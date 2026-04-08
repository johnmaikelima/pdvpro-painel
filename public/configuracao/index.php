<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
requireLogin();

$pageTitle = 'Configuracoes';

// Buscar admin atual
$stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
$stmt->execute([currentUser()['id']]);
$admin = $stmt->fetch();

// Carregar configs
$smtp = getAllConfigs($pdo, 'smtp_');
$asaas = getAllConfigs($pdo, 'asaas_');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        flash('danger', 'Token CSRF invalido.');
        redirect('index.php');
    }

    $acao = $_POST['acao'] ?? '';

    if ($acao === 'perfil') {
        $nome = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if (empty($nome) || empty($email)) {
            flash('danger', 'Nome e email sao obrigatorios.');
            redirect('index.php');
        }

        $pdo->prepare("UPDATE admins SET nome = ?, email = ? WHERE id = ?")
            ->execute([$nome, $email, $admin['id']]);

        $_SESSION['admin_user']['nome'] = $nome;
        $_SESSION['admin_user']['email'] = $email;
        flash('success', 'Perfil atualizado!');
    }

    if ($acao === 'senha') {
        $senhaAtual = $_POST['senha_atual'] ?? '';
        $novaSenha = $_POST['nova_senha'] ?? '';
        $confirmar = $_POST['confirmar_senha'] ?? '';

        if (!password_verify($senhaAtual, $admin['senha'])) {
            flash('danger', 'Senha atual incorreta.');
            redirect('index.php');
        }

        if (strlen($novaSenha) < 6) {
            flash('danger', 'Nova senha deve ter no minimo 6 caracteres.');
            redirect('index.php');
        }

        if ($novaSenha !== $confirmar) {
            flash('danger', 'Senhas nao conferem.');
            redirect('index.php');
        }

        $hash = password_hash($novaSenha, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE admins SET senha = ? WHERE id = ?")->execute([$hash, $admin['id']]);
        flash('success', 'Senha alterada com sucesso!');
    }

    if ($acao === 'smtp') {
        setConfig($pdo, 'smtp_host', trim($_POST['smtp_host'] ?? ''));
        setConfig($pdo, 'smtp_port', trim($_POST['smtp_port'] ?? '587'));
        setConfig($pdo, 'smtp_user', trim($_POST['smtp_user'] ?? ''));
        $smtpPass = $_POST['smtp_pass'] ?? '';
        if (!empty($smtpPass)) {
            setConfig($pdo, 'smtp_pass', $smtpPass);
        }
        setConfig($pdo, 'smtp_from_name', trim($_POST['smtp_from_name'] ?? ''));
        setConfig($pdo, 'smtp_from_email', trim($_POST['smtp_from_email'] ?? ''));
        setConfig($pdo, 'smtp_encryption', $_POST['smtp_encryption'] ?? 'tls');
        flash('success', 'Configuracoes de email salvas!');
    }

    if ($acao === 'asaas') {
        setConfig($pdo, 'asaas_ambiente', $_POST['asaas_ambiente'] ?? 'sandbox');
        $apiKey = trim($_POST['asaas_api_key'] ?? '');
        if (!empty($apiKey)) {
            setConfig($pdo, 'asaas_api_key', $apiKey);
        }
        setConfig($pdo, 'asaas_webhook_secret', trim($_POST['asaas_webhook_secret'] ?? ''));
        flash('success', 'Configuracoes do Asaas salvas!');
    }

    if ($acao === 'testar_smtp') {
        $emailTeste = trim($_POST['email_teste'] ?? $admin['email']);
        try {
            $host = getConfig($pdo, 'smtp_host');
            $port = (int)getConfig($pdo, 'smtp_port', '587');
            $user = getConfig($pdo, 'smtp_user');
            $pass = getConfig($pdo, 'smtp_pass');
            $fromName = getConfig($pdo, 'smtp_from_name', 'PDV Pro');
            $fromEmail = getConfig($pdo, 'smtp_from_email');
            $encryption = getConfig($pdo, 'smtp_encryption', 'tls');

            if (empty($host) || empty($user) || empty($pass)) {
                throw new Exception('Configure host, usuario e senha SMTP primeiro.');
            }

            $subject = 'Teste PDV Pro - Email Configurado';
            $body = '<h2>PDV Pro</h2><p>Se voce recebeu este email, a configuracao SMTP esta funcionando corretamente!</p><p><small>Enviado em ' . date('d/m/Y H:i:s') . '</small></p>';

            sendMail($pdo, $emailTeste, $subject, $body);
            flash('success', "Email de teste enviado para {$emailTeste}!");
        } catch (Exception $e) {
            flash('danger', 'Erro ao enviar email: ' . $e->getMessage());
        }
    }

    if ($acao === 'testar_asaas') {
        try {
            require_once APP_PATH . '/includes/asaas.php';
            $asaasApi = new Asaas($pdo);
            $balance = $asaasApi->testConnection();
            $saldo = number_format($balance['balance'] ?? 0, 2, ',', '.');
            flash('success', "Conexao com Asaas OK! Saldo: R$ {$saldo}");
        } catch (Exception $e) {
            flash('danger', 'Erro Asaas: ' . $e->getMessage());
        }
    }

    redirect('index.php');
}

