<?php
// Catálogos de selección. Compatible con PHP 7.2+ y MariaDB/MySQL.
declare(strict_types=1);

function catalogo_definiciones(): array {
    return [
        'soporte' => ['titulo'=>'Responsables de soporte', 'campo'=>'N. SOPORTE', 'grupo'=>'areas'],
        'vendedor' => ['titulo'=>'Equipo de ventas', 'campo'=>'N. VENDEDOR', 'grupo'=>'areas'],
        'proyecto' => ['titulo'=>'Proyectos', 'campo'=>'PROYECTO', 'grupo'=>'finanzas'],
        'plan' => ['titulo'=>'Planes comerciales', 'campo'=>'PLAN', 'grupo'=>'finanzas'],
    ];
}
function catalogo_iniciales(): array {
    return [
        'soporte' => ['KELMER', 'JEAN PIERE', 'CRISTIAN', 'JHONATAN'],
        'vendedor' => ['MAFER', 'BRISAIDA', 'WENDY', 'ADRIANA', 'MARCIA', 'CLARA'],
        'proyecto' => ['BRAINPOS', 'BRAIN TECH', 'MI PSE', 'MISIRE'],
        'plan' => ['PRO', 'ORDENA', 'HOTEL LITE', 'CRECE', 'MASTER', 'PLAN ORDENA (ANTIGUO)', 'PLAN CRECE (ANTIGUO)', 'PLAN AVANZA (ANTIGUO)'],
    ];
}
// Importes publicados para planes antiguos. No se agregan importes de planes nuevos.
function catalogo_precios_antiguos(): array {
    return [
        'PLAN ORDENA (ANTIGUO)' => '59.90',
        'PLAN CRECE (ANTIGUO)' => '89.90',
        'PLAN AVANZA (ANTIGUO)' => '159.90',
    ];
}
function catalogo_tiene_precios(PDO $pdo): bool {
    try {
        if (!catalogo_disponible($pdo)) return false;
        $col = $pdo->query("SHOW COLUMNS FROM opciones_catalogo LIKE 'precio'");
        return (bool)$col->fetch();
    } catch (Throwable $e) { return false; }
}
function catalogo_precios_activos(PDO $pdo): array {
    if (!catalogo_tiene_precios($pdo)) return [];
    $st = $pdo->query("SELECT nombre, precio FROM opciones_catalogo WHERE tipo='plan' AND activo=1 AND precio IS NOT NULL");
    $result = [];
    while ($row = $st->fetch()) $result[(string)$row['nombre']] = number_format((float)$row['precio'], 2, '.', '');
    return $result;
}
function catalogo_disponible(PDO $pdo): bool {
    try {
        // No hace cambios en la BD, por lo que es seguro llamarla en el listado.
        $st = $pdo->query("SHOW TABLES LIKE 'opciones_catalogo'");
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}
function catalogo_es_admin(PDO $pdo): bool {
    require_once __DIR__.'/usuarios_lib.php';
    if (usuarios_listos($pdo)) return es_admin($pdo);
    if (!logged_in()) return false;
    // Instalación original: el primer usuario registrado es el administrador.
    $firstId = (int)$pdo->query('SELECT MIN(id) FROM usuarios')->fetchColumn();
    return $firstId > 0 && (int)$_SESSION['uid'] === $firstId;
}
function catalogo_vista(string $tipo): string {
    $defs = catalogo_definiciones();
    return isset($defs[$tipo]) && $defs[$tipo]['grupo'] === 'finanzas' ? 'finanzas' : 'areas';
}
function catalogo_seed(PDO $pdo): void {
    // Ejecutable repetidamente, no revive opciones que el usuario haya desactivado.
    $st = $pdo->prepare('INSERT IGNORE INTO opciones_catalogo (tipo, nombre, activo) VALUES (:tipo, :nombre, 1)');
    foreach (catalogo_iniciales() as $tipo=>$nombres) {
        foreach ($nombres as $nombre) $st->execute([':tipo'=>$tipo, ':nombre'=>$nombre]);
    }
    // Incorpora las opciones ya usadas en registros existentes para no perderlas.
    foreach (array_keys(catalogo_definiciones()) as $tipo) {
        $st2 = $pdo->query("SELECT DISTINCT TRIM(`{$tipo}`) AS nombre FROM registros WHERE TRIM(`{$tipo}`) <> ''");
        while ($row = $st2->fetch()) {
            $nombre = trim((string)$row['nombre']);
            if ($nombre !== '' && text_length($nombre) <= 100) {
                $st->execute([':tipo'=>$tipo, ':nombre'=>$nombre]);
            }
        }
    }
    // Añade montos de los planes antiguos solamente si todavía no tienen precio configurado.
    if (catalogo_tiene_precios($pdo)) {
        $up = $pdo->prepare("UPDATE opciones_catalogo SET precio=:precio WHERE tipo='plan' AND nombre=:nombre AND precio IS NULL");
        foreach (catalogo_precios_antiguos() as $nombre=>$precio) {
            $up->execute([':nombre'=>$nombre, ':precio'=>$precio]);
        }
    }
}
function catalogo_activos(PDO $pdo, string $tipo): array {
    if (!isset(catalogo_definiciones()[$tipo])) return [];
    if (!catalogo_disponible($pdo)) return catalogo_iniciales()[$tipo];
    $st = $pdo->prepare('SELECT nombre FROM opciones_catalogo WHERE tipo=:tipo AND activo=1 ORDER BY nombre ASC');
    $st->execute([':tipo'=>$tipo]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}
function catalogo_todos(PDO $pdo, string $tipo): array {
    if (!isset(catalogo_definiciones()[$tipo]) || !catalogo_disponible($pdo)) return [];
    $columnaPrecio = catalogo_tiene_precios($pdo) ? ', precio' : ', NULL AS precio';
    $st = $pdo->prepare('SELECT id, nombre, activo'.$columnaPrecio.' FROM opciones_catalogo WHERE tipo=:tipo ORDER BY activo DESC, nombre ASC');
    $st->execute([':tipo'=>$tipo]);
    return $st->fetchAll();
}
function catalogo_opcion_select(array $opciones, string $actual): array {
    // Al editar un cliente con un valor antiguo/desactivado, mostrar su valor,
    // pero impedir que quede oculto o sea reemplazado por accidente.
    $existe = false;
    foreach ($opciones as $opcion) {
        if ($opcion === $actual) $existe = true;
    }
    if ($actual !== '' && !$existe) array_unshift($opciones, $actual);
    return $opciones;
}
