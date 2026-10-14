<?php
declare(strict_types=1);
require __DIR__.'/lib.php';
ensure_login();
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('La ampliación de la base de datos debe ejecutarse desde el servidor XAMPP (localhost).');
}
$pdo=db();
$columns = [
    'proyecto' => "VARCHAR(100) NOT NULL DEFAULT ''",
    'plan' => "VARCHAR(100) NOT NULL DEFAULT ''",
    'monto' => 'DECIMAL(12,2) NULL DEFAULT NULL',
    'pago' => "VARCHAR(12) NOT NULL DEFAULT ''",
    'emite' => "VARCHAR(12) NOT NULL DEFAULT ''",
    'observacion' => 'TEXT NULL',
    'serie_comprobante' => "VARCHAR(30) NOT NULL DEFAULT ''",
    'numero_comprobante' => "VARCHAR(40) NOT NULL DEFAULT ''",
    'imagen_voucher' => "VARCHAR(255) NOT NULL DEFAULT ''",
    'informacion_soporte' => 'TEXT NULL',
];
function missing_columns(PDO $pdo, array $columns): array {
    $present=[];
    foreach ($pdo->query('SHOW COLUMNS FROM `registros`')->fetchAll() as $c) $present[$c['Field']] = true;
    return array_diff_key($columns,$present);
}
$message = '';
$error = '';
try {
    $pending=missing_columns($pdo,$columns);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        check_csrf();
        $added=0;
        foreach ($pending as $name=>$definition) {
            // Los nombres/definiciones proceden solo del arreglo estático anterior.
            $pdo->exec('ALTER TABLE `registros` ADD COLUMN `'.$name.'` '.$definition);
            $added++;
        }
        $message = $added ? 'Se agregaron '.$added.' columnas correctamente. No se eliminaron registros existentes.' : 'La base de datos ya estaba actualizada.';
        $pending = missing_columns($pdo,$columns);
    }
} catch (Throwable $e) {
    error_log('Actualización de Registro Soporte: '.$e->getMessage());
    $error = 'No se pudo actualizar. Revisa los permisos MySQL y vuelve a intentar. Detalle: '.$e->getMessage();
    $pending=[];
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Actualizar Registro Soporte</title><link rel="stylesheet" href="assets/app.css"></head><body class="auth-page"><main class="auth-card">
<div class="brand large"><span class="brand-symbol">✚</span><span>REGISTRO <b>SOPORTE</b></span></div>
<h1>Ampliar la base de datos</h1><p class="muted">Añade las 10 columnas de proyectos, pagos, comprobantes y seguimiento a tu MariaDB existente.</p>
<?php if ($message): ?><div class="notice success"><?= h($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
<?php if (!$error && $pending): ?>
<p class="muted">Faltan <?= count($pending) ?> columnas. <b>Antes de continuar, haz una copia de seguridad de la base de datos registro_soporte desde phpMyAdmin → Exportar.</b> Esta operación no elimina los registros.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><button class="btn btn-primary full" type="submit">Agregar <?= count($pending) ?> columnas →</button></form>
<?php elseif (!$error): ?><p class="muted">La estructura actual ya tiene todas las columnas necesarias.</p><a href="index.php" class="btn btn-primary full">Ir a Registro de soporte →</a><?php endif; ?>
</main></body></html>
