<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
$cliente = null;

if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
    $stmt->execute([$id]);
    $cliente = $stmt->fetch();
    if (!$cliente) {
        flash('danger', 'Cliente nao encontrado.');
        redirect('index.php');
    }
}

$pageTitle = $cliente ? 'Editar Cliente' : 'Novo Cliente';

// Planos
$planos = $pdo->query("SELECT * FROM planos WHERE ativo = 1 ORDER BY tipo_produto, preco")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        flash('danger', 'Token CSRF invalido.');
        redirect($_SERVER['REQUEST_URI']);
    }

    $dados = [
        'razao_social' => trim($_POST['razao_social'] ?? ''),
        'nome_fantasia' => trim($_POST['nome_fantasia'] ?? '') ?: null,
        'cnpj' => trim($_POST['cnpj'] ?? '') ?: null,
        'cpf' => trim($_POST['cpf'] ?? '') ?: null,
        'email' => trim($_POST['email'] ?? '') ?: null,
        'telefone' => trim($_POST['telefone'] ?? '') ?: null,
        'whatsapp' => trim($_POST['whatsapp'] ?? '') ?: null,
        'contato_nome' => trim($_POST['contato_nome'] ?? '') ?: null,
        'cidade' => trim($_POST['cidade'] ?? '') ?: null,
        'uf' => trim($_POST['uf'] ?? '') ?: null,
        'plano_id' => (int)($_POST['plano_id'] ?? 0) ?: null,
        'status' => $_POST['status'] ?? 'trial',
        'observacoes' => trim($_POST['observacoes'] ?? '') ?: null,
    ];

    if (empty($dados['razao_social'])) {
        flash('danger', 'Razao Social e obrigatoria.');
        redirect($_SERVER['REQUEST_URI']);
    }

    if ($cliente) {
        $sql = "UPDATE clientes SET razao_social=?, nome_fantasia=?, cnpj=?, cpf=?, email=?, telefone=?, whatsapp=?, contato_nome=?, cidade=?, uf=?, plano_id=?, status=?, observacoes=? WHERE id=?";
        $pdo->prepare($sql)->execute([
            $dados['razao_social'], $dados['nome_fantasia'], $dados['cnpj'], $dados['cpf'],
            $dados['email'], $dados['telefone'], $dados['whatsapp'], $dados['contato_nome'],
            $dados['cidade'], $dados['uf'], $dados['plano_id'], $dados['status'],
            $dados['observacoes'], $id
        ]);
        flash('success', 'Cliente atualizado com sucesso!');
    } else {
        $apiToken = generateApiToken();
        $sql = "INSERT INTO clientes (razao_social, nome_fantasia, cnpj, cpf, email, telefone, whatsapp, contato_nome, cidade, uf, plano_id, status, api_token, observacoes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $pdo->prepare($sql)->execute([
            $dados['razao_social'], $dados['nome_fantasia'], $dados['cnpj'], $dados['cpf'],
            $dados['email'], $dados['telefone'], $dados['whatsapp'], $dados['contato_nome'],
            $dados['cidade'], $dados['uf'], $dados['plano_id'], $dados['status'],
            $apiToken, $dados['observacoes']
        ]);
        flash('success', 'Cliente cadastrado com sucesso!');
    }

    redirect('index.php');
}

$ufs = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];

include APP_PATH . '/includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-<?= $cliente ? 'edit' : 'plus' ?> me-2"></i><?= e($pageTitle) ?></h2>
    <a href="index.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Voltar</a>
</div>

<div class="table-card">
    <div class="card-header"><h5>Dados do Cliente</h5></div>
    <div class="p-4">
        <form method="POST">
            <?= csrfField() ?>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Razao Social *</label>
                    <input type="text" name="razao_social" class="form-control" required
                           value="<?= e($cliente['razao_social'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Nome Fantasia</label>
                    <input type="text" name="nome_fantasia" class="form-control"
                           value="<?= e($cliente['nome_fantasia'] ?? '') ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">CNPJ</label>
                    <input type="text" name="cnpj" class="form-control" placeholder="00.000.000/0000-00"
                           value="<?= e($cliente['cnpj'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">CPF</label>
                    <input type="text" name="cpf" class="form-control" placeholder="000.000.000-00"
                           value="<?= e($cliente['cpf'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control"
                           value="<?= e($cliente['email'] ?? '') ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Telefone</label>
                    <input type="text" name="telefone" class="form-control"
                           value="<?= e($cliente['telefone'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">WhatsApp</label>
                    <input type="text" name="whatsapp" class="form-control" placeholder="(00) 00000-0000"
                           value="<?= e($cliente['whatsapp'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Nome do Contato</label>
                    <input type="text" name="contato_nome" class="form-control"
                           value="<?= e($cliente['contato_nome'] ?? '') ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Cidade</label>
                    <input type="text" name="cidade" class="form-control"
                           value="<?= e($cliente['cidade'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">UF</label>
                    <select name="uf" class="form-select">
                        <option value="">-</option>
                        <?php foreach ($ufs as $uf): ?>
                            <option value="<?= $uf ?>" <?= ($cliente['uf'] ?? '') === $uf ? 'selected' : '' ?>><?= $uf ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Plano</label>
                    <select name="plano_id" class="form-select">
                        <option value="">Sem plano</option>
                        <?php foreach ($planos as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= ($cliente['plano_id'] ?? '') == $p['id'] ? 'selected' : '' ?>>
                                <?= e($p['nome']) ?> (<?= e($p['tipo_produto']) ?>) - <?= formatMoney($p['preco']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="trial" <?= ($cliente['status'] ?? 'trial') === 'trial' ? 'selected' : '' ?>>Trial</option>
                        <option value="ativo" <?= ($cliente['status'] ?? '') === 'ativo' ? 'selected' : '' ?>>Ativo</option>
                        <option value="inativo" <?= ($cliente['status'] ?? '') === 'inativo' ? 'selected' : '' ?>>Inativo</option>
                        <option value="inadimplente" <?= ($cliente['status'] ?? '') === 'inadimplente' ? 'selected' : '' ?>>Inadimplente</option>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label">Observacoes</label>
                    <textarea name="observacoes" class="form-control" rows="3"><?= e($cliente['observacoes'] ?? '') ?></textarea>
                </div>
            </div>

            <?php if ($cliente && $cliente['api_token']): ?>
            <div class="mt-3 p-3 bg-light rounded">
                <label class="form-label fw-bold">API Token</label>
                <div class="input-group">
                    <input type="text" class="form-control font-monospace" value="<?= e($cliente['api_token']) ?>" readonly id="apiToken">
                    <button type="button" class="btn btn-outline-secondary" onclick="copyToClipboard(document.getElementById('apiToken').value, this)">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>
                <small class="text-muted">Este token e usado pelo PDV Desktop para se autenticar na API.</small>
            </div>
            <?php endif; ?>

            <div class="mt-4">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Salvar</button>
                <a href="index.php" class="btn btn-outline-secondary ms-2">Cancelar</a>
            </div>
        </form>
    </div>
</div>

<?php include APP_PATH . '/includes/footer.php'; ?>
