<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
requireLogin();

$pageTitle = 'Planos';

$planos = $pdo->query("
    SELECT p.*,
        (SELECT COUNT(*) FROM clientes WHERE plano_id = p.id) as total_clientes,
        (SELECT COUNT(*) FROM licencas WHERE plano_id = p.id) as total_licencas
    FROM planos p
    ORDER BY p.tipo_produto, p.preco
")->fetchAll();

include APP_PATH . '/includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-tags me-2"></i>Planos</h2>
</div>

<!-- Desktop -->
<h5 class="mb-3"><i class="fas fa-desktop me-2"></i>Desktop</h5>
<div class="row g-3 mb-4">
    <?php foreach ($planos as $p): ?>
        <?php if ($p['tipo_produto'] !== 'desktop') continue; ?>
        <div class="col-md-4">
            <div class="stat-card h-100">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <h5 class="fw-bold mb-0"><?= e($p['nome']) ?></h5>
                        <small class="text-muted"><?= e($p['slug']) ?></small>
                    </div>
                    <span class="badge bg-<?= $p['ativo'] ? 'success' : 'secondary' ?>"><?= $p['ativo'] ? 'Ativo' : 'Inativo' ?></span>
                </div>

                <div class="stat-value text-primary mb-2"><?= $p['preco'] > 0 ? formatMoney($p['preco']) : 'Gratis' ?></div>
                <p class="text-muted small mb-2"><?= e(ucfirst($p['periodo'])) ?></p>

                <hr>
                <p class="small mb-1">
                    <i class="fas fa-file-invoice me-1"></i>
                    NFC-e: <?= $p['limite_nfce'] > 0 ? number_format($p['limite_nfce'], 0, ',', '.') . '/mes' : 'Ilimitado' ?>
                </p>
                <p class="small mb-1">
                    <i class="fas fa-desktop me-1"></i>
                    Terminais: <?= $p['limite_terminais'] > 0 ? $p['limite_terminais'] : 'Ilimitado' ?>
                </p>
                <p class="small mb-0">
                    <i class="fas fa-users me-1"></i>
                    <?= $p['total_clientes'] ?> clientes | <?= $p['total_licencas'] ?> licencas
                </p>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- SaaS -->
<h5 class="mb-3"><i class="fas fa-cloud me-2"></i>SaaS</h5>
<div class="row g-3 mb-4">
    <?php foreach ($planos as $p): ?>
        <?php if ($p['tipo_produto'] !== 'saas') continue; ?>
        <div class="col-md-4">
            <div class="stat-card h-100">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <h5 class="fw-bold mb-0"><?= e($p['nome']) ?></h5>
                        <small class="text-muted"><?= e($p['slug']) ?></small>
                    </div>
                    <span class="badge bg-<?= $p['ativo'] ? 'success' : 'secondary' ?>"><?= $p['ativo'] ? 'Ativo' : 'Inativo' ?></span>
                </div>

                <div class="stat-value text-primary mb-2"><?= formatMoney($p['preco']) ?></div>
                <p class="text-muted small mb-2"><?= e(ucfirst($p['periodo'])) ?></p>

                <hr>
                <p class="small mb-1">
                    <i class="fas fa-file-invoice me-1"></i>
                    NFC-e: <?= $p['limite_nfce'] > 0 ? number_format($p['limite_nfce'], 0, ',', '.') . '/mes' : 'Ilimitado' ?>
                </p>
                <p class="small mb-1">
                    <i class="fas fa-desktop me-1"></i>
                    Terminais: <?= $p['limite_terminais'] > 0 ? $p['limite_terminais'] : 'Ilimitado' ?>
                </p>
                <p class="small mb-0">
                    <i class="fas fa-users me-1"></i>
                    <?= $p['total_clientes'] ?> clientes | <?= $p['total_licencas'] ?> licencas
                </p>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php include APP_PATH . '/includes/footer.php'; ?>
