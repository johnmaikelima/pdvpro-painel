<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
requireLogin();

$pageTitle = 'Faturamento';

// Filtros
$filtroStatus = $_GET['status'] ?? '';
$filtroMes = $_GET['mes'] ?? date('Y-m');
$filtroBusca = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));

// Metricas do mes selecionado
$stmtMetricas = $pdo->prepare("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pago' THEN 1 ELSE 0 END) as pagos,
        SUM(CASE WHEN status = 'pendente' THEN 1 ELSE 0 END) as pendentes,
        SUM(CASE WHEN status = 'cancelado' THEN 1 ELSE 0 END) as cancelados,
        SUM(CASE WHEN status = 'pago' THEN valor ELSE 0 END) as receita,
        SUM(CASE WHEN status = 'pendente' THEN valor ELSE 0 END) as pendente_valor
    FROM pagamentos
    WHERE mes_referencia = ?
");
$stmtMetricas->execute([$filtroMes]);
$metricas = $stmtMetricas->fetch();

// MRR (receita recorrente mensal)
$stmtMrr = $pdo->query("SELECT SUM(valor) as mrr FROM pagamentos WHERE status = 'pago' AND tipo_cobranca = 'recorrente' AND mes_referencia = '" . date('Y-m') . "'");
$mrr = (float)($stmtMrr->fetch()['mrr'] ?? 0);

// Query principal
$where = "WHERE p.mes_referencia = ?";
$params = [$filtroMes];

if ($filtroStatus) {
    $where .= " AND p.status = ?";
    $params[] = $filtroStatus;
}

if ($filtroBusca) {
    $where .= " AND (c.razao_social LIKE ? OR c.cnpj LIKE ? OR l.chave LIKE ?)";
    $buscaLike = "%{$filtroBusca}%";
    $params[] = $buscaLike;
    $params[] = $buscaLike;
    $params[] = $buscaLike;
}

$query = "SELECT p.*, c.razao_social, c.cnpj, c.email as cliente_email, l.chave, pl.nome as plano_nome
    FROM pagamentos p
    LEFT JOIN clientes c ON p.cliente_id = c.id
    LEFT JOIN licencas l ON p.licenca_id = l.id
    LEFT JOIN planos pl ON p.plano_id = pl.id
    {$where}
    ORDER BY p.criado_em DESC";

$result = paginate($pdo, $query, $params, $page, 25);

// Meses disponiveis
$meses = $pdo->query("SELECT DISTINCT mes_referencia FROM pagamentos WHERE mes_referencia IS NOT NULL ORDER BY mes_referencia DESC LIMIT 24")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($filtroMes, $meses)) {
    $meses[] = $filtroMes;
    rsort($meses);
}

include APP_PATH . '/includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center">
    <h2><i class="fas fa-file-invoice-dollar me-2"></i>Faturamento</h2>
</div>

<!-- Metricas -->
<div class="row g-3 mb-4">
    <div class="col-md-2">
        <div class="stat-card text-center">
            <div class="stat-label">Receita</div>
            <div class="stat-value text-success"><?= formatMoney((float)($metricas['receita'] ?? 0)) ?></div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="stat-card text-center">
            <div class="stat-label">Pendente</div>
            <div class="stat-value text-warning"><?= formatMoney((float)($metricas['pendente_valor'] ?? 0)) ?></div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="stat-card text-center">
            <div class="stat-label">MRR</div>
            <div class="stat-value text-primary"><?= formatMoney($mrr) ?></div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="stat-card text-center">
            <div class="stat-label">Pagos</div>
            <div class="stat-value text-success"><?= (int)($metricas['pagos'] ?? 0) ?></div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="stat-card text-center">
            <div class="stat-label">Pendentes</div>
            <div class="stat-value text-warning"><?= (int)($metricas['pendentes'] ?? 0) ?></div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="stat-card text-center">
            <div class="stat-label">Cancelados</div>
            <div class="stat-value text-danger"><?= (int)($metricas['cancelados'] ?? 0) ?></div>
        </div>
    </div>
</div>

<!-- Filtros -->
<div class="table-card mb-3">
    <div class="p-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small">Mes</label>
                <select name="mes" class="form-select form-select-sm">
                    <?php foreach ($meses as $m): ?>
                        <option value="<?= $m ?>" <?= $m === $filtroMes ? 'selected' : '' ?>><?= date('M/Y', strtotime($m . '-01')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <option value="pago" <?= $filtroStatus === 'pago' ? 'selected' : '' ?>>Pago</option>
                    <option value="pendente" <?= $filtroStatus === 'pendente' ? 'selected' : '' ?>>Pendente</option>
                    <option value="cancelado" <?= $filtroStatus === 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small">Buscar</label>
                <input type="text" name="q" class="form-control form-control-sm" value="<?= e($filtroBusca) ?>" placeholder="Empresa, CNPJ ou chave...">
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary btn-sm w-100"><i class="fas fa-search me-1"></i>Filtrar</button>
            </div>
        </form>
    </div>
</div>

<!-- Tabela -->
<div class="table-card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Cliente</th>
                    <th>Plano</th>
                    <th>Valor</th>
                    <th>Tipo</th>
                    <th>Status</th>
                    <th>Data</th>
                    <th>Acoes</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($result['items'])): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Nenhum pagamento encontrado.</td></tr>
                <?php endif; ?>
                <?php foreach ($result['items'] as $p): ?>
                    <tr>
                        <td><?= $p['id'] ?></td>
                        <td>
                            <strong><?= e($p['razao_social'] ?? '-') ?></strong>
                            <?php if ($p['cnpj']): ?>
                                <br><small class="text-muted"><?= e($p['cnpj']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= e($p['plano_nome'] ?? '-') ?>
                            <?php if ($p['chave']): ?>
                                <br><small class="text-muted font-monospace"><?= e(substr($p['chave'], 0, 9)) ?>...</small>
                            <?php endif; ?>
                        </td>
                        <td class="fw-bold"><?= formatMoney((float)$p['valor']) ?></td>
                        <td>
                            <?php if (($p['tipo_cobranca'] ?? 'avulso') === 'recorrente'): ?>
                                <span class="badge bg-info">Recorrente</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Avulso</span>
                            <?php endif; ?>
                        </td>
                        <td><?= statusBadge($p['status']) ?></td>
                        <td>
                            <?= formatDateTime($p['criado_em']) ?>
                            <?php if ($p['data_pagamento']): ?>
                                <br><small class="text-success">Pago: <?= formatDateTime($p['data_pagamento']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($p['asaas_url'])): ?>
                                <a href="<?= e($p['asaas_url']) ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="Ver no Asaas">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            <?php endif; ?>
                            <?php if ($p['cliente_id']): ?>
                                <a href="/clientes/view.php?id=<?= $p['cliente_id'] ?>" class="btn btn-sm btn-outline-secondary" title="Ver cliente">
                                    <i class="fas fa-user"></i>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($result['total_pages'] > 1): ?>
        <div class="p-3">
            <nav>
                <ul class="pagination pagination-sm justify-content-center mb-0">
                    <?php for ($i = 1; $i <= $result['total_pages']; $i++): ?>
                        <li class="page-item <?= $i === $result['page'] ? 'active' : '' ?>">
                            <a class="page-link" href="?mes=<?= e($filtroMes) ?>&status=<?= e($filtroStatus) ?>&q=<?= e($filtroBusca) ?>&page=<?= $i ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php include APP_PATH . '/includes/footer.php'; ?>
