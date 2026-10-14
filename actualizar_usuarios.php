<?php
declare(strict_types=1);
require __DIR__.'/usuarios_lib.php';
ensure_login();
$pdo=db();
// Primer usuario siempre puede ejecutar el alta inicial; después, solo administradores.
$first=(int)$pdo->query('SELECT MIN(id) FROM usuarios')->fetchColumn();
if ((int)$_SESSION['uid']!==$first && !es_admin($pdo)) {http_response_code(403);exit('Acceso exclusivo del administrador.');}
$messages=[];$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf();
    if (($_POST['action']??'')==='actualizar_usuarios') {
        if (!$pdo->query("SHOW COLUMNS FROM usuarios LIKE 'nombre'")->fetch()) {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN nombre VARCHAR(120) NOT NULL DEFAULT ''");
        }
        $cols=[
            'correo'=>"VARCHAR(190) NULL DEFAULT NULL",
            'perfil'=>"VARCHAR(20) NOT NULL DEFAULT 'VENDEDOR'",
            'activo'=>"TINYINT(1) NOT NULL DEFAULT 1",
            'permisos'=>"TEXT NULL",
            'auth_version'=>"INT UNSIGNED NOT NULL DEFAULT 1"
        ];
        foreach($cols as $c=>$type) {
            if (!$pdo->query("SHOW COLUMNS FROM usuarios LIKE ".$pdo->quote($c))->fetch()) {
                $pdo->exec("ALTER TABLE usuarios ADD COLUMN `".$c."` ".$type);
            }
        }
        if (!$pdo->query("SHOW INDEX FROM usuarios WHERE Key_name='uq_usuarios_correo'")->fetch()) {
            $pdo->exec('ALTER TABLE usuarios ADD UNIQUE KEY uq_usuarios_correo (correo)');
        }
        if (!$pdo->query("SHOW COLUMNS FROM registros LIKE 'creado_por'")->fetch()) {
            $pdo->exec('ALTER TABLE registros ADD COLUMN creado_por INT UNSIGNED NULL DEFAULT NULL');
        }
        if (!$pdo->query("SHOW INDEX FROM registros WHERE Key_name='idx_registros_propietario'")->fetch()) {
            $pdo->exec('ALTER TABLE registros ADD INDEX idx_registros_propietario (creado_por,id)');
        }
        // Mantener nombre y usuario existentes. No cambia las contraseñas.
        $pdo->exec("UPDATE usuarios SET nombre=usuario WHERE nombre='' OR nombre IS NULL");
        $st=$pdo->prepare("UPDATE usuarios SET perfil='ADMINISTRADOR', activo=1, permisos='[]' WHERE id=:id");
        $st->execute([':id'=>$first]);
        $def=json_encode(permisos_vendedor());
        $st=$pdo->prepare("UPDATE usuarios SET perfil='VENDEDOR', permisos=:p WHERE id<>:id AND (permisos IS NULL OR permisos='')");
        $st->execute([':p'=>$def,':id'=>$first]);
        flash('success','Usuarios activados. Los registros históricos se conservaron. Puedes asignarlos desde Editar registro.');
        go('actualizar_usuarios.php');
    }
}
$ready=usuarios_listos($pdo);
$messages=array_merge($messages,array_map(function($f){return $f[1];},get_flashes()));
header('Content-Type: text/html; charset=utf-8');
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Activar usuarios</title><link rel="stylesheet" href="assets/usuarios.css"></head><body class="users-page"><main class="users-install"><h1>Activar módulo Usuarios y permisos</h1><p>Este paso agrega los perfiles ADMINISTRADOR y VENDEDOR, permisos individuales y propietario de cada registro.</p><?php foreach($messages as $m):?><div class="users-ok"><?=h($m)?></div><?php endforeach;?><?php if($ready):?><p class="users-ok">Las columnas del módulo ya están instaladas.</p><a class="users-button" href="usuarios.php">Abrir administración de usuarios →</a><?php else:?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="actualizar_usuarios"><button class="users-button">Activar usuarios y permisos</button></form><?php endif;?><p><a href="index.php">← Volver al sistema</a></p><p class="users-hint">Haz una copia SQL de la base de datos antes de iniciar. Solo el usuario administrador original puede realizar la actualización inicial.</p></main></body></html>
