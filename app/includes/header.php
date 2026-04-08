<?php
requireLogin();
$flash = getFlash();
$currentPage = basename(dirname($_SERVER['PHP_SELF']));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'Painel') ?> - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="<?= APP_URL ?>/assets/css/admin.css" rel="stylesheet">
</head>
<body>

<div class="d-flex" id="wrapper">
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h5><i class="fas fa-cash-register me-2"></i>PDV Pro</h5>
            <small class="text-muted">Painel Admin</small>
        </div>

        <nav class="sidebar-nav">
            <a href="<?= APP_URL ?>/dashboard/" class="nav-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                <i class="fas fa-chart-line"></i> Dashboard
            </a>
            <a href="<?= APP_URL ?>/clientes/" class="nav-link <?= $currentPage === 'clientes' ? 'active' : '' ?>">
                <i class="fas fa-building"></i> Clientes
            </a>
            <a href="<?= APP_URL ?>/licencas/" class="nav-link <?= $currentPage === 'licencas' ? 'active' : '' ?>">
                <i class="fas fa-key"></i> Licencas
            </a>
            <a href="<?= APP_URL ?>/planos/" class="nav-link <?= $currentPage === 'planos' ? 'active' : '' ?>">
                <i class="fas fa-tags"></i> Planos
            </a>
            <a href="<?= APP_URL ?>/faturamento/" class="nav-link <?= $currentPage === 'faturamento' ? 'active' : '' ?>">
                <i class="fas fa-file-invoice-dollar"></i> Faturamento
            </a>
            <a href="<?= APP_URL ?>/configuracao/" class="nav-link <?= $currentPage === 'configuracao' ? 'active' : '' ?>">
                <i class="fas fa-cog"></i> Configuracoes
            </a>

            <hr class="my-3">

            <a href="<?= APP_URL ?>/auth/logout.php" class="nav-link text-danger">
                <i class="fas fa-sign-out-alt"></i> Sair
            </a>
        </nav>
    </div>

    <!-- Content -->
    <div class="content-wrapper" id="content">
        <!-- Topbar -->
        <div class="topbar">
            <button class="btn btn-sm btn-outline-secondary d-md-none" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>
            <div class="ms-auto d-flex align-items-center">
                <span class="text-muted me-3">
                    <i class="fas fa-user me-1"></i><?= e(currentUser()['nome'] ?? 'Admin') ?>
                </span>
            </div>
        </div>

        <!-- Main -->
        <div class="main-content">
            <?php if ($flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
                    <?= e($flash['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
