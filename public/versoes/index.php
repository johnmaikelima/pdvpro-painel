<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
requireLogin();

$pageTitle = 'Versoes';

$uploadDir = dirname(__DIR__, 2) . '/storage/uploads/';
$maxSize = 200 * 1024 * 1024; // 200MB

// Upload de nova versao
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        flash('danger', 'Token CSRF invalido.');
        redirect('index.php');
    }

    $acao = $_POST['acao'] ?? '';

    if ($acao === 'nova_versao') {
        $versao = trim($_POST['versao'] ?? '');
        $tipoProduto = $_POST['tipo_produto'] ?? 'desktop';
        $changelog = trim($_POST['changelog'] ?? '');
        $obrigatoria = (int)($_POST['obrigatoria'] ?? 0);

        if (empty($versao) || !preg_match('/^\d+\.\d+\.\d+$/', $versao)) {
            flash('danger', 'Versao invalida. Use formato X.Y.Z (ex: 1.2.0)');
            redirect('index.php');
        }

        // Verificar se versao ja existe
        $stmt = $pdo->prepare("SELECT id FROM versoes WHERE versao = ? AND tipo_produto = ?");
        $stmt->execute([$versao, $tipoProduto]);
        if ($stmt->fetch()) {
            flash('danger', "Versao {$versao} ja existe para {$tipoProduto}.");
            redirect('index.php');
        }

        // Processar upload
        $arquivoNome = null;
        $arquivoPath = null;
        $arquivoTamanho = 0;

        if (!empty($_FILES['arquivo']['name']) && $_FILES['arquivo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['arquivo'];

            if ($file['size'] > $maxSize) {
                flash('danger', 'Arquivo muito grande. Maximo: 200MB.');
                redirect('index.php');
            }

            // Validar extensao
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $extPermitidas = ['exe', 'msi', 'zip'];
            if (!in_array($ext, $extPermitidas)) {
                flash('danger', 'Tipo de arquivo nao permitido. Use: ' . implode(', ', $extPermitidas));
                redirect('index.php');
            }

            // Nome seguro: pdvpro-desktop-1.2.0.exe
            $arquivoNome = "kaixa-{$tipoProduto}-{$versao}.{$ext}";
            $destino = $uploadDir . $arquivoNome;

            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            if (!move_uploaded_file($file['tmp_name'], $destino)) {
                flash('danger', 'Erro ao salvar arquivo.');
                redirect('index.php');
            }

            $arquivoPath = $destino;
            $arquivoTamanho = $file['size'];
        }

        $pdo->prepare("INSERT INTO versoes (versao, tipo_produto, arquivo_nome, arquivo_path, arquivo_tamanho, changelog, obrigatoria) VALUES (?,?,?,?,?,?,?)")
            ->execute([$versao, $tipoProduto, $arquivoNome, $arquivoPath, $arquivoTamanho, $changelog, $obrigatoria]);

        flash('success', "Versao {$versao} publicada com sucesso!");
        redirect('index.php');
    }

    if ($acao === 'desativar') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE versoes SET ativa = 0 WHERE id = ?")->execute([$id]);
        flash('success', 'Versao desativada.');
        redirect('index.php');
    }

    if ($acao === 'ativar') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE versoes SET ativa = 1 WHERE id = ?")->execute([$id]);
        flash('success', 'Versao ativada.');
        redirect('index.php');
    }

    if ($acao === 'excluir') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT arquivo_path FROM versoes WHERE id = ?");
        $stmt->execute([$id]);
        $v = $stmt->fetch();
        if ($v && $v['arquivo_path'] && file_exists($v['arquivo_path'])) {
            unlink($v['arquivo_path']);
        }
        $pdo->prepare("DELETE FROM versoes WHERE id = ?")->execute([$id]);
        flash('success', 'Versao excluida.');
        redirect('index.php');
    }
}

// Listar versoes
$versoes = $pdo->query("SELECT * FROM versoes ORDER BY criado_em DESC")->fetchAll();

include APP_PATH . '/includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center">
    <h2><i class="fas fa-cloud-upload-alt me-2"></i>Versoes</h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#novaVersaoModal">
        <i class="fas fa-plus me-1"></i>Nova Versao
    </button>
