<?php
// Seguridad y autorizaciones del módulo Usuarios. PHP 7.2+ / MariaDB.
declare(strict_types=1);
require_once __DIR__.'/lib.php';

function usuarios_listos(PDO $pdo): bool {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'perfil'")->fetchAll();
        $owner = $pdo->query("SHOW COLUMNS FROM registros LIKE 'creado_por'")->fetchAll();
        return $cache = !empty($cols) && !empty($owner);
    } catch (Throwable $e) { return $cache = false; }
}
function permisos_disponibles(): array {
    return [
        'registros_ver'=>'Ver sus propios registros',
        'registros_crear'=>'Agregar registros',
        'registros_editar'=>'Editar sus propios registros',
        'registros_eliminar'=>'Eliminar sus propios registros',
        'exportar'=>'Exportar únicamente sus registros a Excel',
    ];
}
function permisos_vendedor(): array { return ['registros_ver','registros_crear']; }
function current_user(PDO $pdo): ?array {
    static $checked = false;
    static $cached = null;
    if ($checked) return $cached;
    $checked = true;
    if (!logged_in()) return null;
    if (!usuarios_listos($pdo)) {
        $firstId = (int)$pdo->query('SELECT MIN(id) FROM usuarios')->fetchColumn();
        if ((int)$_SESSION['uid'] === $firstId) {
            $cached = ['id'=>$firstId,'usuario'=>(string)($_SESSION['username']??''),'nombre'=>(string)($_SESSION['username']??''),'correo'=>'','perfil'=>'ADMINISTRADOR','activo'=>1,'permisos'=>'[]','auth_version'=>1];
        }
        return $cached;
    }
    $extra = (bool)$pdo->query("SHOW COLUMNS FROM usuarios LIKE 'debe_cambiar_clave'")->fetch();
    $vendedorCol = (bool)$pdo->query("SHOW COLUMNS FROM usuarios LIKE 'vendedor_catalogo_id'")->fetch();
    $st = $pdo->prepare('SELECT id,usuario,nombre,correo,perfil,activo,permisos,auth_version'.($extra?',debe_cambiar_clave':'').($vendedorCol?',vendedor_catalogo_id':'').' FROM usuarios WHERE id=:id');
    $st->execute([':id'=>(int)$_SESSION['uid']]);
    $user = $st->fetch();
    if (!$user || (int)$user['activo'] !== 1) {
        $_SESSION = [];
        session_regenerate_id(true);
        return null;
    }
    // Anula sesiones antiguas tras cambios de perfil, contraseña o desactivación.
    $version=(int)$user['auth_version'];
    if (isset($_SESSION['auth_version']) && (int)$_SESSION['auth_version'] !== $version) {
        $_SESSION = [];
        session_regenerate_id(true);
        return null;
    }
    $_SESSION['auth_version']=$version;
    // Contraseñas temporales: impedir cualquier acceso a datos hasta renovarlas.
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $isLogout = $script === 'index.php' && (string)($_POST['action'] ?? '') === 'logout';
    if ($extra && (int)$user['debe_cambiar_clave'] === 1 && $script !== 'cambiar_clave.php' && !$isLogout) {
        go('cambiar_clave.php');
    }
    return $cached=$user;
}
function es_admin(PDO $pdo): bool {
    $user=current_user($pdo);
    return $user !== null && $user['perfil']==='ADMINISTRADOR';
}
function permitido(PDO $pdo, string $permiso): bool {
    $user=current_user($pdo);
    if (!$user) return false;
    if ($user['perfil']==='ADMINISTRADOR') return true;
    if (in_array($permiso,['usuarios','catalogos','importar','comisiones'],true)) return false;
    $set=json_decode((string)$user['permisos'],true);
    return is_array($set) && in_array($permiso,$set,true);
}
function exigir_permiso(PDO $pdo, string $permiso): void {
    if (!current_user($pdo)) go('index.php?view=login');
    if (!permitido($pdo,$permiso)) { http_response_code(403); exit('403 · No tienes permiso para realizar esta acción.'); }
}
function puede_ver_registro(PDO $pdo, int $id): bool {
    if (!permitido($pdo,'registros_ver')) return false;
    if (es_admin($pdo)) return true;
    $st=$pdo->prepare('SELECT 1 FROM registros WHERE id=:id AND creado_por=:uid LIMIT 1');
    $st->execute([':id'=>$id,':uid'=>(int)$_SESSION['uid']]);
    return (bool)$st->fetchColumn();
}
function filtros_registros_usuario(PDO $pdo, array $input): array {
    list($where,$params)=registro_filters($input);
    if (!es_admin($pdo)) {
        $where .= $where === '' ? ' WHERE creado_por=:_mi_uid' : ' AND creado_por=:_mi_uid';
        $params[':_mi_uid']=(string)(int)$_SESSION['uid'];
    }
    return [$where,$params];
}
function lista_vendedores(PDO $pdo): array {
    if (!usuarios_listos($pdo)) return [];
    return $pdo->query("SELECT id, nombre, correo, activo FROM usuarios WHERE perfil='VENDEDOR' ORDER BY activo DESC, nombre ASC")->fetchAll();
}

// Nombre del catálogo asociado a la cuenta. Vinculación persistente por ID, aunque cambie el nombre.
function nombre_vendedor_vinculado(PDO $pdo, array $user): string {
    if (!empty($user['vendedor_catalogo_id'])) {
        $st=$pdo->prepare("SELECT nombre FROM opciones_catalogo WHERE id=:id AND tipo='vendedor' LIMIT 1");
        $st->execute([':id'=>(int)$user['vendedor_catalogo_id']]);
        $nombre=$st->fetchColumn();
        if (is_string($nombre) && $nombre!=='') return $nombre;
    }
    return (string)($user['nombre'] ?: $user['usuario']);
}
