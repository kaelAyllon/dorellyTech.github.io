<?php
// Crea/vincula una cuenta por opción del catálogo Equipo de ventas, sin duplicar usuarios.
// PHP >=7.2, MySQL/MariaDB.
declare(strict_types=1);
require __DIR__.'/usuarios_lib.php';
require_once __DIR__.'/catalogos_lib.php';
ensure_login();
$pdo=db();
exigir_permiso($pdo,'usuarios');
if (!catalogo_disponible($pdo) || !usuarios_listos($pdo)) {
    http_response_code(409);
    exit('Activa primero Usuarios y permisos y Roles / Áreas.');
}
$cuentas = [
    'ADRIANA'=>'adriana',
    'BRISAIDA'=>'brisaida',
    'CLARA'=>'clara',
    'MAFER'=>'mafer',
    'MARCIA'=>'marcia',
    'WENDY'=>'wendy',
];
$dominio='dorrellytech.com'; // Tal como solicitaste: adriana@dorrellytech.com.
$mensajes=[];
$errores=[];
$col=$pdo->query("SHOW COLUMNS FROM usuarios LIKE 'vendedor_catalogo_id'")->fetch();
$ready=(bool)$col;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf();
    if (!$ready) {
        // Se ejecuta antes de cualquier consulta que requiera la columna.
        $pdo->exec('ALTER TABLE usuarios ADD COLUMN vendedor_catalogo_id INT UNSIGNED NULL DEFAULT NULL');
        $ready = true;
    }
    // Las actualizaciones parcialmente ejecutadas también deben poder repetirse.
    if (!$pdo->query("SHOW INDEX FROM usuarios WHERE Key_name='uq_usuarios_vendedor_catalogo_id'")->fetch()) {
        $pdo->exec('ALTER TABLE usuarios ADD UNIQUE KEY uq_usuarios_vendedor_catalogo_id (vendedor_catalogo_id)');
    }
    if (!$pdo->query("SHOW COLUMNS FROM usuarios LIKE 'debe_cambiar_clave'")->fetch()) {
        $pdo->exec('ALTER TABLE usuarios ADD COLUMN debe_cambiar_clave TINYINT(1) NOT NULL DEFAULT 0');
    }
    $asignar=isset($_POST['asignar_historial']) && $_POST['asignar_historial']==='1';
    foreach ($cuentas as $nombre=>$usuario) {
        try {
            $st=$pdo->prepare("SELECT id,nombre,activo FROM opciones_catalogo WHERE tipo='vendedor' AND UPPER(TRIM(nombre))=:n ORDER BY activo DESC,id ASC LIMIT 1");
            $st->execute([':n'=>$nombre]);
            $catalogo=$st->fetch();
            if (!$catalogo || (int)$catalogo['activo']!==1) {
                $errores[]=$nombre.': no existe una opción activa en Roles / Áreas → Equipo de ventas.';
                continue;
            }
            $catalogId=(int)$catalogo['id'];
            $correo=$usuario.'@'.$dominio;
            $pdo->beginTransaction();
            // El token de catálogo nunca puede quedar vinculado a más de una cuenta.
            $st=$pdo->prepare('SELECT id,usuario,correo,perfil,vendedor_catalogo_id FROM usuarios WHERE vendedor_catalogo_id=:c OR LOWER(usuario)=:u OR LOWER(correo)=:mail FOR UPDATE');
            $st->execute([':c'=>$catalogId,':u'=>$usuario,':mail'=>$correo]);
            $coincidencias=$st->fetchAll();
            if (count($coincidencias)>1) {
                throw new RuntimeException('Hay varias cuentas con nombres/correos o relaciones en conflicto; revísalas en Usuarios.');
            }
            if ($coincidencias) {
                $existente=$coincidencias[0];
                if ($existente['perfil']!=='VENDEDOR') throw new RuntimeException('El nombre o correo ya corresponde a un administrador; no se modificó.');
                if ((int)($existente['vendedor_catalogo_id']??0)>0 && (int)$existente['vendedor_catalogo_id']!==$catalogId) {
                    throw new RuntimeException('La cuenta existente está vinculada con otra opción. No se cambió.');
                }
                $id=(int)$existente['id'];
                if (!$existente['vendedor_catalogo_id']) {
                    $link=$pdo->prepare('UPDATE usuarios SET vendedor_catalogo_id=:cat WHERE id=:id');
                    $link->execute([':cat'=>$catalogId,':id'=>$id]);
                }
                $status='Vinculada cuenta existente (se conservaron correo, clave y permisos)';
                $correo=(string)$existente['correo'];
            } else {
                $alta=$pdo->prepare('INSERT INTO usuarios (usuario,nombre,correo,clave_hash,perfil,activo,permisos,auth_version,vendedor_catalogo_id,debe_cambiar_clave) VALUES (:u,:n,:c,:h,\'VENDEDOR\',1,:p,1,:cat,1)');
                $alta->execute([
                    ':u'=>$usuario,':n'=>(string)$catalogo['nombre'],':c'=>$correo,
                    ':h'=>password_hash('123456',PASSWORD_DEFAULT),
                    ':p'=>json_encode(permisos_vendedor()),':cat'=>$catalogId,
                ]);
                $id=(int)$pdo->lastInsertId();
                $status='Cuenta creada (clave inicial temporal; se exigirá cambiarla)';
            }
            $assigned=0;
            if ($asignar) {
                // No tocar registros ya asociados a otros vendedores.
                // Se permite reasignar ventas creadas anteriormente por el administrador.
                $hist=$pdo->prepare("UPDATE registros SET creado_por=:uid WHERE UPPER(TRIM(vendedor))=:nombre AND (creado_por IS NULL OR creado_por IN (SELECT id FROM usuarios WHERE perfil='ADMINISTRADOR'))");
                $hist->execute([':uid'=>$id,':nombre'=>$nombre]);
                $assigned=$hist->rowCount();
            }
            $pdo->commit();
            $mensajes[]=$nombre.' → '.$usuario.' ('.$correo.'): '.$status.'. Ventas anteriores asignadas: '.$assigned.'.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Sincronizar vendedoras '.$nombre.': '.$e->getMessage());
            $errores[]=$nombre.': '.$e->getMessage();
        }
    }
}
$cat=$pdo->query("SELECT id,nombre,activo FROM opciones_catalogo WHERE tipo='vendedor' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$map=[];
foreach($cat as $c) $map[upper_es(trim((string)$c['nombre']))]=$c;
$rows=[];
foreach($cuentas as $n=>$u) {
    // En GET inicial aún puede faltar la columna; mostrar la página sin provocar SQLSTATE[42S22].
    $catalogField = $ready ? 'vendedor_catalogo_id' : 'NULL AS vendedor_catalogo_id';
    $q=$pdo->prepare('SELECT id,perfil,correo,'.$catalogField.',activo FROM usuarios WHERE LOWER(usuario)=:u OR LOWER(correo)=:c LIMIT 1');
    $email=$u.'@'.$dominio;
    $q->execute([':u'=>$u,':c'=>$email]);
    $rows[]=['nombre'=>$n,'usuario'=>$u,'correo'=>$email,'opcion'=>$map[$n]??null,'existente'=>$q->fetch()?:null];
}
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Vincular equipo de ventas</title><link rel="stylesheet" href="assets/usuarios.css"><link rel="stylesheet" href="assets/vendedoras.css"></head><body class="users-page"><main class="vendedoras-container">
<div class="vendedoras-head"><div><small>ROLES → ÁREAS → EQUIPO DE VENTAS</small><h1>Crear y vincular cuentas de vendedoras</h1><p>Esta acción crea hasta seis cuentas y las vincula por ID a las opciones de Equipo de ventas, sin duplicar usuarios ni borrar clientes.</p></div><a href="usuarios.php" class="users-cancel">← Volver a Usuarios</a></div>
<?php foreach($mensajes as $m):?><div class="users-ok"><?=h($m)?></div><?php endforeach;?>
<?php foreach($errores as $e):?><div class="users-error"><?=h($e)?></div><?php endforeach;?>
<div class="users-card"><div class="users-card-title"><h2>Vendedoras a sincronizar</h2><span>6 cuentas</span></div><div class="users-scroll"><table><thead><tr><th>Equipo de ventas</th><th>Usuario</th><th>Correo</th><th>Perfil</th><th>Estado</th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><b><?=h($r['nombre'])?></b></td><td><?=h($r['usuario'])?></td><td><?=h($r['correo'])?></td><td>VENDEDOR</td><td><?=!$r['opcion']?'Falta opción':(!(int)$r['opcion']['activo']?'Opción inactiva':($r['existente']?'Cuenta existente':'Pendiente de crear'))?></td></tr><?php endforeach;?>
</tbody></table></div></div>
<section class="users-card vendedoras-action"><h2>Confirmar sincronización</h2>
<?php if (!$ready): ?><div class="users-hint">La columna de vinculación todavía no existe. Se creará de forma segura al pulsar Crear / vincular vendedoras.</div><?php endif; ?><p>Las cuentas nuevas tendrán la contraseña inicial temporal <b>123456</b>. <b>En el primer inicio de sesión deberán cambiarla por una contraseña personal de 10 o más caracteres</b>; hasta entonces no podrán abrir los registros.</p>
<p>Si una cuenta ya existe, solo se vinculará si no hay conflicto; <b>no se sobrescribe la contraseña existente</b>.</p>
<form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><label class="vendedoras-check"><input type="checkbox" name="asignar_historial" value="1" checked><span>Asignar también ventas históricas cuyo <b>N. VENDEDOR</b> coincide con estas vendedoras, solo si están sin propietario o pertenecen a un administrador. No cambiar ventas asignadas a otro vendedor.</span></label><button class="users-button" type="submit">Crear / vincular vendedoras</button></form>
<p class="users-hint">Ejecuta esta operación una sola vez. Si la repites no volverá a crear cuentas ni reiniciar contraseñas. La sincronización requiere el catálogo activo en MariaDB.</p></section>
</main></body></html>
