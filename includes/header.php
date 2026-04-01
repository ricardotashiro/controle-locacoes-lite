<?php
$config = require __DIR__ . '/../config.php';
$current = basename($_SERVER['PHP_SELF']);
$currentAction = $_GET['action'] ?? '';
$logoWebPath = 'assets/img/logo.png';
$logoFilePath = __DIR__ . '/../' . $logoWebPath;
$hasLogo = is_file($logoFilePath);
$logoVersion = $hasLogo ? (string)filemtime($logoFilePath) : (string)time();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1777ff">
    <title><?= htmlspecialchars($config['app_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.17/index.global.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=19" rel="stylesheet">
</head>
<body class="app-body">
<div class="sidebar-backdrop" data-sidebar-close></div>
<?php if (is_logged_in()): ?>
<div class="app-shell">
    <aside class="sidebar shadow-sm">
        <div class="sidebar-brand <?= $hasLogo ? 'sidebar-brand-logo-only' : '' ?>">
            <?php if ($hasLogo): ?>
                <img src="<?= htmlspecialchars($logoWebPath) ?>?v=<?= htmlspecialchars($logoVersion) ?>" alt="Logo do sistema" class="sidebar-logo">
                <small class="text-white-50 brand-subtitle-only">Gestão de reservas</small>
            <?php else: ?>
                <div class="brand-badge"><i class="bi bi-buildings-fill"></i></div>
                <div>
                    <div class="brand-title"><?= htmlspecialchars($config['app_name']) ?></div>
                    <small class="text-white-50">Gestão de reservas</small>
                </div>
            <?php endif; ?>
        </div>

        <nav class="sidebar-nav">
            <a class="sidebar-link <?= $current === 'home.php' ? 'active' : '' ?>" href="home.php">
                <i class="bi bi-house-door-fill"></i>
                <span>Home</span>
            </a>
            <a class="sidebar-link <?= $current === 'dashboard.php' ? 'active' : '' ?>" href="dashboard.php">
                <i class="bi bi-calendar3"></i>
                <span>Agenda</span>
            </a>
            <a class="sidebar-link <?= $current === 'apartments.php' ? 'active' : '' ?>" href="apartments.php">
                <i class="bi bi-door-open-fill"></i>
                <span>Apartamentos</span>
            </a>
            <a class="sidebar-link <?= $current === 'bookings.php' && $currentAction === '' ? 'active' : '' ?>" href="bookings.php">
                <i class="bi bi-journal-check"></i>
                <span>Reservas</span>
            </a>
            <a class="sidebar-link <?= $current === 'clients.php' ? 'active' : '' ?>" href="clients.php">
                <i class="bi bi-people-fill"></i>
                <span>Clientes</span>
            </a>
</nav>

        <div class="sidebar-user mt-auto">
            <div class="sidebar-user-info">
                <div class="sidebar-user-avatar"><i class="bi bi-person-fill"></i></div>
                <div class="sidebar-user-text">
                    <div class="small text-white-50 mb-0">Conectado como</div>
                    <div class="fw-semibold"><?= htmlspecialchars(current_user_name()) ?></div>
                    <div class="small text-white-50">@<?= htmlspecialchars(current_username()) ?></div>
                </div>
            </div>
            <a class="btn btn-light btn-sm sidebar-logout" href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Sair</span></a>
        </div>
    </aside>

    <main class="content-area">
        <div class="content-inner">
        <div class="topbar shadow-sm">
            <div class="topbar-main">
                <button class="sidebar-toggle" type="button" aria-label="Abrir menu" data-sidebar-toggle>
                    <i class="bi bi-list"></i>
                </button>
                <div>
                <div class="page-kicker">Controle de reservas</div>
                <h1 class="page-title mb-0">
                    <?php if ($current === 'home.php'): ?>Home<?php elseif ($current === 'dashboard.php'): ?>Agenda de ocupação<?php elseif ($current === 'apartments.php'): ?>Cadastro de apartamentos<?php elseif ($current === 'clients.php'): ?>Cadastro de clientes<?php elseif ($current === 'bookings.php' && $currentAction === 'new'): ?>Nova reserva<?php else: ?>Controle de reservas<?php endif; ?>
                </h1>
                </div>
            </div>
        </div>
        <div class="page-content">
<?php else: ?>
<div class="container-fluid py-4">
<?php endif; ?>
