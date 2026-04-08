<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$pageTitle = 'Gerar Licenca';

$clientes = $pdo->query("SELECT id, razao_social, nome_fantasia FROM clientes ORDER BY razao_social")->fetchAll();
$planos = $pdo->query("SELECT * FROM planos WHERE ativo = 1 ORDER BY tipo_produto, preco")->fetchAll();

$clienteId = (int)($_GET['cliente_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        flash('danger', 'Token CSRF invalido.');
        redirect('form.php');
    }

    $quantidade = max(1, min(50, (int)($_POST['quantidade'] ?? 1)));
    $tipo = $_POST['tipo'] ?? 'mensal';
    $clienteIdPost = (int)($_POST['cliente_id'] ?? 0) ?: null;
    $planoIdPost = (int)($_POST['plano_id'] ?? 0) ?: null;
    $obs = trim($_POST['observacoes'] ?? '') ?: null;

    $geradas = [];
    $stmt = $pdo->prepare("INSERT INTO licencas (chave, cliente_id, plano_id, tipo, status, observacoes) VALUES (?,?,?,?,?,?)");

    for ($i = 0; $i < $quantidade; $i++) {
        $chave = generateLicenseKey($tipo);

        // Garantir unicidade
        $check = $pdo->prepare("SELECT COUNT(*) FROM licencas WHERE chave = ?");
        $check->execute([$chave]);
        while ($check->fetchColumn() > 0) {
            $chave = generateLicenseKey($tipo);
            $check->execute([$chave]);
        }

        $stmt->execute([$chave, $clienteIdPost, $planoIdPost, $tipo, 'disponivel', $obs]);
        $geradas[] = $chave;
    }

    $_SESSION['licencas_geradas'] = $geradas;
    flash('success', count($geradas) . ' licenca(s) gerada(s) com sucesso!');
    redirect('geradas.php');
}

include APP_PATH . '/includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-plus me-2"></i>Gerar Licenca</h2>
    <a href="index.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Voltar</a>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="table-card">
            <div class="card-header"><h5>Nova Licenca</h5></div>
            <div class="p-4">
                <form method="POST">
                    <?= csrfField() ?>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Tipo *</label>
                            <select name="tipo" class="form-select" id="tipo">
                                <option value="mensal">Mensal (30 dias)</option>
                                <option value="trimestral">Trimestral (90 dias)</option>
                                <option value="anual">Anual (365 dias)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Quantidade</label>
                            <input type="number" name="quantidade" class="form-control" value="1" min="1" max="50">
                            <small class="text-muted">Maximo 50 por vez</small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Cliente (opcional)</label>
                            <select name="cliente_id" class="form-select">
                                <option value="">Sem cliente (gerar avulsa)</option>
                                <?php foreach ($clientes as $c): ?>
                                    <option value="<?= $c['id'] ?>" <?= $clienteId == $c['id'] ? 'selected' : '' ?>>
                                        <?= e($c['nome_fantasia'] ?: $c['razao_social']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Plano (opcional)</label>
                            <select name="plano_id" class="form-select">
                                <option value="">Sem plano especifico</option>
                                <?php foreach ($planos as $p): ?>
                                    <option value="<?= $p['id'] ?>">
                                        <?= e($p['nome']) ?> - <?= formatMoney($p['preco']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Observacoes</label>
                            <textarea name="observacoes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="fas fa-key me-2"></i>Gerar Licenca(s)
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="stat-card">
            <h6 class="fw-bold mb-3">Como funciona</h6>
            <p class="small text-muted mb-2">
                <i class="fas fa-circle text-primary me-1" style="font-size:0.5rem;vertical-align:middle;"></i>
                A licenca e gerada com status <strong>Disponivel</strong>
            </p>
            <p class="small text-muted mb-2">
                <i class="fas fa-circle text-primary me-1" style="font-size:0.5rem;vertical-align:middle;"></i>
                Envie a chave ao cliente (email, WhatsApp)
            </p>
            <p class="small text-muted mb-2">
                <i class="fas fa-circle text-primary me-1" style="font-size:0.5rem;vertical-align:middle;"></i>
                O cliente digita no PDV Desktop
            </p>
            <p class="small text-muted mb-2">
                <i class="fas fa-circle text-primary me-1" style="font-size:0.5rem;vertical-align:middle;"></i>
                O PDV valida via API e ativa
            </p>
            <p class="small text-muted mb-0">
                <i class="fas fa-circle text-primary me-1" style="font-size:0.5rem;vertical-align:middle;"></i>
                Voce acompanha tudo aqui no painel
            </p>
        </div>
    </div>
</div>

<?php include APP_PATH . '/includes/footer.php'; ?>