</div>

<!-- Info -->
<div class="alert alert-info mb-4">
    <i class="fas fa-info-circle me-1"></i>
    Ao publicar uma nova versao, todos os clientes serao notificados automaticamente no PDV.
    A versao mais recente <strong>ativa</strong> sera oferecida para download.
</div>

<!-- Tabela -->
<div class="table-card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Versao</th>
                    <th>Produto</th>
                    <th>Arquivo</th>
                    <th>Changelog</th>
                    <th>Downloads</th>
                    <th>Status</th>
                    <th>Data</th>
                    <th>Acoes</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($versoes)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Nenhuma versao publicada.</td></tr>
                <?php endif; ?>
                <?php foreach ($versoes as $v): ?>
                    <tr>
                        <td>
                            <strong class="font-monospace">v<?= e($v['versao']) ?></strong>
                            <?php if ($v['obrigatoria']): ?>
                                <br><span class="badge bg-danger">Obrigatoria</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?= $v['tipo_produto'] === 'desktop' ? 'primary' : 'info' ?>">
                                <?= e(ucfirst($v['tipo_produto'])) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($v['arquivo_nome']): ?>
                                <i class="fas fa-file me-1"></i><?= e($v['arquivo_nome']) ?>
                                <br><small class="text-muted"><?= number_format($v['arquivo_tamanho'] / 1024 / 1024, 1) ?> MB</small>
                            <?php else: ?>
                                <span class="text-muted">Sem arquivo</span>
                            <?php endif; ?>
                        </td>
                        <td style="max-width: 300px;">
                            <?php if ($v['changelog']): ?>
                                <small><?= nl2br(e(mb_substr($v['changelog'], 0, 200))) ?><?= mb_strlen($v['changelog']) > 200 ? '...' : '' ?></small>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?= number_format($v['downloads']) ?></td>
                        <td>
                            <?php if ($v['ativa']): ?>
                                <span class="badge bg-success">Ativa</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inativa</span>
                            <?php endif; ?>
                        </td>
                        <td><?= formatDateTime($v['criado_em']) ?></td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <?php if ($v['arquivo_nome']): ?>
                                    <a href="/versoes/download.php?id=<?= $v['id'] ?>&admin=1" class="btn btn-outline-primary" title="Baixar">
                                        <i class="fas fa-download"></i>
                                    </a>
                                <?php endif; ?>
                                <form method="POST" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="id" value="<?= $v['id'] ?>">
                                    <?php if ($v['ativa']): ?>
                                        <button type="submit" name="acao" value="desativar" class="btn btn-outline-warning" title="Desativar">
                                            <i class="fas fa-pause"></i>
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" name="acao" value="ativar" class="btn btn-outline-success" title="Ativar">
                                            <i class="fas fa-play"></i>
                                        </button>
                                    <?php endif; ?>
                                    <button type="submit" name="acao" value="excluir" class="btn btn-outline-danger" title="Excluir" onclick="return confirm('Excluir esta versao?')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Nova Versao -->
<div class="modal fade" id="novaVersaoModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="acao" value="nova_versao">

                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-cloud-upload-alt me-2"></i>Publicar Nova Versao</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Versao *</label>
                            <input type="text" name="versao" class="form-control" placeholder="1.2.0" required pattern="\d+\.\d+\.\d+">
                            <small class="text-muted">Formato: X.Y.Z</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Produto</label>
                            <select name="tipo_produto" class="form-select">
                                <option value="desktop">Desktop</option>
                                <option value="saas">SaaS</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Tipo</label>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" name="obrigatoria" value="1" id="obrigatoria">
                                <label class="form-check-label" for="obrigatoria">Atualizacao obrigatoria</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Arquivo do Instalador</label>
                            <input type="file" name="arquivo" class="form-control" accept=".exe,.msi,.zip">
                            <small class="text-muted">Maximo 200MB. Formatos: .exe, .msi, .zip</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Changelog (o que mudou)</label>
                            <textarea name="changelog" class="form-control" rows="5" placeholder="- Correcao de bug X&#10;- Nova funcionalidade Y&#10;- Melhoria em Z"></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-upload me-1"></i>Publicar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include APP_PATH . '/includes/footer.php'; ?>
