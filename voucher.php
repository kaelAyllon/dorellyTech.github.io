<?php
declare(strict_types=1);
require __DIR__.'/usuarios_lib.php';
ensure_login();
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!$id) { http_response_code(404); exit('Registro no encontrado'); }
if (!puede_ver_registro(db(),(int)$id)) {http_response_code(403);exit('No tienes acceso a ese voucher.');}
$st=db()->prepare('SELECT imagen_voucher FROM registros WHERE id=:id');
$st->execute([':id'=>$id]);
$voucher=$st->fetchColumn();
if (!$voucher || !preg_match('~^uploads/[a-f0-9]{32}\.(jpg|png|webp)$~D', $voucher)) { http_response_code(404); exit('Imagen no encontrada'); }
$path=__DIR__.'/'.$voucher;
if (!is_file($path)) { http_response_code(404); exit('Imagen no encontrada'); }
$ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));
$mimes=['jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
header('Content-Type: '.$mimes[$ext]);
header('Content-Disposition: inline; filename="voucher-'.$id.'.'.$ext.'"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($path);
