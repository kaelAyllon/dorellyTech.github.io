<?php
declare(strict_types=1);
require __DIR__.'/lib.php';
require __DIR__.'/catalogos_lib.php';
ensure_login();
$pdo = db();
if (!catalogo_es_admin($pdo)) {
    http_response_code(403);
    exit('Solo el administrador principal puede habilitar los catálogos.');
}
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)) {
    http_response_code(403);
    exit('Por seguridad, abre esta página desde el PC donde se ejecuta XAMPP: localhost.');
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS opciones_catalogo (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          tipo VARCHAR(30) NOT NULL,
          nombre VARCHAR(100) NOT NULL,
          activo TINYINT(1) NOT NULL DEFAULT 1,
          creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_tipo_nombre (tipo, nombre),
          KEY idx_tipo_activo (tipo, activo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        catalogo_seed($pdo);
        flash('success', 'Módulo Roles activado. Se conservaron los registros y se cargaron las opciones existentes.');
        go('index.php?view=areas');
    } catch (Throwable $e) {
        error_log('actualizar_catalogos: '.$e->getMessage());
        $error = 'No se pudo activar el módulo. Comprueba que MariaDB esté encendida y que tu usuario MySQL tenga permiso de crear tablas.';
    }
}
$yaExiste = catalogo_disponible($pdo);
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Activar Roles | Registro Soporte</title><link rel="stylesheet" href="assets/app.css"></head>
<body class="auth-page"><main class="auth-card">
<div class="brand large"><span class="brand-symbol">✚</span><span>REGISTRO <b>SOPORTE</b></span></div>
<h1>Activar Áreas y Finanzas</h1>
<p class="muted">Se creará una tabla nueva llamada <b>opciones_catalogo</b> y se cargarán los nombres existentes de N. Soporte, N. Vendedor, Proyecto y Plan. <b>No se borrarán clientes.</b></p>
<?php if ($error): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
<?php if ($yaExiste): ?><div class="notice success">La tabla de opciones ya está creada.</div><?php endif; ?>
<p class="tiny muted">Antes de activar, exporta un respaldo SQL de la base de datos <b>registro_soporte</b> desde phpMyAdmin.</p>
<form method="post" class="stack"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><button type="submit" class="btn btn-primary full"><?= $yaExiste ? 'Actualizar opciones existentes' : 'Activar módulo Roles' ?> →</button></form>
<p class="tiny"><a href="index.php">← Regresar al sistema</a></p>
</main></body></html>
