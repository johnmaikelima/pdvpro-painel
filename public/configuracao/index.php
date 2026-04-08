<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$pageTitle = 'Configuracoes';

// Buscar admin atual
$stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
$stmt->execute([currentUser()['id']]);
$admin = $stmt->fetch();

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

    redirect('index.php');
}

// URL da API
$apiUrl = APP_URL . '/api/index.php';

include APP_PATH . '/includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-cog me-2"></i>Configuracoes</h2>
</div>

<div class="row g-4">
    <!-- Perfil -->
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

    <!-- Senha -->
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

    <!-- Info da API -->
    <div class="col-12">
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
</script>
HTML;
include APP_PATH . '/includes/footer.php';
?>
