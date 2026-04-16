<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT c.*, p.nome as plano_nome, p.limite_nfce FROM clientes c LEFT JOIN planos p ON c.plano_id = p.id WHERE c.id = ?");
$stmt->execute([$id]);
$cliente = $stmt->fetch();

if (!$cliente) {
    flash('danger', 'Cliente nao encontrado.');
    redirect('index.php');
}

$pageTitle = $cliente['nome_fantasia'] ?: $cliente['razao_social'];

// Alterar senha do SaaS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'alterar_senha_saas') {
    if (!verifyCsrf()) {
        flash('danger', 'Token CSRF inválido.');
        redirect("view.php?id={$id}");
    }

    $novaSenha = $_POST['nova_senha'] ?? '';
    if (strlen($novaSenha) < 8) {
        flash('danger', 'Senha deve ter no mínimo 8 caracteres.');
        redirect("view.php?id={$id}");
    }

    // Buscar licença SaaS do cliente
    $stmtLic = $pdo->prepare("SELECT chave FROM licencas WHERE cliente_id = ? AND chave LIKE 'S%' ORDER BY id DESC LIMIT 1");
    $stmtLic->execute([$id]);
    $licenca = $stmtLic->fetch();

    if (!$licenca || empty(SAAS_URL)) {
        flash('danger', 'Não foi possível alterar a senha. Licença SaaS não encontrada ou SAAS_URL não configurada.');
        redirect("view.php?id={$id}");
    }

    $url = rtrim(SAAS_URL, '/') . '/api/alterar-senha.php';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'licenca_chave' => $licenca['chave'],
            'nova_senha' => $novaSenha,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Api-Secret: ' . API_SECRET,
        ],
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        flash('success', 'Senha do SaaS alterada com sucesso!');
    } else {
        $data = json_decode($resp, true);
        flash('danger', 'Erro ao alterar senha: ' . ($data['msg'] ?? 'HTTP ' . $httpCode));
    }
    redirect("view.php?id={$id}");
}

// Acessar SaaS do cliente (impersonate)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'acessar_saas') {
    if (!verifyCsrf()) {
        flash('danger', 'Token CSRF inválido.');
        redirect("view.php?id={$id}");
    }

    // Buscar licença SaaS do cliente
    $stmtLic = $pdo->prepare("SELECT chave FROM licencas WHERE cliente_id = ? AND chave LIKE 'S%' AND status = 'ativa' ORDER BY id DESC LIMIT 1");
    $stmtLic->execute([$id]);
    $licenca = $stmtLic->fetch();

    if (!$licenca || empty(SAAS_URL)) {
        flash('danger', 'Licença SaaS não encontrada ou SAAS_URL não configurada.');
        redirect("view.php?id={$id}");
    }

    $url = rtrim(SAAS_URL, '/') . '/api/impersonate.php';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'api_secret' => API_SECRET,
            'licenca_chave' => $licenca['chave'],
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($resp, true);
    if ($httpCode === 200 && !empty($data['ok']) && !empty($data['url'])) {
        header('Location: ' . $data['url']);
        exit;
    } else {
        flash('danger', 'Erro ao acessar SaaS: ' . ($data['mensagem'] ?? 'HTTP ' . $httpCode));
        redirect("view.php?id={$id}");
    }
}

// Excluir cliente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir') {
    if (!verifyCsrf()) {
        flash('danger', 'Token CSRF inválido.');
        redirect("view.php?id={$id}");
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM pagamentos WHERE cliente_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM licencas WHERE cliente_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM api_logs WHERE cliente_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM clientes WHERE id = ?")->execute([$id]);
        $pdo->commit();
        flash('success', "Cliente \"{$pageTitle}\" excluído com sucesso.");
        redirect('index.php');
    } catch (\PDOException $e) {
        $pdo->rollBack();
        flash('danger', 'Erro ao excluir cliente.');
        redirect("view.php?id={$id}");
    }
}

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
        <form method="POST" class="d-inline" target="_blank">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="acessar_saas">
            <button type="submit" class="btn btn-outline-success" title="Abrir o SaaS deste cliente em nova aba"><i class="fas fa-external-link-alt me-1"></i>Acessar SaaS</button>
        </form>
        <a href="form.php?id=<?= $id ?>" class="btn btn-outline-primary ms-1"><i class="fas fa-edit me-1"></i>Editar</a>
        <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Tem certeza que deseja excluir este cliente? Todas as licenças e pagamentos serão removidos.')">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="excluir">
            <button type="submit" class="btn btn-outline-danger"><i class="fas fa-trash me-1"></i>Excluir</button>
        </form>
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
        <div class="stat-card mb-3">
            <div class="stat-label">API Token</div>
            <div class="d-flex align-items-center gap-2 mt-1">
                <code class="small" id="tokenDisplay"><?= e(substr($cliente['api_token'] ?? '', 0, 16)) ?>...</code>
                <button class="btn btn-sm btn-outline-secondary" onclick="copyToClipboard('<?= e($cliente['api_token']) ?>', this)">
                    <i class="fas fa-copy"></i>
                </button>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Acesso SaaS</div>
            <div class="mt-1">
                <?php if (!empty($cliente['login_saas'])): ?>
                    <div class="mb-2">
                        <small class="text-muted">Login:</small>
                        <strong><?= e($cliente['login_saas']) ?></strong>
                    </div>
                <?php else: ?>
                    <div class="mb-2 text-muted small">Login não registrado</div>
                <?php endif; ?>
                <button class="btn btn-sm btn-outline-warning w-100" data-bs-toggle="modal" data-bs-target="#modalAlterarSenha">
                    <i class="fas fa-key me-1"></i>Alterar Senha
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Alterar Senha SaaS -->
<div class="modal fade" id="modalAlterarSenha" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="acao" value="alterar_senha_saas">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-key me-2"></i>Alterar Senha SaaS</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if (!empty($cliente['login_saas'])): ?>
                        <div class="mb-3">
                            <label class="form-label">Login</label>
                            <input type="text" class="form-control" value="<?= e($cliente['login_saas']) ?>" readonly>
                        </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">Nova Senha</label>
                        <input type="password" name="nova_senha" class="form-control" required minlength="8" placeholder="Minimo 8 caracteres">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning"><i class="fas fa-save me-1"></i>Alterar</button>
                </div>
            </form>
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
