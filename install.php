<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/catalogos_lib.php';

// El alta inicial del administrador solo se puede realizar en el mismo equipo servidor.
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('La instalación inicial debe abrirse en el mismo equipo donde está XAMPP: http://localhost/registro-soporte/install.php');
}
if (installed()) go('index.php');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $username = trim((string)($_POST['usuario'] ?? ''));
    $password = (string)($_POST['clave'] ?? '');
    $confirm = (string)($_POST['confirmar'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9_.-]{3,40}$/D', $username)) {
        $error = 'El usuario debe tener entre 3 y 40 caracteres (letras, números, punto, guion).';
    } elseif (strlen($password) < 10) {
        $error = 'La contraseña debe tener al menos 10 caracteres.';
    } elseif ($password !== $confirm) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        try {
            $c = cfg();
            $server = db(false);
            $name = $c['db_name'];
            if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name)) throw new RuntimeException('Nombre de base de datos no válido en config.php');
            $server->exec('CREATE DATABASE IF NOT EXISTS `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo = db();
            $schema = file_get_contents(__DIR__ . '/schema.sql');
            foreach (explode(';', $schema) as $statement) {
                if (trim($statement) !== '') $pdo->exec($statement);
            }
            catalogo_seed($pdo);
            if ((int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() > 0) {
                go('index.php');
            }
            $st = $pdo->prepare('INSERT INTO usuarios (usuario, clave_hash) VALUES (:u, :p)');
            $st->execute([':u'=>$username, ':p'=>password_hash($password, PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$pdo->lastInsertId();
            $_SESSION['username'] = $username;
            $_SESSION['last_seen'] = time();
            flash('success', 'Base de datos creada. ¡Tu sistema está listo!');
            go('index.php');
        } catch (Throwable $e) {
            error_log('Error instalación Registro Soporte: ' . $e->getMessage());
            $error = 'No se pudo conectar a MySQL. Comprueba que Apache y MySQL estén iniciados en XAMPP, y revisa config.php. Detalle: ' . h($e->getMessage());
        }
    }
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalar · Registro Soporte</title><link rel="stylesheet" href="assets/app.css"></head>
<body class="auth-page"><main class="auth-card">
    <div class="brand large"><span class="brand-symbol">✚</span><span>REGISTRO <b>SOPORTE</b></span></div>
    <h1>Configurar tu sistema</h1>
    <p class="muted">Instalación inicial de la base de datos MySQL. Crea una cuenta de administrador para proteger los registros.</p>
    <?php if ($error): ?><div class="notice error"><?= $error ?></div><?php endif; ?>
    <form method="post" class="stack" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
        <label>Usuario administrador<input name="usuario" required minlength="3" maxlength="40" placeholder="admin" value="<?= h((string)($_POST['usuario'] ?? '')) ?>"></label>
        <label>Contraseña (mínimo 10 caracteres)<input name="clave" type="password" required minlength="10" autocomplete="new-password"></label>
        <label>Repite la contraseña<input name="confirmar" type="password" required minlength="10" autocomplete="new-password"></label>
        <button type="submit" class="btn btn-primary full">Instalar y entrar →</button>
    </form>
    <p class="tiny muted">Solo se permite instalar desde localhost. No se crea ninguna contraseña predeterminada.</p>
</main></body></html>
