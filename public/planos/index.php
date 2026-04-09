<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
requireLogin();

$pageTitle = 'Planos';

// Salvar edicao ou criar novo
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        flash('danger', 'Token CSRF inválido.');
        redirect('index.php');
    }

    $acao = $_POST['acao'] ?? 'editar';
    $nome = trim($_POST['nome'] ?? '');
    $preco = (float)str_replace(',', '.', $_POST['preco'] ?? '0');
    $precoMensal = !empty($_POST['preco_mensal']) ? (float)str_replace(',', '.', $_POST['preco_mensal']) : null;
    $precoTrimestral = !empty($_POST['preco_trimestral']) ? (float)str_replace(',', '.', $_POST['preco_trimestral']) : null;
    $precoSemestral = !empty($_POST['preco_semestral']) ? (float)str_replace(',', '.', $_POST['preco_semestral']) : null;
    $precoAnual = !empty($_POST['preco_anual']) ? (float)str_replace(',', '.', $_POST['preco_anual']) : null;
    $limiteNfce = (int)($_POST['limite_nfce'] ?? 0);
    $limiteTerminais = (int)($_POST['limite_terminais'] ?? 1);
    $ativo = (int)($_POST['ativo'] ?? 0);
    $recursos = trim($_POST['recursos'] ?? '');

    if ($acao === 'criar' && !empty($nome)) {
        $slug = trim($_POST['slug'] ?? '');
        $tipoProduto = $_POST['tipo_produto'] ?? 'desktop';
        $periodo = $_POST['periodo'] ?? 'mensal';

        if (empty($slug)) {
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9-]/', '-', $nome));
            $slug = preg_replace('/-+/', '-', trim($slug, '-'));
        }

        $existe = $pdo->prepare("SELECT id FROM planos WHERE slug = ?");
        $existe->execute([$slug]);
        if ($existe->fetch()) {
            flash('danger', "Já existe um plano com o slug \"{$slug}\".");
            redirect('index.php');
        }

        $pdo->prepare("INSERT INTO planos (nome, slug, tipo_produto, periodo, preco, preco_mensal, preco_trimestral, preco_semestral, preco_anual, limite_nfce, limite_terminais, recursos, ativo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$nome, $slug, $tipoProduto, $periodo, $preco, $precoMensal, $precoTrimestral, $precoSemestral, $precoAnual, $limiteNfce, $limiteTerminais, $recursos ?: null, $ativo]);
        flash('success', "Plano \"{$nome}\" criado com sucesso!");

    } elseif ($acao === 'editar') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id && !empty($nome)) {
            $pdo->prepare("UPDATE planos SET nome = ?, preco = ?, preco_mensal = ?, preco_trimestral = ?, preco_semestral = ?, preco_anual = ?, limite_nfce = ?, limite_terminais = ?, recursos = ?, ativo = ? WHERE id = ?")
                ->execute([$nome, $preco, $precoMensal, $precoTrimestral, $precoSemestral, $precoAnual, $limiteNfce, $limiteTerminais, $recursos ?: null, $ativo, $id]);
            flash('success', "Plano \"{$nome}\" atualizado!");
        }

    } elseif ($acao === 'excluir') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            // Verificar se tem clientes ou licenças vinculados
            $check = $pdo->prepare("SELECT (SELECT COUNT(*) FROM clientes WHERE plano_id = ?) + (SELECT COUNT(*) FROM licencas WHERE plano_id = ?) as total");
            $check->execute([$id, $id]);
            if ((int)$check->fetchColumn() > 0) {
                flash('danger', 'Não é possível excluir um plano com clientes ou licenças vinculados.');
            } else {
                $pdo->prepare("DELETE FROM planos WHERE id = ?")->execute([$id]);
                flash('success', 'Plano excluído com sucesso.');
            }
        }
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

<div class="page-header d-flex justify-content-between align-items-center">
    <h2><i class="fas fa-tags me-2"></i>Planos</h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#criarModal">
        <i class="fas fa-plus me-1"></i>Novo Plano
    </button>
</div>

