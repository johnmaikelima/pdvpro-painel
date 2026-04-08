<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT c.*, p.nome as plano_nome, p.limite_nfce FROM clientes c LEFT JOIN planos p ON c.plano_id = p.id WHERE c.id = ?");
$stmt->execute([$id]);
$cliente = $stmt->fetch();

if (!$cliente) {
    flash('danger', 'Cliente nao encontrado.');
    redirect('index.php');
}

$pageTitle = $cliente['nome_fantasia'] ?: $cliente['razao_social'];

// Licencas do cliente
$licencas = $pdo->prepare("SELECT * FROM licencas WHERE cliente_id = ? ORDER BY criado_em DESC");
$licencas->execute([$id]);
$licencas = $licencas->fetchAll();

// Pagamentos do cliente
$pagamentos = $pdo->prepare("SELECT pg.*, p.nome as plano_nome FROM pagamentos pg LEFT JOIN planos p ON pg.plano_id = p.id WHERE pg.cliente_id = ? ORDER BY pg.criado_em DESC LIMIT 20");
$pagamentos->execute([$id]);
$pagamentos = $pagamentos->fetchAll();

// Totais
$totalPago = $pdo->prepare("SELECT COALESCE(SUM(valor), 0) FROM pagamentos WHERE cliente_id = ? AND status = 'pago'");
$totalPago->execute([$id]);
$totalPago = (float)$totalPago->fetchColumn();

include APP_PATH . '/includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-building me-2"></i><?= e($pageTitle) ?></h2>
    <div>
        <a href="form.php?id=<?= $id ?>" class="btn btn-outline-primary"><i class="fas fa-edit me-1"></i>Editar</a>
        <a href="index.php" class="btn btn-outline-secondary ms-1"><i class="fas fa-arrow-left me-1"></i>Voltar</a>
    </div>
</div>

