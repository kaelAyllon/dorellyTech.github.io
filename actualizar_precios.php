<?php
declare(strict_types=1);
require __DIR__.'/lib.php';
require __DIR__.'/catalogos_lib.php';
ensure_login();
$pdo = db();
if (!catalogo_es_admin($pdo)) { http_response_code(403); exit('Solo el administrador puede actualizar precios.'); }
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Abre esta página desde la computadora con XAMPP: localhost.');
}
$error = '';
$yaInstalado = catalogo_tiene_precios($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        if (!catalogo_disponible($pdo)) throw new RuntimeException('Primero ejecuta actualizar_catalogos.php.');
        if (!catalogo_tiene_precios($pdo)) {
            $pdo->exec('ALTER TABLE opciones_catalogo ADD COLUMN precio DECIMAL(12,2) NULL DEFAULT NULL AFTER activo');
        }
        catalogo_seed($pdo);
        flash('success', 'Precios antiguos activados: ORDENA S/ 59.90, CRECE S/ 89.90 y AVANZA S/ 159.90. Los registros históricos no cambiaron.');
        go('index.php?view=finanzas#tipo-plan');
    } catch (Throwable $e) {
        error_log('Error al activar precios: '.$e->getMessage());
        $error = 'No se pudo actualizar: revisa si el módulo Roles ya está instalado y si MariaDB tiene permisos de ALTER TABLE.';
    }
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Activar montos antiguos</title><link rel="stylesheet" href="assets/app.css"></head>
<body class="auth-page"><main class="auth-card">
<div class="brand large"><span class="brand-symbol">✚</span><span>REGISTRO <b>SOPORTE</b></span></div>
<h1>Montos de planes antiguos</h1>
<p class="muted">Se añadirá la columna <b>precio</b> en opciones_catalogo, y solo estos importes iniciales: ORDENA ANTIGUO S/ 59.90, CRECE ANTIGUO S/ 89.90 y AVANZA ANTIGUO S/ 159.90.</p>
<?php if ($error): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
<?php if ($yaInstalado): ?><div class="notice success">La columna de precios ya está instalada. Puedes volver a ejecutar para agregar tarifas antiguas faltantes sin modificar precios personalizados.</div><?php endif; ?>
<p class="tiny muted">Antes de actualizar, exporta una copia SQL de registro_soporte en phpMyAdmin. No se actualizan los montos de los clientes existentes.</p>
<form method="post" class="stack"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><button class="btn btn-primary full" type="submit">Activar montos antiguos →</button></form>
<p class="tiny"><a href="index.php?view=finanzas">← Volver a Finanzas</a></p>
</main></body></html>
