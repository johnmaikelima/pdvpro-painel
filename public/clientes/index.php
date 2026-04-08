<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$pageTitle = 'Clientes';

// Filtros
$busca = trim($_GET['busca'] ?? '');
$status = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));

$where = "WHERE 1=1";
$params = [];

if ($busca) {
    $where .= " AND (c.razao_social LIKE ? OR c.nome_fantasia LIKE ? OR c.cnpj LIKE ? OR c.email LIKE ?)";
    $params = array_merge($params, ["%{$busca}%", "%{$busca}%", "%{$busca}%", "%{$busca}%"]);
}

if ($status) {
    $where .= " AND c.status = ?";
    $params[] = $status;
}

$query = "SELECT c.*, p.nome as plano_nome FROM clientes c LEFT JOIN planos p ON c.plano_id = p.id {$where} ORDER BY c.criado_em DESC";
$result = paginate($pdo, $query, $params, $page);

include APP_PATH . '/includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-building me-2"></i>Clientes</h2>
    <a href="form.php" class="btn btn-primary"><i class="fas fa-plus me-1"></i>Novo Cliente</a>
</div>

<!-- Filtros -->
<div class="table-card mb-3">
    <div class="card-header">
        <form method="GET" class="d-flex gap-2 w-100">
            <input type="text" name="busca" class="form-control form-control-sm" placeholder="Buscar por nome, CNPJ, email..."
                   value="<?= e($busca) ?>" style="max-width:300px;">
            <select name="status" class="form-select form-select-sm" style="max-width:160px;">
                <option value="">Todos status</option>
                <option value="ativo" <?= $status === 'ativo' ? 'selected' : '' ?>>Ativo</option>
                <option value="trial" <?= $status === 'trial' ? 'selected' : '' ?>>Trial</option>
                <option value="inativo" <?= $status === 'inativo' ? 'selected' : '' ?>>Inativo</option>
                <option value="inadimplente" <?= $status === 'inadimplente' ? 'selected' : '' ?>>Inadimplente</option>
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
                <th>Cliente</th>
                <th>CNPJ/CPF</th>
                <th>Contato</th>
                <th>Plano</th>
                <th>Status</th>
                <th>Desde</th>
                <th width="120">Acoes</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($result['items'])): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Nenhum cliente encontrado</td></tr>
            <?php else: ?>
                <?php foreach ($result['items'] as $c): ?>
                <tr>
                    <td>
                        <strong><?= e($c['nome_fantasia'] ?: $c['razao_social']) ?></strong>
                        <?php if ($c['nome_fantasia'] && $c['razao_social'] !== $c['nome_fantasia']): ?>
                            <br><small class="text-muted"><?= e($c['razao_social']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= e($c['cnpj'] ?: $c['cpf'] ?: '-') ?></td>
                    <td>
                        <?php if ($c['email']): ?><small><?= e($c['email']) ?></small><br><?php endif; ?>
                        <?php if ($c['whatsapp']): ?>
                            <a href="https://wa.me/55<?= preg_replace('/\D/', '', $c['whatsapp']) ?>" target="_blank" class="text-success">
                                <i class="fab fa-whatsapp"></i> <?= e($c['whatsapp']) ?>
                            </a>
                        <?php elseif ($c['telefone']): ?>
                            <small><?= e($c['telefone']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= $c['plano_nome'] ? e($c['plano_nome']) : '<span class="text-muted">-</span>' ?></td>
                    <td><?= statusBadge($c['status']) ?></td>
                    <td><?= formatDate($c['criado_em']) ?></td>
                    <td>
                        <a href="form.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary" title="Editar">
                            <i class="fas fa-edit"></i>
                        </a>
                        <a href="view.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-info" title="Detalhes">
                            <i class="fas fa-eye"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Paginacao -->
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

<p class="text-muted text-center"><small><?= $result['total'] ?> cliente(s) encontrado(s)</small></p>

<?php include APP_PATH . '/includes/footer.php'; ?>
