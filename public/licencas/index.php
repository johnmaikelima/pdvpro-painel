<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
requireLogin();

$pageTitle = 'Licencas';

$busca = trim($_GET['busca'] ?? '');
$status = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));

$where = "WHERE 1=1";
$params = [];

if ($busca) {
    $where .= " AND (l.chave LIKE ? OR c.razao_social LIKE ? OR c.nome_fantasia LIKE ?)";
    $params = array_merge($params, ["%{$busca}%", "%{$busca}%", "%{$busca}%"]);
}

if ($status) {
    $where .= " AND l.status = ?";
    $params[] = $status;
}

$query = "SELECT l.*, c.razao_social, c.nome_fantasia, p.nome as plano_nome
          FROM licencas l
          LEFT JOIN clientes c ON l.cliente_id = c.id
          LEFT JOIN planos p ON l.plano_id = p.id
          {$where} ORDER BY l.criado_em DESC";
$result = paginate($pdo, $query, $params, $page);

include APP_PATH . '/includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-key me-2"></i>Licencas</h2>
    <a href="form.php" class="btn btn-primary"><i class="fas fa-plus me-1"></i>Gerar Licenca</a>
</div>

<!-- Filtros -->
<div class="table-card mb-3">
    <div class="card-header">
        <form method="GET" class="d-flex gap-2 w-100">
            <input type="text" name="busca" class="form-control form-control-sm" placeholder="Buscar por chave ou cliente..."
                   value="<?= e($busca) ?>" style="max-width:300px;">
            <select name="status" class="form-select form-select-sm" style="max-width:160px;">
                <option value="">Todos status</option>
                <option value="disponivel" <?= $status === 'disponivel' ? 'selected' : '' ?>>Disponivel</option>
                <option value="ativa" <?= $status === 'ativa' ? 'selected' : '' ?>>Ativa</option>
                <option value="expirada" <?= $status === 'expirada' ? 'selected' : '' ?>>Expirada</option>
                <option value="revogada" <?= $status === 'revogada' ? 'selected' : '' ?>>Revogada</option>
                <option value="bloqueada" <?= $status === 'bloqueada' ? 'selected' : '' ?>>Bloqueada</option>
            </select>
            <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fas fa-search"></i></button>
            <?php if ($busca || $status): ?>
                <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-times"></i></a>
            <?php endif; ?>
        </form>
    </div>

    <table class="table table-hover mb-0">
        <thead>
            <tr>
                <th>Chave</th>
                <th>Cliente</th>
                <th>Plano</th>
                <th>Tipo</th>
                <th>Status</th>
                <th>Ativacao</th>
                <th>Vencimento</th>
                <th>NFC-e/Mes</th>
                <th>Ultimo Check</th>
                <th width="100">Acoes</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($result['items'])): ?>
                <tr><td colspan="10" class="text-center text-muted py-4">Nenhuma licenca encontrada</td></tr>
            <?php else: ?>
                <?php foreach ($result['items'] as $l): ?>
                <tr>
                    <td>
                        <span class="license-key"><?= e($l['chave']) ?></span>
                        <button class="btn btn-sm btn-outline-secondary ms-1" onclick="copyToClipboard('<?= e($l['chave']) ?>', this)" title="Copiar">
                            <i class="fas fa-copy"></i>
                        </button>
                    </td>
                    <td>
                        <?php if ($l['cliente_id']): ?>
                            <a href="<?= APP_URL ?>/clientes/view.php?id=<?= $l['cliente_id'] ?>">
                                <?= e($l['nome_fantasia'] ?: $l['razao_social']) ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">Sem cliente</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($l['plano_nome'] ?? '-') ?></td>
                    <td><?= e(ucfirst($l['tipo'])) ?></td>
                    <td><?= statusBadge($l['status']) ?></td>
                    <td><?= formatDate($l['data_ativacao']) ?></td>
                    <td>
                        <?php if ($l['data_vencimento']): ?>
                            <?php
                            $venc = new DateTime($l['data_vencimento']);
                            $hoje = new DateTime();
                            $diff = $hoje->diff($venc);
                            $dias = $venc > $hoje ? (int)$diff->days : -(int)$diff->days;
                            $cor = $dias <= 0 ? 'danger' : ($dias <= 7 ? 'warning' : 'success');
                            ?>
                            <span class="text-<?= $cor ?>"><?= formatDate($l['data_vencimento']) ?></span>
                            <br><small class="text-<?= $cor ?>"><?= $dias > 0 ? "{$dias}d restantes" : 'Expirada' ?></small>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                    <td><?= $l['nfce_emitidas_mes'] ?></td>
                    <td><?= $l['ultimo_check'] ? formatDateTime($l['ultimo_check']) : '-' ?></td>
                    <td>
                        <?php if ($l['status'] === 'disponivel'): ?>
                            <a href="form.php?id=<?= $l['id'] ?>" class="btn btn-sm btn-outline-primary" title="Editar">
                                <i class="fas fa-edit"></i>
                            </a>
                        <?php endif; ?>
                        <?php if ($l['status'] === 'ativa'): ?>
                            <a href="revogar.php?id=<?= $l['id'] ?>" class="btn btn-sm btn-outline-danger" title="Revogar"
                               onclick="return confirmAction('Revogar esta licenca? O cliente sera bloqueado.')">
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

<?php if ($result['total_pages'] > 1): ?>
<nav>
    <ul class="pagination justify-content-center">
        <?php for ($i = 1; $i <= $result['total_pages']; $i++): ?>
            <li class="page-item <?= $i === $result['page'] ? 'active' : '' ?>">
                <a class="page-link" href="?page=<?= $i ?>&busca=<?= e($busca) ?>&status=<?= e($status) ?>"><?= $i ?></a>
            </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>

<p class="text-muted text-center"><small><?= $result['total'] ?> licenca(s) encontrada(s)</small></p>

<?php include APP_PATH . '/includes/footer.php'; ?>