<!-- Info do cliente -->
<div class="row g-3 mb-4">
    <div class="col-md-8">
        <div class="table-card">
            <div class="card-header"><h5>Dados do Cliente</h5></div>
            <div class="p-4">
                <div class="row g-3">
                    <div class="col-md-6"><strong>Razao Social:</strong><br><?= e($cliente['razao_social']) ?></div>
                    <div class="col-md-6"><strong>Nome Fantasia:</strong><br><?= e($cliente['nome_fantasia'] ?? '-') ?></div>
                    <div class="col-md-4"><strong>CNPJ:</strong><br><?= e($cliente['cnpj'] ?? '-') ?></div>
                    <div class="col-md-4"><strong>Email:</strong><br><?= e($cliente['email'] ?? '-') ?></div>
                    <div class="col-md-4"><strong>WhatsApp:</strong><br>
                        <?php if ($cliente['whatsapp']): ?>
                            <a href="https://wa.me/55<?= preg_replace('/\D/', '', $cliente['whatsapp']) ?>" target="_blank" class="text-success">
                                <i class="fab fa-whatsapp"></i> <?= e($cliente['whatsapp']) ?>
                            </a>
                        <?php else: ?>-<?php endif; ?>
                    </div>
                    <div class="col-md-4"><strong>Cidade/UF:</strong><br><?= e(($cliente['cidade'] ?? '') . ($cliente['uf'] ? '/' . $cliente['uf'] : '')) ?: '-' ?></div>
                    <div class="col-md-4"><strong>Contato:</strong><br><?= e($cliente['contato_nome'] ?? '-') ?></div>
                    <div class="col-md-4"><strong>Cliente desde:</strong><br><?= formatDate($cliente['criado_em']) ?></div>
                    <?php if ($cliente['observacoes']): ?>
                        <div class="col-12"><strong>Observacoes:</strong><br><?= nl2br(e($cliente['observacoes'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="stat-card mb-3">
            <div class="stat-label">Status</div>
            <div class="mt-1"><?= statusBadge($cliente['status']) ?></div>
        </div>
        <div class="stat-card mb-3">
            <div class="stat-label">Plano</div>
            <div class="stat-value" style="font-size:1.2rem;"><?= e($cliente['plano_nome'] ?? 'Sem plano') ?></div>
            <?php if ($cliente['limite_nfce']): ?>
                <small class="text-muted">Limite: <?= number_format($cliente['limite_nfce'], 0, ',', '.') ?> NFC-e/mes</small>
            <?php else: ?>
                <small class="text-muted">NFC-e ilimitado</small>
            <?php endif; ?>
        </div>
        <div class="stat-card mb-3">
            <div class="stat-label">Total Pago</div>
            <div class="stat-value" style="font-size:1.2rem;"><?= formatMoney($totalPago) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">API Token</div>
            <div class="d-flex align-items-center gap-2 mt-1">
                <code class="small" id="tokenDisplay"><?= e(substr($cliente['api_token'] ?? '', 0, 16)) ?>...</code>
                <button class="btn btn-sm btn-outline-secondary" onclick="copyToClipboard('<?= e($cliente['api_token']) ?>', this)">
                    <i class="fas fa-copy"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Licencas -->
<div class="table-card mb-4">
    <div class="card-header">
        <h5><i class="fas fa-key me-2"></i>Licencas (<?= count($licencas) ?>)</h5>
        <a href="<?= APP_URL ?>/licencas/form.php?cliente_id=<?= $id ?>" class="btn btn-sm btn-primary">
            <i class="fas fa-plus me-1"></i>Gerar Licenca
        </a>
    </div>
    <table class="table table-hover mb-0">
        <thead>
            <tr>
                <th>Chave</th>
                <th>Tipo</th>
                <th>Status</th>
                <th>Ativacao</th>
                <th>Vencimento</th>
                <th>NFC-e/Mes</th>
                <th>Acoes</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($licencas)): ?>
                <tr><td colspan="7" class="text-center text-muted py-3">Nenhuma licenca</td></tr>
            <?php else: ?>
                <?php foreach ($licencas as $l): ?>
                <tr>
                    <td>
                        <span class="license-key"><?= e($l['chave']) ?></span>
                        <button class="btn btn-sm btn-outline-secondary ms-1" onclick="copyToClipboard('<?= e($l['chave']) ?>', this)">
                            <i class="fas fa-copy"></i>
                        </button>
                    </td>
                    <td><?= e(ucfirst($l['tipo'])) ?></td>
                    <td><?= statusBadge($l['status']) ?></td>
                    <td><?= formatDate($l['data_ativacao']) ?></td>
                    <td><?= formatDate($l['data_vencimento']) ?></td>
                    <td><?= $l['nfce_emitidas_mes'] ?></td>
                    <td>
                        <?php if ($l['status'] === 'ativa'): ?>
                            <a href="<?= APP_URL ?>/licencas/revogar.php?id=<?= $l['id'] ?>" class="btn btn-sm btn-outline-danger"
                               onclick="return confirmAction('Revogar esta licenca?')">
                                <i class="fas fa-ban"></i>
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Pagamentos -->
<div class="table-card">
    <div class="card-header">
        <h5><i class="fas fa-dollar-sign me-2"></i>Pagamentos</h5>
    </div>
    <table class="table table-hover mb-0">
        <thead>
            <tr>
                <th>Data</th>
                <th>Plano</th>
                <th>Valor</th>
                <th>Forma</th>
                <th>Status</th>
                <th>Referencia</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($pagamentos)): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">Nenhum pagamento registrado</td></tr>
            <?php else: ?>
                <?php foreach ($pagamentos as $pg): ?>
                <tr>
                    <td><?= formatDateTime($pg['criado_em']) ?></td>
                    <td><?= e($pg['plano_nome'] ?? '-') ?></td>
                    <td><strong><?= formatMoney($pg['valor']) ?></strong></td>
                    <td><?= e(ucfirst($pg['forma'])) ?></td>
                    <td><?= statusBadge($pg['status']) ?></td>
                    <td><small><?= e($pg['mes_referencia'] ?? '-') ?></small></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include APP_PATH . '/includes/footer.php'; ?>
