<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
requireLogin();

$pageTitle = 'Gerar Licenca';

$clientes = $pdo->query("SELECT id, razao_social, nome_fantasia FROM clientes ORDER BY razao_social")->fetchAll();
$planos = $pdo->query("SELECT * FROM planos WHERE ativo = 1 ORDER BY tipo_produto, preco")->fetchAll();

$clienteId = (int)($_GET['cliente_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        flash('danger', 'Token CSRF invalido.');
        redirect('form.php');
    }

    $tipoProduto = $_POST['tipo_produto'] ?? 'desktop';
    if (!in_array($tipoProduto, ['desktop', 'saas'], true)) {
        $tipoProduto = 'desktop';
    }

    $quantidade = max(1, min(50, (int)($_POST['quantidade'] ?? 1)));
    $tipo = $_POST['tipo'] ?? 'mensal';
    $clienteIdPost = (int)($_POST['cliente_id'] ?? 0) ?: null;
    $planoIdPost = (int)($_POST['plano_id'] ?? 0) ?: null;
    $statusInicial = $_POST['status_inicial'] ?? 'disponivel';
    if (!in_array($statusInicial, ['disponivel', 'ativa'], true)) {
        $statusInicial = 'disponivel';
    }
    $obs = trim($_POST['observacoes'] ?? '') ?: null;

    // Para SaaS, exigir cliente vinculado quando status=ativa (sem cliente nao tem como o tenant SaaS apontar)
    if ($tipoProduto === 'saas' && $statusInicial === 'ativa' && !$clienteIdPost) {
        flash('warning', 'Para gerar uma licenca SaaS ja ativa, selecione o cliente.');
        redirect('form.php');
    }

    // Calcular datas se status=ativa
    $dataAtivacao = null;
    $dataVencimento = null;
    if ($statusInicial === 'ativa') {
        $dataAtivacao = date('Y-m-d H:i:s');
        $dias = diasPlano($tipo);
        $dataVencimento = date('Y-m-d H:i:s', strtotime("+{$dias} days"));
    }

    $geradas = [];
    $stmt = $pdo->prepare("INSERT INTO licencas (chave, cliente_id, plano_id, tipo, status, data_ativacao, data_vencimento, observacoes) VALUES (?,?,?,?,?,?,?,?)");

    for ($i = 0; $i < $quantidade; $i++) {
        $chave = generateLicenseKey($tipo, $tipoProduto);

        // Garantir unicidade
        $check = $pdo->prepare("SELECT COUNT(*) FROM licencas WHERE chave = ?");
        $check->execute([$chave]);
        while ($check->fetchColumn() > 0) {
            $chave = generateLicenseKey($tipo, $tipoProduto);
            $check->execute([$chave]);
        }

        $stmt->execute([$chave, $clienteIdPost, $planoIdPost, $tipo, $statusInicial, $dataAtivacao, $dataVencimento, $obs]);
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
                <form method="POST" id="formLicenca">
                    <?= csrfField() ?>

                    <!-- Tipo de Produto -->
                    <div class="mb-4">
                        <label class="form-label fw-bold">Tipo de Produto *</label>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <input type="radio" class="btn-check" name="tipo_produto" id="tp_desktop" value="desktop" checked>
                                <label class="btn btn-outline-primary w-100 text-start py-3" for="tp_desktop">
                                    <i class="fas fa-desktop me-2"></i><strong>Desktop</strong><br>
                                    <small class="text-muted">Cliente ativa pela maquina (chave M/T/A)</small>
                                </label>
                            </div>
                            <div class="col-md-6">
                                <input type="radio" class="btn-check" name="tipo_produto" id="tp_saas" value="saas">
                                <label class="btn btn-outline-primary w-100 text-start py-3" for="tp_saas">
                                    <i class="fas fa-cloud me-2"></i><strong>SaaS (Online)</strong><br>
                                    <small class="text-muted">Acesso via web (chave S)</small>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Periodo *</label>
                            <select name="tipo" class="form-select" id="tipo">
                                <option value="mensal">Mensal (30 dias)</option>
                                <option value="trimestral">Trimestral (90 dias)</option>
                                <option value="anual">Anual (365 dias)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Quantidade</label>
                            <input type="number" name="quantidade" class="form-control" value="1" min="1" max="50" id="quantidade">
                            <small class="text-muted">Maximo 50 por vez</small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Cliente <span id="clienteRequired" class="text-danger d-none">*</span></label>
                            <select name="cliente_id" class="form-select" id="clienteSelect">
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
                            <select name="plano_id" class="form-select" id="planoSelect">
                                <option value="">Sem plano especifico</option>
                                <?php foreach ($planos as $p): ?>
                                    <option value="<?= $p['id'] ?>" data-tipo-produto="<?= e($p['tipo_produto']) ?>">
                                        <?= e($p['nome']) ?> (<?= e(ucfirst($p['tipo_produto'])) ?>) - <?= formatMoney($p['preco']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Status Inicial</label>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <input type="radio" class="btn-check" name="status_inicial" id="st_disp" value="disponivel" checked>
                                    <label class="btn btn-outline-secondary w-100 py-2" for="st_disp">
                                        <i class="fas fa-clock me-1"></i>Disponivel <small>(cliente ativa depois)</small>
                                    </label>
                                </div>
                                <div class="col-md-6">
                                    <input type="radio" class="btn-check" name="status_inicial" id="st_ativa" value="ativa">
                                    <label class="btn btn-outline-success w-100 py-2" for="st_ativa">
                                        <i class="fas fa-check-circle me-1"></i>Ja Ativa <small>(comeca a contar agora)</small>
                                    </label>
                                </div>
                            </div>
                            <small class="text-muted d-block mt-2" id="hintStatusSaas" style="display:none !important;">
                                <i class="fas fa-info-circle"></i> Para SaaS, geralmente <strong>ja ativa</strong> ja que o cliente acessa imediatamente.
                            </small>
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
        <div class="stat-card mb-3" id="cardDesktop">
            <h6 class="fw-bold mb-3"><i class="fas fa-desktop me-1 text-primary"></i>Como funciona (Desktop)</h6>
            <p class="small text-muted mb-2"><i class="fas fa-circle text-primary me-1" style="font-size:0.5rem;vertical-align:middle;"></i> Chave gerada com prefixo <strong>M/T/A</strong></p>
            <p class="small text-muted mb-2"><i class="fas fa-circle text-primary me-1" style="font-size:0.5rem;vertical-align:middle;"></i> Status <strong>Disponivel</strong> por padrao</p>
            <p class="small text-muted mb-2"><i class="fas fa-circle text-primary me-1" style="font-size:0.5rem;vertical-align:middle;"></i> Envie a chave ao cliente (email, WhatsApp)</p>
            <p class="small text-muted mb-2"><i class="fas fa-circle text-primary me-1" style="font-size:0.5rem;vertical-align:middle;"></i> Cliente digita no PDV Desktop</p>
            <p class="small text-muted mb-0"><i class="fas fa-circle text-primary me-1" style="font-size:0.5rem;vertical-align:middle;"></i> O PDV valida via API e ativa</p>
        </div>

        <div class="stat-card mb-3 d-none" id="cardSaas">
            <h6 class="fw-bold mb-3"><i class="fas fa-cloud me-1 text-info"></i>Como funciona (SaaS)</h6>
            <p class="small text-muted mb-2"><i class="fas fa-circle text-info me-1" style="font-size:0.5rem;vertical-align:middle;"></i> Chave gerada com prefixo <strong>S</strong></p>
            <p class="small text-muted mb-2"><i class="fas fa-circle text-info me-1" style="font-size:0.5rem;vertical-align:middle;"></i> Recomendado <strong>Ja Ativa</strong></p>
            <p class="small text-muted mb-2"><i class="fas fa-circle text-info me-1" style="font-size:0.5rem;vertical-align:middle;"></i> Cliente <strong>precisa estar selecionado</strong></p>
            <p class="small text-muted mb-2"><i class="fas fa-circle text-info me-1" style="font-size:0.5rem;vertical-align:middle;"></i> Vencimento conta a partir de hoje</p>
            <p class="small text-warning mb-0">
                <i class="fas fa-exclamation-triangle me-1"></i>
                <strong>Para reativar</strong> uma licenca SaaS existente, use o botao <em>Renovar</em> na pagina do cliente — assim mantem a mesma chave e o login continua valido.
            </p>
        </div>
    </div>
</div>

<script>
const tpDesktop = document.getElementById('tp_desktop');
const tpSaas = document.getElementById('tp_saas');
const cardDesktop = document.getElementById('cardDesktop');
const cardSaas = document.getElementById('cardSaas');
const planoSelect = document.getElementById('planoSelect');
const stDisp = document.getElementById('st_disp');
const stAtiva = document.getElementById('st_ativa');
const hintStatusSaas = document.getElementById('hintStatusSaas');
const clienteRequired = document.getElementById('clienteRequired');
const clienteSelect = document.getElementById('clienteSelect');
const quantidade = document.getElementById('quantidade');
const planoOptions = Array.from(planoSelect.querySelectorAll('option'));

function aplicarTipoProduto() {
    const isSaas = tpSaas.checked;

    cardDesktop.classList.toggle('d-none', isSaas);
    cardSaas.classList.toggle('d-none', !isSaas);

    // Filtrar planos pelo tipo
    planoSelect.value = '';
    planoOptions.forEach(opt => {
        if (!opt.value) return; // "Sem plano especifico"
        const tp = opt.dataset.tipoProduto;
        opt.hidden = isSaas ? tp !== 'saas' : tp !== 'desktop';
    });

    // SaaS: sugerir Ja Ativa por padrao
    if (isSaas) {
        stAtiva.checked = true;
        hintStatusSaas.style.cssText = 'display:block !important';
        clienteRequired.classList.remove('d-none');
        quantidade.value = 1;
        quantidade.max = 1; // SaaS = 1 licenca por cliente
    } else {
        stDisp.checked = true;
        hintStatusSaas.style.cssText = 'display:none !important';
        clienteRequired.classList.add('d-none');
        quantidade.max = 50;
    }
}

tpDesktop.addEventListener('change', aplicarTipoProduto);
tpSaas.addEventListener('change', aplicarTipoProduto);

document.getElementById('formLicenca').addEventListener('submit', function(e) {
    if (tpSaas.checked && stAtiva.checked && !clienteSelect.value) {
        e.preventDefault();
        alert('Para gerar uma licenca SaaS ja ativa, selecione o cliente.');
        clienteSelect.focus();
    }
});

aplicarTipoProduto();
</script>

<?php include APP_PATH . '/includes/footer.php'; ?>