<?php foreach (['desktop' => 'Desktop', 'saas' => 'SaaS'] as $tipo => $label): ?>
<h5 class="mb-3"><i class="fas fa-<?= $tipo === 'desktop' ? 'desktop' : 'cloud' ?> me-2"></i><?= $label ?></h5>
<div class="table-card mb-4">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Preços</th>
                    <th>NFC-e/mês</th>
                    <th>Terminais</th>
                    <th>Clientes</th>
                    <th>Status</th>
                    <th width="100">Ações</th>
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
                        <td>
                            <?php if ((float)$p['preco'] == 0 && !$p['preco_mensal']): ?>
                                <span class="text-success fw-bold">Grátis</span>
                            <?php else: ?>
                                <?php if ($p['preco_mensal']): ?><small>Mensal: <strong><?= formatMoney((float)$p['preco_mensal']) ?></strong></small><br><?php endif; ?>
                                <?php if ($p['preco_trimestral']): ?><small>Trim: <?= formatMoney((float)$p['preco_trimestral']) ?></small><br><?php endif; ?>
                                <?php if ($p['preco_semestral']): ?><small>Sem: <?= formatMoney((float)$p['preco_semestral']) ?></small><br><?php endif; ?>
                                <?php if ($p['preco_anual']): ?><small>Anual: <?= formatMoney((float)$p['preco_anual']) ?></small><?php endif; ?>
                                <?php if (!$p['preco_mensal'] && !$p['preco_trimestral'] && !$p['preco_semestral'] && !$p['preco_anual']): ?>
                                    <strong><?= formatMoney((float)$p['preco']) ?></strong>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td><?= $p['limite_nfce'] > 0 ? number_format($p['limite_nfce'], 0, ',', '.') : '<span class="text-primary">Ilimitado</span>' ?></td>
                        <td><?= $p['limite_terminais'] > 0 ? $p['limite_terminais'] : 'Ilimitado' ?></td>
                        <td><?= $p['total_clientes'] ?> clientes<br><small class="text-muted"><?= $p['total_licencas'] ?> licenças</small></td>
                        <td><?= $p['ativo'] ? '<span class="badge bg-success">Ativo</span>' : '<span class="badge bg-secondary">Inativo</span>' ?></td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal<?= $p['id'] ?>">
                                <i class="fas fa-edit"></i>
                            </button>
                            <?php if ($p['total_clientes'] == 0 && $p['total_licencas'] == 0): ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Excluir o plano \'<?= e($p['nome']) ?>\'?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="acao" value="excluir">
                                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger ms-1"><i class="fas fa-trash"></i></button>
                            </form>
                            <?php endif; ?>
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
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= $p['id'] ?>">

                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Editar Plano: <?= e($p['nome']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Nome</label>
                            <input type="text" name="nome" class="form-control" value="<?= e($p['nome']) ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Slug</label>
                            <input type="text" class="form-control" value="<?= e($p['slug']) ?>" disabled>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Tipo</label>
                            <input type="text" class="form-control" value="<?= e(ucfirst($p['tipo_produto'])) ?>" disabled>
                        </div>
                    </div>

                    <hr class="my-3">
                    <h6 class="fw-bold"><i class="fas fa-dollar-sign me-1"></i>Preços por Período</h6>

                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Preço base (R$)</label>
                            <input type="number" name="preco" class="form-control" value="<?= number_format((float)$p['preco'], 2, '.', '') ?>" step="0.01" min="0" required>
                            <small class="text-muted">Referência</small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Mensal (R$)</label>
                            <input type="number" name="preco_mensal" class="form-control" value="<?= $p['preco_mensal'] ? number_format((float)$p['preco_mensal'], 2, '.', '') : '' ?>" step="0.01" min="0" placeholder="0.00">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Trimestral (R$)</label>
                            <input type="number" name="preco_trimestral" class="form-control" value="<?= $p['preco_trimestral'] ? number_format((float)$p['preco_trimestral'], 2, '.', '') : '' ?>" step="0.01" min="0" placeholder="0.00">
                            <small class="text-muted">Valor/mês</small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Semestral (R$)</label>
                            <input type="number" name="preco_semestral" class="form-control" value="<?= $p['preco_semestral'] ? number_format((float)$p['preco_semestral'], 2, '.', '') : '' ?>" step="0.01" min="0" placeholder="0.00">
                            <small class="text-muted">Valor/mês</small>
                        </div>
                    </div>
                    <div class="row g-3 mt-1">
                        <div class="col-md-3">
                            <label class="form-label">Anual (R$)</label>
                            <input type="number" name="preco_anual" class="form-control" value="<?= $p['preco_anual'] ? number_format((float)$p['preco_anual'], 2, '.', '') : '' ?>" step="0.01" min="0" placeholder="0.00">
                            <small class="text-muted">Valor/mês</small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Limite NFC-e/mês</label>
                            <input type="number" name="limite_nfce" class="form-control" value="<?= (int)$p['limite_nfce'] ?>" min="0">
                            <small class="text-muted">0 = ilimitado</small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Terminais</label>
                            <input type="number" name="limite_terminais" class="form-control" value="<?= (int)$p['limite_terminais'] ?>" min="0">
                            <small class="text-muted">0 = ilimitado</small>
                        </div>
                    </div>

                    <hr class="my-3">

                    <div class="mb-3">
                        <label class="form-label">Recursos (JSON)</label>
                        <textarea name="recursos" class="form-control" rows="4" placeholder='{"descricao": "...", "beneficios": ["..."]}'><?= e($p['recursos'] ?? '') ?></textarea>
                        <small class="text-muted">Ex: {"descricao": "Para pequenos negócios", "destaque": true, "beneficios": ["Item 1", "Item 2"]}</small>
                    </div>

                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="ativo" value="1" id="ativo<?= $p['id'] ?>" <?= $p['ativo'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="ativo<?= $p['id'] ?>">Plano ativo (visível para clientes)</label>
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

<!-- Modal Criar Plano -->
<div class="modal fade" id="criarModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="acao" value="criar">

                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Novo Plano</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Nome</label>
                            <input type="text" name="nome" class="form-control" placeholder="Ex: Desktop Pro" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Slug</label>
                            <input type="text" name="slug" class="form-control" placeholder="Ex: desktop-pro">
                            <small class="text-muted">Vazio = auto</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Tipo de Produto</label>
                            <select name="tipo_produto" class="form-select">
                                <option value="desktop">Desktop</option>
                                <option value="saas">SaaS</option>
                            </select>
                        </div>
                    </div>

                    <hr class="my-3">
                    <h6 class="fw-bold"><i class="fas fa-dollar-sign me-1"></i>Preços por Período</h6>

                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Preço base (R$)</label>
                            <input type="number" name="preco" class="form-control" value="0.00" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Mensal (R$)</label>
                            <input type="number" name="preco_mensal" class="form-control" step="0.01" min="0" placeholder="0.00">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Trimestral (R$)</label>
                            <input type="number" name="preco_trimestral" class="form-control" step="0.01" min="0" placeholder="0.00">
                            <small class="text-muted">Valor/mês</small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Semestral (R$)</label>
                            <input type="number" name="preco_semestral" class="form-control" step="0.01" min="0" placeholder="0.00">
                            <small class="text-muted">Valor/mês</small>
                        </div>
                    </div>
                    <div class="row g-3 mt-1">
                        <div class="col-md-3">
                            <label class="form-label">Anual (R$)</label>
                            <input type="number" name="preco_anual" class="form-control" step="0.01" min="0" placeholder="0.00">
                            <small class="text-muted">Valor/mês</small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Limite NFC-e/mês</label>
                            <input type="number" name="limite_nfce" class="form-control" value="0" min="0">
                            <small class="text-muted">0 = ilimitado</small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Terminais</label>
                            <input type="number" name="limite_terminais" class="form-control" value="1" min="0">
                            <small class="text-muted">0 = ilimitado</small>
                        </div>
                    </div>

                    <hr class="my-3">

                    <div class="mb-3">
                        <label class="form-label">Recursos (JSON)</label>
                        <textarea name="recursos" class="form-control" rows="3" placeholder='{"descricao": "...", "beneficios": ["..."]}'></textarea>
                        <small class="text-muted">Ex: {"descricao": "Para pequenos negócios", "destaque": true, "beneficios": ["Item 1", "Item 2"]}</small>
                    </div>

                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="ativo" value="1" id="ativoCriar" checked>
                        <label class="form-check-label" for="ativoCriar">Plano ativo (visível para clientes)</label>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Criar Plano</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include APP_PATH . '/includes/footer.php'; ?>
