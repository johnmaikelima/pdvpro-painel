<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
requireLogin();

$pageTitle = 'Dashboard';

// Metricas
$totalClientes = $pdo->query("SELECT COUNT(*) FROM clientes")->fetchColumn();
$clientesAtivos = $pdo->query("SELECT COUNT(*) FROM clientes WHERE status = 'ativo'")->fetchColumn();
$clientesTrial = $pdo->query("SELECT COUNT(*) FROM clientes WHERE status = 'trial'")->fetchColumn();
$clientesInadimplentes = $pdo->query("SELECT COUNT(*) FROM clientes WHERE status = 'inadimplente'")->fetchColumn();

$licencasAtivas = $pdo->query("SELECT COUNT(*) FROM licencas WHERE status = 'ativa'")->fetchColumn();
$licencasDisponiveis = $pdo->query("SELECT COUNT(*) FROM licencas WHERE status = 'disponivel'")->fetchColumn();
$licencasExpiradas = $pdo->query("SELECT COUNT(*) FROM licencas WHERE status = 'expirada'")->fetchColumn();

// Receita do mes
$mesAtual = date('Y-m');
$receitaMes = $pdo->prepare("SELECT COALESCE(SUM(valor), 0) FROM pagamentos WHERE status = 'pago' AND mes_referencia = ?");
$receitaMes->execute([$mesAtual]);
$receitaMes = (float)$receitaMes->fetchColumn();

// Receita total
$receitaTotal = $pdo->query("SELECT COALESCE(SUM(valor), 0) FROM pagamentos WHERE status = 'pago'")->fetchColumn();

// NFC-e emitidas no mes
$stmtNfce = $pdo->prepare("SELECT COALESCE(SUM(nfce_emitidas_mes), 0) FROM licencas WHERE nfce_mes_referencia = ?");
$stmtNfce->execute([$mesAtual]);
$nfceMes = $stmtNfce->fetchColumn();

