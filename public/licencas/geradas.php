<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$pageTitle = 'Licencas Geradas';
$licencas = $_SESSION['licencas_geradas'] ?? [];

if (empty($licencas)) {
    redirect('index.php');
}

// Limpar da sessao
unset($_SESSION['licencas_geradas']);

include APP_PATH . '/includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-check-circle text-success me-2"></i>Licencas Geradas</h2>
    <a href="index.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Ver Todas</a>
</div>

<div class="table-card">
    <div class="card-header">
        <h5><?= count($licencas) ?> licenca(s) gerada(s)</h5>
        <button class="btn btn-sm btn-outline-primary" onclick="copyAll()">
            <i class="fas fa-copy me-1"></i>Copiar Todas
        </button>
    </div>

    <div class="p-4">
        <?php foreach ($licencas as $i => $chave): ?>
        <div class="d-flex align-items-center justify-content-between p-3 mb-2 bg-light rounded">
            <span class="license-key" style="font-size:1.3rem;"><?= e($chave) ?></span>
            <button class="btn btn-outline-secondary" onclick="copyToClipboard('<?= e($chave) ?>', this)">
                <i class="fas fa-copy"></i> Copiar
            </button>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="text-center mt-3">
    <a href="form.php" class="btn btn-primary"><i class="fas fa-plus me-1"></i>Gerar Mais</a>
</div>

<?php
$allKeys = implode("\n", $licencas);
$pageScripts = <<<HTML
<script>
function copyAll() {
    const keys = `{$allKeys}`;
    navigator.clipboard.writeText(keys).then(() => {
        alert('Todas as chaves copiadas!');
    });
}
</script>
HTML;
include APP_PATH . '/includes/footer.php';
?>
