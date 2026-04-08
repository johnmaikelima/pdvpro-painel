<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
requireLogin();

$pageTitle = 'Planos';

// Salvar edicao
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        flash('danger', 'Token CSRF invalido.');
        redirect('index.php');
    }

    $id = (int)($_POST['id'] ?? 0);
    $nome = trim($_POST['nome'] ?? '');
    $preco = (float)str_replace(',', '.', $_POST['preco'] ?? '0');
    $limiteNfce = (int)($_POST['limite_nfce'] ?? 0);
    $limiteTerminais = (int)($_POST['limite_terminais'] ?? 1);
    $ativo = (int)($_POST['ativo'] ?? 0);

    if ($id && !empty($nome)) {
        $pdo->prepare("UPDATE planos SET nome = ?, preco = ?, limite_nfce = ?, limite_terminais = ?, ativo = ? WHERE id = ?")
            ->execute([$nome, $preco, $limiteNfce, $limiteTerminais, $ativo, $id]);
        flash('success', "Plano \"{$nome}\" atualizado!");
    }

    redirect('index.php');
}

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

<?php foreach (['desktop' => 'Desktop', 'saas' => 'SaaS'] as $tipo => $label): ?>
<h5 class="mb-3"><i class="fas fa-<?= $tipo === 'desktop' ? 'desktop' : 'cloud' ?> me-2"></i><?= $label ?></h5>
<div class="table-card mb-4">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Periodo</th>
                    <th>Preco</th>
                    <th>NFC-e/mes</th>
                    <th>Terminais</th>
                    <th>Clientes</th>
                    <th>Status</th>
                    <th width="100">Acoes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($planos as $p): ?>
                    <?php if ($p['tipo_produto'] !== $tipo) continue; ?>
                    <tr>
                        <td>
                            <strong><?= e($p['nome']) ?></strong>
                            <br><small class="text-muted"><?= e($p['slug']) ?></small>
                        </td>
                        <td><?= e(ucfirst($p['periodo'])) ?></td>
                        <td class="fw-bold"><?= $p['preco'] > 0 ? formatMoney((float)$p['preco']) : '<span class="text-success">Gratis</span>' ?></td>
                        <td><?= $p['limite_nfce'] > 0 ? number_format($p['limite_nfce'], 0, ',', '.') : '<span class="text-primary">Ilimitado</span>' ?></td>
                        <td><?= $p['limite_terminais'] > 0 ? $p['limite_terminais'] : 'Ilimitado' ?></td>
                        <td><?= $p['total_clientes'] ?> clientes<br><small class="text-muted"><?= $p['total_licencas'] ?> licencas</small></td>
                        <td><?= $p['ativo'] ? '<span class="badge bg-success">Ativo</span>' : '<span class="badge bg-secondary">Inativo</span>' ?></td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal<?= $p['id'] ?>">
                                <i class="fas fa-edit"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<!-- Modais de edicao -->
<?php foreach ($planos as $p): ?>
<div class="modal fade" id="editModal<?= $p['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= $p['id'] ?>">

                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Editar Plano</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nome</label>
                        <input type="text" name="nome" class="form-control" value="<?= e($p['nome']) ?>" required>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Slug</label>
                            <input type="text" class="form-control" value="<?= e($p['slug']) ?>" disabled>
                            <small class="text-muted">Nao editavel (usado na API)</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Periodo</label>
                            <input type="text" class="form-control" value="<?= e(ucfirst($p['periodo'])) ?>" disabled>
                        </div>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-md-4">
                            <label class="form-label">Preco (R$)</label>
                            <input type="number" name="preco" class="form-control" value="<?= number_format((float)$p['preco'], 2, '.', '') ?>" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Limite NFC-e/mes</label>
                            <input type="number" name="limite_nfce" class="form-control" value="<?= (int)$p['limite_nfce'] ?>" min="0">
                            <small class="text-muted">0 = ilimitado</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Terminais</label>
                            <input type="number" name="limite_terminais" class="form-control" value="<?= (int)$p['limite_terminais'] ?>" min="0">
                            <small class="text-muted">0 = ilimitado</small>
                        </div>
                    </div>

                    <div class="mt-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="ativo" value="1" id="ativo<?= $p['id'] ?>" <?= $p['ativo'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="ativo<?= $p['id'] ?>">Plano ativo (visivel para clientes)</label>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php include APP_PATH . '/includes/footer.php'; ?>