// Ultimos clientes
$ultimosClientes = $pdo->query("
    SELECT c.*, p.nome as plano_nome
    FROM clientes c
    LEFT JOIN planos p ON c.plano_id = p.id
    ORDER BY c.criado_em DESC
    LIMIT 5
")->fetchAll();

// Licencas que vencem em 7 dias
$vencendo = $pdo->query("
    SELECT l.*, c.razao_social, c.whatsapp
    FROM licencas l
    LEFT JOIN clientes c ON l.cliente_id = c.id
    WHERE l.status = 'ativa'
    AND l.data_vencimento BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
    ORDER BY l.data_vencimento ASC
    LIMIT 10
")->fetchAll();

// Ultimos logs da API
$ultimosLogs = $pdo->query("
    SELECT al.*, c.razao_social
    FROM api_logs al
    LEFT JOIN clientes c ON al.cliente_id = c.id
    ORDER BY al.criado_em DESC
    LIMIT 10
")->fetchAll();

include APP_PATH . '/includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-chart-line me-2"></i>Dashboard</h2>
    <small class="text-muted"><?= date('d/m/Y H:i') ?></small>
</div>

<!-- Cards de metricas -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-sm-6">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-value"><?= $totalClientes ?></div>
                    <div class="stat-label">Clientes Total</div>
                </div>
                <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                    <i class="fas fa-building"></i>
                </div>
            </div>
            <div class="mt-2">
                <small class="text-success"><?= $clientesAtivos ?> ativos</small>
                <small class="text-muted mx-1">|</small>
                <small class="text-info"><?= $clientesTrial ?> trial</small>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-value"><?= $licencasAtivas ?></div>
                    <div class="stat-label">Licencas Ativas</div>
                </div>
                <div class="stat-icon bg-success bg-opacity-10 text-success">
                    <i class="fas fa-key"></i>
                </div>
            </div>
            <div class="mt-2">
                <small class="text-secondary"><?= $licencasDisponiveis ?> disponiveis</small>
                <small class="text-muted mx-1">|</small>
                <small class="text-warning"><?= $licencasExpiradas ?> expiradas</small>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-value"><?= formatMoney($receitaMes) ?></div>
                    <div class="stat-label">Receita do Mes</div>
                </div>
                <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                    <i class="fas fa-dollar-sign"></i>
                </div>
            </div>
            <div class="mt-2">
                <small class="text-muted">Total: <?= formatMoney((float)$receitaTotal) ?></small>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-value"><?= number_format($nfceMes, 0, ',', '.') ?></div>
                    <div class="stat-label">NFC-e no Mes</div>
                </div>
                <div class="stat-icon bg-info bg-opacity-10 text-info">
                    <i class="fas fa-file-invoice"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Licencas vencendo -->
    <?php if (!empty($vencendo)): ?>
    <div class="col-12">
        <div class="table-card border-warning">
            <div class="card-header bg-warning bg-opacity-10">
                <h5><i class="fas fa-exclamation-triangle text-warning me-2"></i>Licencas Vencendo em 7 Dias</h5>
            </div>
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>Chave</th>
                        <th>Vencimento</th>
                        <th>WhatsApp</th>
                        <th>Acao</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vencendo as $v): ?>
                    <tr>
                        <td><?= e($v['razao_social'] ?? 'Sem cliente') ?></td>
                        <td><span class="license-key"><?= e($v['chave']) ?></span></td>
                        <td>
                            <span class="text-warning fw-bold"><?= formatDate($v['data_vencimento']) ?></span>
                        </td>
                        <td><?= e($v['whatsapp'] ?? '-') ?></td>
                        <td>
                            <?php if ($v['whatsapp']): ?>
                                <?php $msg = urlencode("Ola! Sua licenca do Balcão PDV vence em " . formatDate($v['data_vencimento']) . ". Deseja renovar?"); ?>
                                <a href="https://wa.me/55<?= preg_replace('/\D/', '', $v['whatsapp']) ?>?text=<?= $msg ?>"
                                   target="_blank" class="btn btn-sm btn-success">
                                    <i class="fab fa-whatsapp"></i>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Ultimos clientes -->
    <div class="col-md-6">
        <div class="table-card">
            <div class="card-header">
                <h5><i class="fas fa-building me-2"></i>Ultimos Clientes</h5>
                <a href="<?= APP_URL ?>/clientes/" class="btn btn-sm btn-outline-primary">Ver todos</a>
            </div>
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>Plano</th>
                        <th>Status</th>
                        <th>Desde</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ultimosClientes)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">Nenhum cliente cadastrado</td></tr>
                    <?php else: ?>
                        <?php foreach ($ultimosClientes as $c): ?>
                        <tr>
                            <td>
                                <strong><?= e($c['nome_fantasia'] ?: $c['razao_social']) ?></strong>
                                <br><small class="text-muted"><?= e($c['cnpj'] ?? $c['cpf'] ?? '') ?></small>
                            </td>
                            <td><?= e($c['plano_nome'] ?? 'Sem plano') ?></td>
                            <td><?= statusBadge($c['status']) ?></td>
                            <td><?= formatDate($c['criado_em']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Atividade da API -->
    <div class="col-md-6">
        <div class="table-card">
            <div class="card-header">
                <h5><i class="fas fa-exchange-alt me-2"></i>Atividade Recente (API)</h5>
            </div>
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Acao</th>
                        <th>Cliente</th>
                        <th>IP</th>
                        <th>Data</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ultimosLogs)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">Nenhuma atividade registrada</td></tr>
                    <?php else: ?>
                        <?php foreach ($ultimosLogs as $log): ?>
                        <tr>
                            <td><span class="badge bg-secondary"><?= e($log['acao']) ?></span></td>
                            <td><?= e($log['razao_social'] ?? '-') ?></td>
                            <td><small><?= e($log['ip'] ?? '-') ?></small></td>
                            <td><small><?= formatDateTime($log['criado_em']) ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include APP_PATH . '/includes/footer.php'; ?>
