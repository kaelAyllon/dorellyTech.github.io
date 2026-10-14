<?php
/** Componentes compartidos entre Dashboard, Usuarios y Comisiones. PHP 7.2+. */
declare(strict_types=1);
require_once __DIR__.'/usuarios_lib.php';

function ui_sidebar(PDO $pdo, string $section = 'registros'): void {
    $admin = es_admin($pdo);
    $canView = permitido($pdo, 'registros_ver');
    $canNew = permitido($pdo, 'registros_crear');
    $canImport = permitido($pdo, 'importar');
    $canExport = permitido($pdo, 'exportar');
    $canComisiones = permitido($pdo, 'comisiones');
    $canRoles = permitido($pdo, 'catalogos');
    $dashboardOpen = in_array($section, ['registros', 'nuevo', 'editar', 'importar', 'exportar'], true);
    $rolesOpen = in_array($section, ['areas', 'finanzas'], true);
    ?>
    <aside class="app-sidebar" aria-label="Navegación del sistema">
        <a class="app-brand" href="index.php"><span class="app-brand-symbol">✚</span><span>REGISTRO <b>SOPORTE</b></span></a>
        <div class="app-sidebar-label">PANEL DE CONTROL</div>
        <nav class="app-menu">
            <?php if ($canView): ?>
            <details class="app-menu-group" <?= $dashboardOpen ? 'open' : '' ?>>
                <summary class="app-menu-parent <?= $dashboardOpen ? 'active' : '' ?>"><span>▦</span> <?= $admin ? 'Dashboard y registros' : 'Mis ventas y registros' ?> <span class="app-menu-chevron">⌄</span></summary>
                <div class="app-submenu">
                    <a class="<?= $section === 'registros' ? 'active' : '' ?>" href="index.php"><?= $admin ? 'Dashboard' : 'Mis ventas' ?></a>
                    <?php if ($canNew): ?><a class="<?= $section === 'nuevo' ? 'active' : '' ?>" href="index.php?view=nuevo">Nuevo registro</a><?php endif; ?>
                    <?php if ($canImport): ?><a class="<?= $section === 'importar' ? 'active' : '' ?>" href="index.php?view=importar">Importar desde Excel</a><?php endif; ?>
                    <?php if ($canExport): ?><a href="exportar_excel.php">Exportar Excel</a><?php endif; ?>
                </div>
            </details>
            <?php endif; ?>
            <?php if ($canComisiones): ?><a class="app-menu-link <?= $section === 'comisiones' ? 'active' : '' ?>" href="comisiones.php"><span>▤</span> Comisiones</a><?php endif; ?>
            <?php if ($admin): ?><a class="app-menu-link <?= $section === 'usuarios' ? 'active' : '' ?>" href="usuarios.php"><span>♙</span> Usuarios y permisos</a><?php endif; ?>
            <?php if ($canRoles): ?>
            <details class="app-menu-group" <?= $rolesOpen ? 'open' : '' ?>>
                <summary class="app-menu-parent <?= $rolesOpen ? 'active' : '' ?>"><span>◇</span> Roles <span class="app-menu-chevron">⌄</span></summary>
                <div class="app-submenu">
                    <a class="<?= $section === 'areas' ? 'active' : '' ?>" href="index.php?view=areas">Áreas</a>
                    <a class="<?= $section === 'finanzas' ? 'active' : '' ?>" href="index.php?view=finanzas">Finanzas</a>
                </div>
            </details>
            <?php endif; ?>
        </nav>
        <div class="app-sidebar-footer"><span aria-hidden="true" class="app-online-dot"></span> Base de datos conectada<small>MariaDB · Acceso protegido</small></div>
    </aside>
    <?php
}

function ui_topbar(PDO $pdo, string $title, string $subtitle = ''): void {
    $user = current_user($pdo);
    $name = (string)($user['nombre'] ?? $_SESSION['username'] ?? 'Usuario');
    $role = (string)($user['perfil'] ?? 'USUARIO');
    $week = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
    $months = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    $dateLabel = ucfirst($week[(int)date('w')]).', '.date('j').' de '.$months[(int)date('n') - 1].' de '.date('Y');
    ?>
    <header class="app-topbar">
        <div class="app-topbar-title"><span class="app-topbar-eyebrow">✦ GESTIÓN DE CLIENTES</span><h1><?= h($title) ?></h1><?php if ($subtitle !== ''): ?><p><?= h($subtitle) ?></p><?php endif; ?></div>
        <div class="app-topbar-account">
            <span class="app-topbar-date">▦ <?= h($dateLabel) ?></span>
            <span class="app-topbar-profile"><span class="app-profile-name">● <?= h($name) ?></span><span class="app-profile-role"><?= h($role) ?></span></span>
            <form class="app-logout-form" method="post" action="index.php"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="logout"><button type="submit">Salir</button></form>
        </div>
    </header>
    <?php
}
