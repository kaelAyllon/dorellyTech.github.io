<?php
// Cambio obligatorio de contraseña en el primer acceso de las cuentas creadas en lote.
declare(strict_types=1);
require __DIR__.'/usuarios_lib.php';
ensure_login();
$pdo=db();
$user=current_user($pdo);
if (!$user) go('index.php?view=login');
$errores=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf();
    $vieja=(string)($_POST['clave_actual']??'');
    $nueva=(string)($_POST['clave_nueva']??'');
    $confirmar=(string)($_POST['confirmar_clave']??'');
    $q=$pdo->prepare('SELECT clave_hash FROM usuarios WHERE id=:id');
    $q->execute([':id'=>(int)$user['id']]);
    $hash=(string)$q->fetchColumn();
    if (!password_verify($vieja,$hash)) $errores[]='La contraseña actual es incorrecta.';
    if (strlen($nueva)<10 || strlen($nueva)>200) $errores[]='La contraseña nueva debe tener entre 10 y 200 caracteres.';
    if (!preg_match('/[a-zA-Z]/',$nueva) || !preg_match('/[0-9]/',$nueva)) $errores[]='La nueva contraseña debe incluir letras y números.';
    if ($nueva!==$confirmar) $errores[]='Las contraseñas nuevas no coinciden.';
    if ($nueva===$vieja || $nueva==='123456') $errores[]='Elige una contraseña distinta de la temporal.';
    if (!$errores) {
        $q=$pdo->prepare('UPDATE usuarios SET clave_hash=:hash,debe_cambiar_clave=0,auth_version=auth_version+1 WHERE id=:id');
        $q->execute([':hash'=>password_hash($nueva,PASSWORD_DEFAULT),':id'=>(int)$user['id']]);
        $_SESSION['auth_version']=(int)$user['auth_version']+1;
        session_regenerate_id(true);
        flash('success','Contraseña personal guardada. Ya puedes utilizar el sistema.');
        go('index.php');
    }
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Actualizar contraseña · Registro Soporte</title><link rel="stylesheet" href="assets/cambiar_clave.css"></head><body><main class="password-card"><div class="logo">✚ &nbsp; DORELLYTECH</div><h1>Protege tu cuenta</h1><p>Hola, <?=h((string)$user['nombre'])?>. Tu contraseña inicial es temporal. Debes crear una contraseña personal para continuar.</p><?php foreach($errores as $e):?><div class="error"><?=h($e)?></div><?php endforeach;?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><label>Contraseña temporal <input type="password" name="clave_actual" autocomplete="current-password" required></label><label>Nueva contraseña <input type="password" name="clave_nueva" minlength="10" maxlength="200" autocomplete="new-password" required></label><label>Confirmar contraseña <input type="password" name="confirmar_clave" minlength="10" maxlength="200" autocomplete="new-password" required></label><small>Mínimo 10 caracteres, incluyendo letras y números. No uses la clave temporal.</small><button type="submit">Guardar nueva contraseña</button></form><form method="post" action="index.php"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="logout"><button type="submit" class="secondary">Salir</button></form></main></body></html>