// URL da API
$apiUrl = APP_URL . '/api/index.php';

include APP_PATH . '/includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-cog me-2"></i>Configuracoes</h2>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item">
        <a class="nav-link active" data-bs-toggle="tab" href="#tab-perfil"><i class="fas fa-user me-1"></i>Perfil</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-bs-toggle="tab" href="#tab-email"><i class="fas fa-envelope me-1"></i>Email (SMTP)</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-bs-toggle="tab" href="#tab-asaas"><i class="fas fa-credit-card me-1"></i>Pagamentos (Asaas)</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-bs-toggle="tab" href="#tab-api"><i class="fas fa-plug me-1"></i>API</a>
    </li>
</ul>

<div class="tab-content">

    <!-- TAB PERFIL -->
    <div class="tab-pane fade show active" id="tab-perfil">
        <div class="row g-4">
            <div class="col-md-6">
                <div class="table-card">
                    <div class="card-header"><h5><i class="fas fa-user me-2"></i>Meu Perfil</h5></div>
                    <div class="p-4">
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="acao" value="perfil">
                            <div class="mb-3">
                                <label class="form-label">Nome</label>
                                <input type="text" name="nome" class="form-control" value="<?= e($admin['nome']) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" value="<?= e($admin['email']) ?>" required>
                            </div>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Salvar</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="table-card">
                    <div class="card-header"><h5><i class="fas fa-lock me-2"></i>Alterar Senha</h5></div>
                    <div class="p-4">
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="acao" value="senha">
                            <div class="mb-3">
                                <label class="form-label">Senha Atual</label>
                                <input type="password" name="senha_atual" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Nova Senha</label>
                                <input type="password" name="nova_senha" class="form-control" required minlength="6">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Confirmar Nova Senha</label>
                                <input type="password" name="confirmar_senha" class="form-control" required>
                            </div>
                            <button type="submit" class="btn btn-warning"><i class="fas fa-key me-1"></i>Alterar Senha</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- TAB EMAIL -->
    <div class="tab-pane fade" id="tab-email">
        <div class="row g-4">
            <div class="col-md-8">
                <div class="table-card">
                    <div class="card-header"><h5><i class="fas fa-envelope me-2"></i>Configuracao SMTP</h5></div>
                    <div class="p-4">
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="acao" value="smtp">

                            <div class="row g-3">
                                <div class="col-md-8">
                                    <label class="form-label">Servidor SMTP</label>
                                    <input type="text" name="smtp_host" class="form-control" value="<?= e($smtp['smtp_host'] ?? '') ?>" placeholder="smtp.gmail.com">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Porta</label>
                                    <input type="number" name="smtp_port" class="form-control" value="<?= e($smtp['smtp_port'] ?? '587') ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Usuario (email)</label>
                                    <input type="text" name="smtp_user" class="form-control" value="<?= e($smtp['smtp_user'] ?? '') ?>" placeholder="seu@email.com">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Senha</label>
                                    <input type="password" name="smtp_pass" class="form-control" placeholder="<?= !empty($smtp['smtp_pass']) ? '••••••••' : '' ?>">
                                    <?php if (!empty($smtp['smtp_pass'])): ?>
                                        <small class="text-muted">Deixe vazio para manter a senha atual</small>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Criptografia</label>
                                    <select name="smtp_encryption" class="form-select">
                                        <option value="tls" <?= ($smtp['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS</option>
                                        <option value="ssl" <?= ($smtp['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                                        <option value="" <?= empty($smtp['smtp_encryption'] ?? 'tls') ? 'selected' : '' ?>>Nenhuma</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Nome do Remetente</label>
                                    <input type="text" name="smtp_from_name" class="form-control" value="<?= e($smtp['smtp_from_name'] ?? 'PDV Pro') ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Email do Remetente</label>
                                    <input type="email" name="smtp_from_email" class="form-control" value="<?= e($smtp['smtp_from_email'] ?? '') ?>" placeholder="noreply@seudominio.com">
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary mt-3"><i class="fas fa-save me-1"></i>Salvar SMTP</button>
                        </form>

                        <hr>

                        <form method="POST" class="d-flex align-items-end gap-2">
                            <?= csrfField() ?>
                            <input type="hidden" name="acao" value="testar_smtp">
                            <div class="flex-grow-1">
                                <label class="form-label">Enviar email de teste para:</label>
                                <input type="email" name="email_teste" class="form-control" value="<?= e($admin['email']) ?>">
                            </div>
                            <button type="submit" class="btn btn-outline-success"><i class="fas fa-paper-plane me-1"></i>Testar</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="table-card">
                    <div class="card-header"><h5><i class="fas fa-info-circle me-2"></i>Dicas</h5></div>
                    <div class="p-4">
                        <h6 class="fw-bold">Gmail</h6>
                        <ul class="small mb-3">
                            <li>Host: <code>smtp.gmail.com</code></li>
                            <li>Porta: <code>587</code></li>
                            <li>Criptografia: TLS</li>
                            <li>Use <a href="https://myaccount.google.com/apppasswords" target="_blank">Senha de app</a></li>
                        </ul>
                        <h6 class="fw-bold">Outlook/Hotmail</h6>
                        <ul class="small mb-3">
                            <li>Host: <code>smtp-mail.outlook.com</code></li>
                            <li>Porta: <code>587</code></li>
                            <li>Criptografia: TLS</li>
                        </ul>
                        <h6 class="fw-bold">Uso</h6>
                        <p class="small text-muted mb-0">O email sera usado para enviar links de pagamento e notificacoes para seus clientes.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- TAB ASAAS -->
    <div class="tab-pane fade" id="tab-asaas">
        <div class="row g-4">
            <div class="col-md-8">
                <div class="table-card">
                    <div class="card-header"><h5><i class="fas fa-credit-card me-2"></i>Gateway de Pagamento - Asaas</h5></div>
                    <div class="p-4">
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="acao" value="asaas">

                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Ambiente</label>
                                    <select name="asaas_ambiente" class="form-select">
                                        <option value="sandbox" <?= ($asaas['asaas_ambiente'] ?? 'sandbox') === 'sandbox' ? 'selected' : '' ?>>Sandbox (Testes)</option>
                                        <option value="producao" <?= ($asaas['asaas_ambiente'] ?? '') === 'producao' ? 'selected' : '' ?>>Producao</option>
                                    </select>
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label">API Key</label>
                                    <input type="password" name="asaas_api_key" class="form-control" placeholder="<?= !empty($asaas['asaas_api_key']) ? '••••••••••••••••' : '$aact_...' ?>">
                                    <?php if (!empty($asaas['asaas_api_key'])): ?>
                                        <small class="text-success"><i class="fas fa-check-circle"></i> Chave configurada. Deixe vazio para manter.</small>
                                    <?php endif; ?>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Webhook Secret (opcional)</label>
                                    <input type="text" name="asaas_webhook_secret" class="form-control" value="<?= e($asaas['asaas_webhook_secret'] ?? '') ?>" placeholder="Token para validar webhooks">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">URL do Webhook</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control font-monospace" value="<?= e(APP_URL) ?>/api/index.php?action=webhook_asaas" readonly id="webhookUrl">
                                        <button type="button" class="btn btn-outline-secondary" onclick="copyToClipboard(document.getElementById('webhookUrl').value, this)">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted">Configure esta URL no painel do Asaas em Configuracoes > Webhooks</small>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary mt-3"><i class="fas fa-save me-1"></i>Salvar Asaas</button>
                        </form>

                        <hr>

                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="acao" value="testar_asaas">
                            <button type="submit" class="btn btn-outline-success"><i class="fas fa-plug me-1"></i>Testar Conexao</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="table-card">
                    <div class="card-header"><h5><i class="fas fa-info-circle me-2"></i>Sobre o Asaas</h5></div>
                    <div class="p-4">
                        <p class="small">O <strong>Asaas</strong> e um gateway de pagamento brasileiro que suporta:</p>
                        <ul class="small">
                            <li><strong>PIX</strong> - Pagamento instantaneo</li>
                            <li><strong>Boleto</strong> - Boleto bancario</li>
                            <li><strong>Cartao</strong> - Credito</li>
                        </ul>
                        <p class="small mb-2"><strong>Como funciona:</strong></p>
                        <ol class="small">
                            <li>Cliente escolhe upgrade no PDV</li>
                            <li>Sistema gera cobranca no Asaas</li>
                            <li>Cliente paga (PIX/Boleto/Cartao)</li>
                            <li>Webhook confirma pagamento</li>
                            <li>Licenca ativada automaticamente</li>
                        </ol>
                        <a href="https://www.asaas.com" target="_blank" class="btn btn-sm btn-outline-primary w-100 mt-2">
                            <i class="fas fa-external-link-alt me-1"></i>Acessar Asaas
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- TAB API -->
    <div class="tab-pane fade" id="tab-api">
        <div class="table-card">
            <div class="card-header"><h5><i class="fas fa-plug me-2"></i>Informacoes da API</h5></div>
            <div class="p-4">
                <p class="mb-3">A API e usada pelo PDV Desktop para validar licencas. Configure o endpoint no PDV do cliente.</p>

                <div class="mb-3">
                    <label class="form-label fw-bold">URL Base da API</label>
                    <div class="input-group">
                        <input type="text" class="form-control font-monospace" value="<?= e($apiUrl) ?>" readonly id="apiUrlField">
                        <button class="btn btn-outline-secondary" onclick="copyToClipboard(document.getElementById('apiUrlField').value, this)">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                </div>

                <h6 class="fw-bold mt-4">Endpoints Disponiveis</h6>
                <table class="table table-sm">
                    <thead>
                        <tr><th>Metodo</th><th>Endpoint</th><th>Descricao</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><span class="badge bg-success">POST</span></td>
                            <td><code>?action=registrar</code></td>
                            <td>Registrar novo cliente + licenca free</td>
                        </tr>
                        <tr>
                            <td><span class="badge bg-success">POST</span></td>
                            <td><code>?action=validar</code></td>
                            <td>Validar licenca (chave + hardware_id)</td>
                        </tr>
                        <tr>
                            <td><span class="badge bg-success">POST</span></td>
                            <td><code>?action=ativar</code></td>
                            <td>Ativar licenca na maquina</td>
                        </tr>
                        <tr>
                            <td><span class="badge bg-success">POST</span></td>
                            <td><code>?action=reportar_nfce</code></td>
                            <td>Reportar NFC-e emitidas</td>
                        </tr>
                        <tr>
                            <td><span class="badge bg-primary">GET</span></td>
                            <td><code>?action=checar_atualizacao</code></td>
                            <td>Verificar se ha atualizacao</td>
                        </tr>
                        <tr>
                            <td><span class="badge bg-primary">GET</span></td>
                            <td><code>?action=status</code></td>
                            <td>Status da API</td>
                        </tr>
                    </tbody>
                </table>

                <button class="btn btn-outline-primary mt-2" onclick="testarApi()">
                    <i class="fas fa-play me-1"></i>Testar API
                </button>
                <span id="apiResult" class="ms-2"></span>
            </div>
        </div>
    </div>

</div>

<?php
$pageScripts = <<<HTML
<script>
function testarApi() {
    const result = document.getElementById('apiResult');
    result.innerHTML = '<span class="text-muted">Testando...</span>';
    fetch('{$apiUrl}?action=status')
        .then(r => r.json())
        .then(data => {
            result.innerHTML = '<span class="text-success"><i class="fas fa-check-circle"></i> API Online - v' + data.version + '</span>';
        })
        .catch(err => {
            result.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle"></i> API Offline</span>';
        });
}

// Manter aba ativa apos reload
const hash = window.location.hash;
if (hash) {
    const tab = document.querySelector('a[href="' + hash + '"]');
    if (tab) new bootstrap.Tab(tab).show();
}
document.querySelectorAll('a[data-bs-toggle="tab"]').forEach(t => {
    t.addEventListener('shown.bs.tab', e => history.replaceState(null, null, e.target.getAttribute('href')));
});
</script>
HTML;
include APP_PATH . '/includes/footer.php';
?>
