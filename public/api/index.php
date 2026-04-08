<?php
// ============================================
//   PDV Pro - API REST de Licenciamento
//   Endpoints para o PDV Desktop se comunicar
// ============================================

require_once dirname(__DIR__, 2) . '/app/config.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/mailer.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Conexao
try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    jsonResponse(500, ['error' => 'Servidor indisponivel']);
}

// Router simples
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Rate limiting simples por IP
rateLimitCheck($pdo, $action);

match($action) {
    'registrar' => handleRegistrar($pdo),
    'registrar_saas' => handleRegistrarSaas($pdo),
    'validar' => handleValidar($pdo),
    'validar_saas' => handleValidarSaas($pdo),
    'ativar' => handleAtivar($pdo),
    'reportar_nfce' => handleReportarNfce($pdo),
    'checar_atualizacao' => handleChecarAtualizacao($pdo),
    'webhook_asaas' => handleWebhookAsaas($pdo),
    'planos' => handlePlanos($pdo),
    'upgrade' => handleUpgrade($pdo),
    'verificar_upgrade' => handleVerificarUpgrade($pdo),
    'status_pagamento' => handleStatusPagamento($pdo),
    'status' => jsonResponse(200, ['status' => 'online', 'version' => APP_VERSION]),
    default => jsonResponse(404, ['error' => 'Endpoint nao encontrado']),
};

// ============================================
//   Handlers
// ============================================

function handleRegistrar(PDO $pdo): void {
    $input = getInput();

    $razaoSocial = trim($input['razao_social'] ?? '');
    $nomeFantasia = trim($input['nome_fantasia'] ?? '') ?: null;
    $cnpj = trim($input['cnpj'] ?? '') ?: null;
    $cpf = trim($input['cpf'] ?? '') ?: null;
    $email = trim($input['email'] ?? '') ?: null;
    $telefone = trim($input['telefone'] ?? '') ?: null;
    $whatsapp = trim($input['whatsapp'] ?? '') ?: null;
    $contatoNome = trim($input['contato_nome'] ?? '') ?: null;
    $cidade = trim($input['cidade'] ?? '') ?: null;
    $uf = trim($input['uf'] ?? '') ?: null;
    $hardwareId = $input['hardware_id'] ?? '';

    // Validacoes
    if (empty($razaoSocial)) {
        jsonResponse(400, ['ok' => false, 'mensagem' => 'Razao Social e obrigatoria.']);
    }
    if (empty($cnpj) && empty($cpf)) {
        jsonResponse(400, ['ok' => false, 'mensagem' => 'CNPJ ou CPF e obrigatorio.']);
    }
    if (empty($email)) {
        jsonResponse(400, ['ok' => false, 'mensagem' => 'Email e obrigatorio.']);
    }

    // Verificar se CNPJ/CPF ja existe
    if ($cnpj) {
        $stmt = $pdo->prepare("SELECT id FROM clientes WHERE cnpj = ?");
        $stmt->execute([$cnpj]);
        if ($stmt->fetch()) {
            // Cliente ja existe - buscar licenca free dele
            $stmt2 = $pdo->prepare("SELECT l.chave FROM licencas l JOIN clientes c ON l.cliente_id = c.id WHERE c.cnpj = ? AND l.status IN ('disponivel','ativa') ORDER BY l.id DESC LIMIT 1");
            $stmt2->execute([$cnpj]);
            $licExistente = $stmt2->fetch();
            if ($licExistente) {
                jsonResponse(200, [
                    'ok' => true,
                    'mensagem' => 'Empresa ja cadastrada. Licenca recuperada.',
                    'chave' => $licExistente['chave'],
                    'tipo' => 'free',
                ]);
            }
            jsonResponse(409, ['ok' => false, 'mensagem' => 'CNPJ ja cadastrado. Entre em contato com o suporte.']);
        }
    }

    // Buscar plano Free Desktop
    $stmt = $pdo->prepare("SELECT id FROM planos WHERE slug = 'desktop-free' LIMIT 1");
    $stmt->execute();
    $planoFree = $stmt->fetch();
    $planoId = $planoFree ? $planoFree['id'] : null;

    // Gerar API token e chave de licenca
    $apiToken = bin2hex(random_bytes(32));
    $chave = gerarChaveFree();

    // Garantir chave unica
    $check = $pdo->prepare("SELECT COUNT(*) FROM licencas WHERE chave = ?");
    $check->execute([$chave]);
    while ($check->fetchColumn() > 0) {
        $chave = gerarChaveFree();
        $check->execute([$chave]);
    }

    try {
        $pdo->beginTransaction();

        // Criar cliente
        $stmt = $pdo->prepare("INSERT INTO clientes (razao_social, nome_fantasia, cnpj, cpf, email, telefone, whatsapp, contato_nome, cidade, uf, plano_id, status, api_token) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$razaoSocial, $nomeFantasia, $cnpj, $cpf, $email, $telefone, $whatsapp, $contatoNome, $cidade, $uf, $planoId, 'ativo', $apiToken]);
        $clienteId = (int)$pdo->lastInsertId();

        // Criar licenca Free (sem expiracao)
        $stmt = $pdo->prepare("INSERT INTO licencas (chave, cliente_id, plano_id, tipo, status, hardware_id, data_ativacao, data_vencimento, ultimo_check, ip_ativacao, observacoes) VALUES (?,?,?,?,?,?,NOW(),'2099-12-31 23:59:59',NOW(),?,?)");
        $stmt->execute([$chave, $clienteId, $planoId, 'mensal', 'ativa', $hardwareId, $_SERVER['REMOTE_ADDR'] ?? '', 'Registro automatico - Plano Free']);

        $pdo->commit();

        logApi($pdo, null, $clienteId, 'registrar', ['cnpj' => $cnpj, 'email' => $email, 'chave' => $chave]);

        jsonResponse(201, [
            'ok' => true,
            'mensagem' => 'Cadastro realizado com sucesso! Seu PDV esta ativo.',
            'chave' => $chave,
            'tipo' => 'free',
            'cliente_id' => $clienteId,
        ]);
    } catch (\PDOException $e) {
        $pdo->rollBack();
        jsonResponse(500, ['ok' => false, 'mensagem' => 'Erro ao cadastrar. Tente novamente.']);
    }
}

function gerarChaveFree(): string {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $key = 'F'; // F = Free
    for ($i = 1; $i < 16; $i++) {
        $key .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return substr($key, 0, 4) . '-' . substr($key, 4, 4) . '-' . substr($key, 8, 4) . '-' . substr($key, 12, 4);
}

function handleValidar(PDO $pdo): void {
    $input = getInput();
    $chave = strtoupper(trim($input['chave'] ?? ''));
    $hardwareId = $input['hardware_id'] ?? '';

    if (empty($chave)) {
        jsonResponse(400, ['error' => 'Chave obrigatoria']);
    }

    $stmt = $pdo->prepare("
        SELECT l.*, p.limite_nfce, p.nome as plano_nome
        FROM licencas l
        LEFT JOIN planos p ON l.plano_id = p.id
        WHERE l.chave = ?
    ");
    $stmt->execute([$chave]);
    $licenca = $stmt->fetch();

    if (!$licenca) {
        logApi($pdo, null, null, 'validar', ['chave' => $chave, 'resultado' => 'nao_encontrada']);
        jsonResponse(404, ['status' => 'invalida', 'mensagem' => 'Licenca nao encontrada']);
    }

    // Atualizar ultimo check
    $pdo->prepare("UPDATE licencas SET ultimo_check = NOW() WHERE id = ?")->execute([$licenca['id']]);

    // Verificar status
    if (in_array($licenca['status'], ['revogada', 'bloqueada'])) {
        logApi($pdo, $licenca['id'], $licenca['cliente_id'], 'validar', ['resultado' => $licenca['status']]);
        jsonResponse(403, [
            'status' => $licenca['status'],
            'mensagem' => 'Licenca ' . $licenca['status'] . '. Entre em contato com o suporte.',
        ]);
    }

    if ($licenca['status'] === 'expirada') {
        logApi($pdo, $licenca['id'], $licenca['cliente_id'], 'validar', ['resultado' => 'expirada']);
        jsonResponse(402, [
            'status' => 'expirada',
            'mensagem' => 'Licenca expirada. Renove para continuar usando.',
            'data_vencimento' => $licenca['data_vencimento'],
        ]);
    }

    if ($licenca['status'] === 'disponivel') {
        logApi($pdo, $licenca['id'], $licenca['cliente_id'], 'validar', ['resultado' => 'nao_ativada']);
        jsonResponse(200, [
            'status' => 'disponivel',
            'mensagem' => 'Licenca valida mas ainda nao ativada. Use o endpoint /ativar.',
        ]);
    }

    // Verificar hardware
    if ($licenca['hardware_id'] && $licenca['hardware_id'] !== $hardwareId) {
        logApi($pdo, $licenca['id'], $licenca['cliente_id'], 'validar', ['resultado' => 'hardware_diferente']);
        jsonResponse(403, [
            'status' => 'bloqueada',
            'mensagem' => 'Esta licenca esta vinculada a outra maquina.',
        ]);
    }

    // Verificar vencimento
    $vencimento = new DateTime($licenca['data_vencimento']);
    $agora = new DateTime();
    $diasRestantes = $vencimento > $agora ? (int)$agora->diff($vencimento)->days : 0;

    if ($diasRestantes <= 0) {
        $pdo->prepare("UPDATE licencas SET status = 'expirada' WHERE id = ?")->execute([$licenca['id']]);
        logApi($pdo, $licenca['id'], $licenca['cliente_id'], 'validar', ['resultado' => 'expirou_agora']);
        jsonResponse(402, [
            'status' => 'expirada',
            'mensagem' => 'Licenca expirada. Renove para continuar usando.',
        ]);
    }

    // Verificar limite NFC-e
    $mesAtual = date('Y-m');
    $limiteNfce = (int)($licenca['limite_nfce'] ?? 0);
    $nfceEmitidas = (int)$licenca['nfce_emitidas_mes'];

    // Resetar contador se mudou o mes
    if ($licenca['nfce_mes_referencia'] !== $mesAtual) {
        $nfceEmitidas = 0;
        $pdo->prepare("UPDATE licencas SET nfce_emitidas_mes = 0, nfce_mes_referencia = ? WHERE id = ?")
            ->execute([$mesAtual, $licenca['id']]);
    }

    $nfceLiberada = ($limiteNfce === 0) || ($nfceEmitidas < $limiteNfce);

    // Verificar inadimplencia
    $inadimplente = false;
    $inadimplenteDias = 0;
    $inadimplenteBloqueio = false;
    if (!empty($licenca['inadimplente_desde'])) {
        $inadimplente = true;
        $inadimplenteDias = (int)((time() - strtotime($licenca['inadimplente_desde'])) / 86400);

        try {
            $bloqDias = (int)getConfig($pdo, 'inadimplencia_bloqueio_dias', '15');
        } catch (\Throwable $e) {
            $bloqDias = 15;
        }

        if ($inadimplenteDias >= $bloqDias) {
            $inadimplenteBloqueio = true;
            $nfceLiberada = false;
        }
    }

    logApi($pdo, $licenca['id'], $licenca['cliente_id'], 'validar', ['resultado' => 'ativa']);

    jsonResponse(200, [
        'status' => $inadimplenteBloqueio ? 'inadimplente_bloqueado' : 'ativa',
        'tipo' => $licenca['tipo'],
        'plano' => $licenca['plano_nome'] ?? 'Sem plano',
        'dias_restantes' => $diasRestantes,
        'data_vencimento' => $licenca['data_vencimento'],
        'nfce_emitidas_mes' => $nfceEmitidas,
        'nfce_limite_mes' => $limiteNfce,
        'nfce_liberada' => $nfceLiberada,
        'alerta_vencimento' => $diasRestantes <= 7,
        'inadimplente' => $inadimplente,
        'inadimplente_dias' => $inadimplenteDias,
        'inadimplente_bloqueio' => $inadimplenteBloqueio,
    ]);
}

function handleAtivar(PDO $pdo): void {
    $input = getInput();
    $chave = strtoupper(trim($input['chave'] ?? ''));
    $hardwareId = $input['hardware_id'] ?? '';

    if (empty($chave) || empty($hardwareId)) {
        jsonResponse(400, ['error' => 'Chave e hardware_id obrigatorios']);
    }

    $stmt = $pdo->prepare("SELECT * FROM licencas WHERE chave = ?");
    $stmt->execute([$chave]);
    $licenca = $stmt->fetch();

    if (!$licenca) {
        logApi($pdo, null, null, 'ativar', ['chave' => $chave, 'resultado' => 'nao_encontrada']);
        jsonResponse(404, ['ok' => false, 'mensagem' => 'Licenca nao encontrada']);
    }

    if ($licenca['status'] === 'ativa' && $licenca['hardware_id'] === $hardwareId) {
        jsonResponse(200, ['ok' => true, 'mensagem' => 'Licenca ja esta ativa nesta maquina']);
    }

    if ($licenca['status'] === 'ativa' && $licenca['hardware_id'] !== $hardwareId) {
        jsonResponse(403, ['ok' => false, 'mensagem' => 'Licenca ja ativada em outra maquina']);
    }

    if (in_array($licenca['status'], ['revogada', 'bloqueada'])) {
        jsonResponse(403, ['ok' => false, 'mensagem' => 'Licenca ' . $licenca['status']]);
    }

    // Ativar
    $dias = diasPlano($licenca['tipo']);
    $vencimento = date('Y-m-d H:i:s', strtotime("+{$dias} days"));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    $pdo->prepare("UPDATE licencas SET status = 'ativa', hardware_id = ?, data_ativacao = NOW(), data_vencimento = ?, ultimo_check = NOW(), ip_ativacao = ? WHERE id = ?")
        ->execute([$hardwareId, $vencimento, $ip, $licenca['id']]);

    // Atualizar status do cliente
    if ($licenca['cliente_id']) {
        $pdo->prepare("UPDATE clientes SET status = 'ativo' WHERE id = ?")->execute([$licenca['cliente_id']]);
    }

    logApi($pdo, $licenca['id'], $licenca['cliente_id'], 'ativar', ['resultado' => 'sucesso', 'hardware_id' => $hardwareId]);

    jsonResponse(200, [
        'ok' => true,
        'mensagem' => 'Licenca ativada com sucesso!',
        'tipo' => $licenca['tipo'],
        'data_vencimento' => $vencimento,
        'dias' => $dias,
    ]);
}

function handleReportarNfce(PDO $pdo): void {
    $input = getInput();
    $chave = strtoupper(trim($input['chave'] ?? ''));
    $quantidade = max(0, (int)($input['quantidade'] ?? 0));

    if (empty($chave)) {
        jsonResponse(400, ['error' => 'Chave obrigatoria']);
    }

    $stmt = $pdo->prepare("SELECT l.*, p.limite_nfce FROM licencas l LEFT JOIN planos p ON l.plano_id = p.id WHERE l.chave = ? AND l.status = 'ativa'");
    $stmt->execute([$chave]);
    $licenca = $stmt->fetch();

    if (!$licenca) {
        jsonResponse(404, ['error' => 'Licenca nao encontrada ou inativa']);
    }

    $mesAtual = date('Y-m');
    $novoTotal = $quantidade;

    // Se mudou o mes, reseta
    if ($licenca['nfce_mes_referencia'] !== $mesAtual) {
        $pdo->prepare("UPDATE licencas SET nfce_emitidas_mes = ?, nfce_mes_referencia = ? WHERE id = ?")
            ->execute([$novoTotal, $mesAtual, $licenca['id']]);
    } else {
        $novoTotal = (int)$licenca['nfce_emitidas_mes'] + $quantidade;
        $pdo->prepare("UPDATE licencas SET nfce_emitidas_mes = ? WHERE id = ?")
            ->execute([$novoTotal, $licenca['id']]);
    }

    $limite = (int)($licenca['limite_nfce'] ?? 0);
    $liberada = ($limite === 0) || ($novoTotal < $limite);

    logApi($pdo, $licenca['id'], $licenca['cliente_id'], 'reportar_nfce', ['quantidade' => $quantidade, 'total_mes' => $novoTotal]);

    jsonResponse(200, [
        'ok' => true,
        'nfce_emitidas_mes' => $novoTotal,
        'nfce_limite_mes' => $limite,
        'nfce_liberada' => $liberada,
    ]);
}

function handleChecarAtualizacao(PDO $pdo): void {
    $input = getInput();
    $versaoCliente = trim($input['versao'] ?? $_GET['versao'] ?? '');
    $tipoProduto = trim($input['tipo'] ?? $_GET['tipo'] ?? 'desktop');

    // Buscar versao mais recente ativa
    $stmt = $pdo->prepare("SELECT * FROM versoes WHERE tipo_produto = ? AND ativa = 1 ORDER BY criado_em DESC LIMIT 1");
    $stmt->execute([$tipoProduto]);
    $ultima = $stmt->fetch();

    if (!$ultima) {
        jsonResponse(200, [
            'atualizar' => false,
            'versao_servidor' => APP_VERSION,
        ]);
    }

    $temAtualizacao = !empty($versaoCliente) && version_compare($ultima['versao'], $versaoCliente, '>');

    $urlDownload = null;
    if ($ultima['arquivo_nome']) {
        $urlDownload = APP_URL . '/versoes/download.php?id=' . $ultima['id'];
    }

    jsonResponse(200, [
        'atualizar' => $temAtualizacao,
        'versao_servidor' => $ultima['versao'],
        'versao_cliente' => $versaoCliente,
        'url_download' => $urlDownload,
        'obrigatoria' => (bool)$ultima['obrigatoria'],
        'changelog' => $ultima['changelog'],
        'tamanho' => (int)$ultima['arquivo_tamanho'],
    ]);
}

// ============================================
//   Planos e Upgrade
// ============================================

function handlePlanos(PDO $pdo): void {
    $tipo = $_GET['tipo'] ?? 'desktop';
    $incluirFree = ($_GET['incluir_free'] ?? '0') === '1';

    // Planos pagos ativos
    $stmt = $pdo->prepare("SELECT id, nome, slug, tipo_produto, periodo, preco, limite_nfce, limite_terminais, recursos FROM planos WHERE tipo_produto = ? AND ativo = 1 AND preco > 0 ORDER BY preco ASC");
    $stmt->execute([$tipo]);
    $planos = $stmt->fetchAll();

    // Se solicitado, incluir plano Free (busca pelo slug, independente de ativo)
    $planoFree = null;
    if ($incluirFree) {
        $stmtFree = $pdo->prepare("SELECT id, nome, slug, tipo_produto, periodo, preco, limite_nfce, limite_terminais, recursos FROM planos WHERE slug = ? LIMIT 1");
        $stmtFree->execute([$tipo . '-free']);
        $planoFree = $stmtFree->fetch();
        if ($planoFree) {
            array_unshift($planos, $planoFree);
        }
    }

    foreach ($planos as &$p) {
        $p['recursos'] = json_decode($p['recursos'], true) ?: [];
        $p['preco'] = (float)$p['preco'];
        $p['limite_nfce'] = (int)$p['limite_nfce'];
    }

    jsonResponse(200, ['ok' => true, 'planos' => $planos]);
}

function handleUpgrade(PDO $pdo): void {
    $input = getInput();
    $chave = strtoupper(trim($input['chave'] ?? ''));
    $planoSlug = trim($input['plano'] ?? '');

    if (empty($chave) || empty($planoSlug)) {
        jsonResponse(400, ['ok' => false, 'mensagem' => 'Chave e plano sao obrigatorios.']);
    }

    // Buscar licenca e cliente
    $stmt = $pdo->prepare("SELECT l.*, c.id as cid, c.razao_social, c.cnpj, c.cpf, c.email, c.telefone, c.whatsapp, c.asaas_customer_id FROM licencas l LEFT JOIN clientes c ON l.cliente_id = c.id WHERE l.chave = ?");
    $stmt->execute([$chave]);
    $lic = $stmt->fetch();

    if (!$lic || !$lic['cid']) {
        jsonResponse(404, ['ok' => false, 'mensagem' => 'Licenca ou cliente nao encontrado.']);
    }

    // Buscar plano
    $stmt = $pdo->prepare("SELECT * FROM planos WHERE slug = ? AND ativo = 1");
    $stmt->execute([$planoSlug]);
    $plano = $stmt->fetch();

    if (!$plano || $plano['preco'] <= 0) {
        jsonResponse(404, ['ok' => false, 'mensagem' => 'Plano nao encontrado.']);
    }

    // Verificar se ja tem pagamento pendente para este plano
    $stmt = $pdo->prepare("SELECT id, asaas_url FROM pagamentos WHERE cliente_id = ? AND plano_id = ? AND status = 'pendente' AND asaas_url IS NOT NULL ORDER BY id DESC LIMIT 1");
    $stmt->execute([$lic['cid'], $plano['id']]);
    $pendente = $stmt->fetch();

    if ($pendente && !empty($pendente['asaas_url'])) {
        jsonResponse(200, [
            'ok' => true,
            'mensagem' => 'Voce ja tem uma cobranca pendente para este plano.',
            'payment_url' => $pendente['asaas_url'],
            'pagamento_id' => $pendente['id'],
        ]);
    }

    // Criar/buscar cliente no Asaas
    require_once APP_PATH . '/includes/asaas.php';
    try {
        $asaas = new Asaas($pdo);
    } catch (Exception $e) {
        jsonResponse(500, ['ok' => false, 'mensagem' => 'Sistema de pagamento indisponivel. Tente mais tarde.']);
    }

    try {
        $cpfCnpj = $lic['cnpj'] ?: ($lic['cpf'] ?? '');
        if (empty($cpfCnpj)) {
            jsonResponse(400, ['ok' => false, 'mensagem' => 'Cliente sem CPF/CNPJ. Atualize o cadastro.']);
        }

        $asaasCustomerId = $lic['asaas_customer_id'] ?? '';

        if (empty($asaasCustomerId)) {
            $customer = $asaas->getOrCreateCustomer([
                'razao_social' => $lic['razao_social'],
                'nome_fantasia' => $lic['razao_social'],
                'cnpj' => $lic['cnpj'],
                'cpf' => $lic['cpf'],
                'email' => $lic['email'],
                'telefone' => $lic['telefone'] ?? $lic['whatsapp'] ?? '',
            ]);
            $asaasCustomerId = $customer['id'];
            $pdo->prepare("UPDATE clientes SET asaas_customer_id = ? WHERE id = ?")->execute([$asaasCustomerId, $lic['cid']]);
        }

        // Gerar nova chave para a licenca paga
        $novaChave = generateLicenseKey($plano['periodo']);

        // Criar licenca pendente (sera ativada pelo webhook)
        $pdo->prepare("INSERT INTO licencas (chave, cliente_id, plano_id, tipo, status, hardware_id, observacoes) VALUES (?,?,?,?,?,?,?)")
            ->execute([$novaChave, $lic['cid'], $plano['id'], $plano['periodo'], 'disponivel', $lic['hardware_id'], 'Aguardando pagamento - upgrade de ' . $chave]);
        $novaLicId = (int)$pdo->lastInsertId();

        // Criar assinatura recorrente no Asaas
        $descricao = "PDV Pro - {$plano['nome']}";
        $vencimento = date('Y-m-d', strtotime('+3 days'));

        $subscription = $asaas->createSubscription([
            'customer_id' => $asaasCustomerId,
            'billing_type' => 'UNDEFINED',
            'valor' => $plano['preco'],
            'vencimento' => $vencimento,
            'periodo' => $plano['periodo'],
            'descricao' => $descricao,
            'referencia' => "LIC-{$novaLicId}",
        ]);

        $subscriptionId = $subscription['id'] ?? '';

        // Buscar primeira cobranca da assinatura para obter invoiceUrl
        $invoiceUrl = '';
        if ($subscriptionId) {
            try {
                $payments = $asaas->getSubscriptionPayments($subscriptionId);
                if (!empty($payments['data'][0])) {
                    $firstPayment = $payments['data'][0];
                    $invoiceUrl = $firstPayment['invoiceUrl'] ?? '';
                    $asaasPaymentId = $firstPayment['id'] ?? '';

                    // Registrar primeiro pagamento
                    $pdo->prepare("INSERT INTO pagamentos (cliente_id, licenca_id, plano_id, valor, forma, status, referencia, asaas_id, asaas_url, asaas_subscription_id, tipo_cobranca, mes_referencia, observacoes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([$lic['cid'], $novaLicId, $plano['id'], $plano['preco'], 'pix', 'pendente', "LIC-{$novaLicId}", $asaasPaymentId, $invoiceUrl, $subscriptionId, 'recorrente', date('Y-m'), $descricao]);
                }
            } catch (\Throwable $e) {
                // Se nao conseguir buscar pagamentos, usar URL da assinatura
            }
        }

        // Salvar subscription_id na licenca
        $pdo->prepare("UPDATE licencas SET asaas_subscription_id = ?, observacoes = ? WHERE id = ?")
            ->execute([$subscriptionId, "Assinatura: {$subscriptionId}", $novaLicId]);

        // Atualizar plano do cliente
        $pdo->prepare("UPDATE clientes SET plano_id = ? WHERE id = ?")->execute([$plano['id'], $lic['cid']]);

        logApi($pdo, $lic['id'], $lic['cid'], 'upgrade', [
            'plano' => $planoSlug,
            'subscription_id' => $subscriptionId,
            'nova_chave' => $novaChave,
        ]);

        jsonResponse(201, [
            'ok' => true,
            'mensagem' => 'Assinatura criada! Pague a primeira cobranca para ativar.',
            'payment_url' => $invoiceUrl,
            'plano' => $plano['nome'],
            'valor' => $plano['preco'],
            'periodo' => $plano['periodo'],
            'nova_chave' => $novaChave,
        ]);

    } catch (Exception $e) {
        logApi($pdo, $lic['id'] ?? null, $lic['cid'] ?? null, 'upgrade_erro', ['erro' => $e->getMessage()]);
        jsonResponse(500, ['ok' => false, 'mensagem' => 'Erro ao gerar cobranca: ' . $e->getMessage()]);
    }
}

function handleStatusPagamento(PDO $pdo): void {
    $input = getInput();
    $pagamentoId = (int)($input['pagamento_id'] ?? $_GET['pagamento_id'] ?? 0);
    $chave = strtoupper(trim($input['chave'] ?? $_GET['chave'] ?? ''));

    if (!$pagamentoId && !$chave) {
        jsonResponse(400, ['ok' => false, 'mensagem' => 'Informe pagamento_id ou chave.']);
    }

    if ($pagamentoId) {
        $stmt = $pdo->prepare("SELECT p.*, pl.nome as plano_nome FROM pagamentos p LEFT JOIN planos pl ON p.plano_id = pl.id WHERE p.id = ?");
        $stmt->execute([$pagamentoId]);
    } else {
        $stmt = $pdo->prepare("SELECT p.*, pl.nome as plano_nome FROM pagamentos p LEFT JOIN planos pl ON p.plano_id = pl.id LEFT JOIN licencas l ON p.licenca_id = l.id WHERE l.chave = ? ORDER BY p.id DESC LIMIT 1");
        $stmt->execute([$chave]);
    }

    $pag = $stmt->fetch();
    if (!$pag) {
        jsonResponse(404, ['ok' => false, 'mensagem' => 'Pagamento nao encontrado.']);
    }

    jsonResponse(200, [
        'ok' => true,
        'status' => $pag['status'],
        'plano' => $pag['plano_nome'],
        'valor' => (float)$pag['valor'],
        'payment_url' => $pag['asaas_url'],
        'data_pagamento' => $pag['data_pagamento'],
    ]);
}

// ============================================
//   Verificar Upgrade (auto-ativacao)
// ============================================

function handleVerificarUpgrade(PDO $pdo): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(405, ['error' => 'Metodo nao permitido']);
    }

    $input = getInput();
    $hardwareId = trim($input['hardware_id'] ?? '');
    $chaveAtual = strtoupper(trim($input['chave_atual'] ?? ''));

    // Validar parametros obrigatorios
    if (empty($hardwareId) || !preg_match('/^[a-f0-9]{64}$/i', $hardwareId)) {
        jsonResponse(400, ['ok' => false, 'mensagem' => 'Hardware ID invalido.']);
    }

    if (empty($chaveAtual)) {
        jsonResponse(400, ['ok' => false, 'mensagem' => 'Chave atual obrigatoria.']);
    }

    // Buscar licenca atual para confirmar que o hardware_id pertence a esta chave
    $stmt = $pdo->prepare("SELECT l.id, l.cliente_id, l.hardware_id FROM licencas l WHERE l.chave = ? AND l.hardware_id = ?");
    $stmt->execute([$chaveAtual, $hardwareId]);
    $licAtual = $stmt->fetch();

    if (!$licAtual) {
        logApi($pdo, null, null, 'verificar_upgrade_negado', ['chave' => $chaveAtual, 'hw' => substr($hardwareId, 0, 12) . '...']);
        jsonResponse(403, ['ok' => false, 'mensagem' => 'Licenca nao encontrada para este hardware.']);
    }

    // Buscar licenca paga ativa para o mesmo cliente e hardware
    $stmt = $pdo->prepare("
        SELECT l.chave, l.tipo, l.status, l.data_vencimento, p.nome as plano_nome, p.limite_nfce
        FROM licencas l
        LEFT JOIN planos p ON l.plano_id = p.id
        WHERE l.cliente_id = ?
          AND l.hardware_id = ?
          AND l.status = 'ativa'
          AND l.chave != ?
          AND p.preco > 0
        ORDER BY l.data_ativacao DESC
        LIMIT 1
    ");
    $stmt->execute([$licAtual['cliente_id'], $hardwareId, $chaveAtual]);
    $upgrade = $stmt->fetch();

    if (!$upgrade) {
        jsonResponse(200, ['ok' => true, 'upgrade_disponivel' => false]);
    }

    logApi($pdo, $licAtual['id'], $licAtual['cliente_id'], 'verificar_upgrade_encontrado', [
        'nova_chave' => $upgrade['chave'],
        'plano' => $upgrade['plano_nome'],
    ]);

    jsonResponse(200, [
        'ok' => true,
        'upgrade_disponivel' => true,
        'chave' => $upgrade['chave'],
        'tipo' => $upgrade['tipo'],
        'plano' => $upgrade['plano_nome'],
        'limite_nfce' => (int)$upgrade['limite_nfce'],
        'data_vencimento' => $upgrade['data_vencimento'],
    ]);
}

// ============================================
//   Webhook Asaas
// ============================================

function handleWebhookAsaas(PDO $pdo): void {
    $payload = file_get_contents('php://input');
    $data = json_decode($payload, true);

    if (!$data || empty($data['event']) || empty($data['payment'])) {
        jsonResponse(400, ['error' => 'Payload invalido']);
    }

    // Validar webhook secret (opcional)
    try {
        $secret = getConfig($pdo, 'asaas_webhook_secret');
        if (!empty($secret)) {
            $token = $_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] ?? '';
            if ($token !== $secret) {
                logApi($pdo, null, null, 'webhook_asaas', ['erro' => 'Token invalido', 'event' => $data['event']]);
                jsonResponse(401, ['error' => 'Token invalido']);
            }
        }
    } catch (\Throwable $e) {
        // tabela configuracoes pode nao existir ainda, ignorar
    }

    $event = $data['event'];
    $payment = $data['payment'];
    $asaasId = $payment['id'] ?? '';
    $externalRef = $payment['externalReference'] ?? '';

    logApi($pdo, null, null, 'webhook_asaas', ['event' => $event, 'asaas_id' => $asaasId, 'ref' => $externalRef, 'status' => $payment['status'] ?? '']);

    // Buscar pagamento local pela referencia (PAG-{pagamento_id})
    $pagamentoId = null;
    if (str_starts_with($externalRef, 'PAG-')) {
        $pagamentoId = (int)substr($externalRef, 4);
    }

    if (!$pagamentoId) {
        // Tentar buscar pelo asaas_id na tabela pagamentos
        $stmt = $pdo->prepare("SELECT id FROM pagamentos WHERE asaas_id = ?");
        $stmt->execute([$asaasId]);
        $row = $stmt->fetch();
        if ($row) $pagamentoId = (int)$row['id'];
    }

    // Pagamento recorrente pode nao existir localmente ainda (Asaas gera automaticamente)
    // Nesse caso, buscar pela subscription e criar registro local
    $subscriptionId = $payment['subscription'] ?? '';

    if (!$pagamentoId && $subscriptionId) {
        // Buscar licenca pela subscription
        $stmt = $pdo->prepare("SELECT l.id, l.cliente_id, l.plano_id, l.tipo FROM licencas l WHERE l.asaas_subscription_id = ?");
        $stmt->execute([$subscriptionId]);
        $licSub = $stmt->fetch();

        if ($licSub) {
            // Buscar plano para pegar preco
            $stmtP = $pdo->prepare("SELECT preco, nome FROM planos WHERE id = ?");
            $stmtP->execute([$licSub['plano_id']]);
            $planoSub = $stmtP->fetch();

            // Criar pagamento local para essa cobranca recorrente
            $pdo->prepare("INSERT INTO pagamentos (cliente_id, licenca_id, plano_id, valor, forma, status, referencia, asaas_id, asaas_url, asaas_subscription_id, tipo_cobranca, mes_referencia, observacoes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $licSub['cliente_id'], $licSub['id'], $licSub['plano_id'],
                    $planoSub['preco'] ?? 0, 'pix', 'pendente',
                    "LIC-{$licSub['id']}", $asaasId, $payment['invoiceUrl'] ?? '',
                    $subscriptionId, 'recorrente', date('Y-m'),
                    "Cobranca recorrente - {$planoSub['nome']}"
                ]);
            $pagamentoId = (int)$pdo->lastInsertId();
        }
    }

    if (!$pagamentoId) {
        jsonResponse(200, ['ok' => true, 'msg' => 'Pagamento nao encontrado localmente, ignorado.']);
    }

    // Buscar dados do pagamento
    $stmt = $pdo->prepare("SELECT p.*, l.id as licenca_id, l.chave, l.tipo as licenca_tipo, l.asaas_subscription_id, c.id as cid, c.email as cliente_email, c.razao_social FROM pagamentos p LEFT JOIN licencas l ON p.licenca_id = l.id LEFT JOIN clientes c ON p.cliente_id = c.id WHERE p.id = ?");
    $stmt->execute([$pagamentoId]);
    $pagamento = $stmt->fetch();

    if (!$pagamento) {
        jsonResponse(200, ['ok' => true, 'msg' => 'Pagamento local nao encontrado.']);
    }

    switch ($event) {
        case 'PAYMENT_CONFIRMED':
        case 'PAYMENT_RECEIVED':
            // Marcar pagamento como pago
            $pdo->prepare("UPDATE pagamentos SET status = 'pago', data_pagamento = NOW() WHERE id = ?")
                ->execute([$pagamentoId]);

            // Ativar/renovar licenca
            if ($pagamento['licenca_id']) {
                $dias = diasPlano($pagamento['licenca_tipo'] ?? 'mensal');
                $vencimento = date('Y-m-d H:i:s', strtotime("+{$dias} days"));

                $pdo->prepare("UPDATE licencas SET status = 'ativa', data_ativacao = COALESCE(data_ativacao, NOW()), data_vencimento = ?, ultimo_check = NOW(), inadimplente_desde = NULL WHERE id = ?")
                    ->execute([$vencimento, $pagamento['licenca_id']]);

                if ($pagamento['cid']) {
                    $pdo->prepare("UPDATE clientes SET status = 'ativo' WHERE id = ?")->execute([$pagamento['cid']]);
                }
            }

            logApi($pdo, $pagamento['licenca_id'], $pagamento['cid'], 'webhook_pagamento_confirmado', [
                'pagamento_id' => $pagamentoId, 'asaas_id' => $asaasId,
            ]);

            // Enviar email de confirmacao
            if (!empty($pagamento['cliente_email']) && !empty($pagamento['chave'])) {
                enviarEmailLicenca($pdo, $pagamento);
            }
            break;

        case 'PAYMENT_OVERDUE':
            $pdo->prepare("UPDATE pagamentos SET status = 'pendente' WHERE id = ?")->execute([$pagamentoId]);

            // Marcar inadimplencia na licenca (se ainda nao marcado)
            if ($pagamento['licenca_id']) {
                $pdo->prepare("UPDATE licencas SET inadimplente_desde = COALESCE(inadimplente_desde, CURDATE()) WHERE id = ?")
                    ->execute([$pagamento['licenca_id']]);

                if ($pagamento['cid']) {
                    $pdo->prepare("UPDATE clientes SET status = 'inadimplente' WHERE id = ?")->execute([$pagamento['cid']]);
                }
            }

            logApi($pdo, $pagamento['licenca_id'], $pagamento['cid'], 'webhook_inadimplente', [
                'pagamento_id' => $pagamentoId, 'asaas_id' => $asaasId,
            ]);
            break;

        case 'PAYMENT_DELETED':
        case 'PAYMENT_REFUNDED':
            $pdo->prepare("UPDATE pagamentos SET status = 'cancelado' WHERE id = ?")->execute([$pagamentoId]);
            break;
    }

    jsonResponse(200, ['ok' => true]);
}

// ============================================
//   Helpers
// ============================================

function getInput(): array {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    if ($data) return $data;
    return $_POST;
}

function jsonResponse(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function logApi(PDO $pdo, ?int $licencaId, ?int $clienteId, string $acao, array $data): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $pdo->prepare("INSERT INTO api_logs (licenca_id, cliente_id, acao, ip, request_data) VALUES (?,?,?,?,?)")
        ->execute([$licencaId, $clienteId, $acao, $ip, json_encode($data, JSON_UNESCAPED_UNICODE)]);
}

function rateLimitCheck(PDO $pdo, string $action): void {
    // Acoes sensiveis: max 30 requests por minuto por IP
    $acoesSensiveis = ['registrar', 'upgrade', 'ativar', 'verificar_upgrade'];
    if (!in_array($action, $acoesSensiveis)) return;

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (empty($ip)) return;

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM api_logs WHERE ip = ? AND acao = ? AND criado_em > DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
        $stmt->execute([$ip, $action]);
        $count = (int)$stmt->fetchColumn();

        if ($count >= 30) {
            jsonResponse(429, ['error' => 'Muitas requisicoes. Aguarde um momento.']);
        }
    } catch (\Throwable $e) {
        // Se a tabela nao existir, ignorar
    }
}

function enviarEmailLicenca(PDO $pdo, array $pagamento): void {
    try {
        $chave = $pagamento['chave'];
        $nome = $pagamento['razao_social'] ?? 'Cliente';
        $email = $pagamento['cliente_email'];
        $tipo = ucfirst($pagamento['licenca_tipo'] ?? 'mensal');

        // Buscar nome do plano
        $planoNome = 'Plano Pago';
        if (!empty($pagamento['plano_id'])) {
            $stmt = $pdo->prepare("SELECT nome FROM planos WHERE id = ?");
            $stmt->execute([$pagamento['plano_id']]);
            $p = $stmt->fetch();
            if ($p) $planoNome = $p['nome'];
        }

        $valor = number_format((float)($pagamento['valor'] ?? 0), 2, ',', '.');

        $subject = 'PDV Pro - Licenca Ativada com Sucesso!';
        $body = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: 'Segoe UI', Arial, sans-serif; background: #f1f5f9; margin: 0; padding: 20px;">
<div style="max-width: 560px; margin: 0 auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.1);">

    <div style="background: linear-gradient(135deg, #0f172a, #1e40af); padding: 30px; text-align: center;">
        <h1 style="color: #fff; margin: 0; font-size: 24px;">PDV Pro</h1>
        <p style="color: #93c5fd; margin: 8px 0 0;">Pagamento Confirmado</p>
    </div>

    <div style="padding: 30px;">
        <p style="color: #334155; font-size: 16px;">Ola, <strong>{$nome}</strong>!</p>

        <p style="color: #334155;">Seu pagamento foi confirmado e sua licenca esta ativa.</p>

        <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 20px; margin: 20px 0; text-align: center;">
            <p style="color: #166534; font-size: 14px; margin: 0 0 8px;">Sua chave de licenca:</p>
            <p style="color: #166534; font-size: 28px; font-weight: 800; letter-spacing: 2px; margin: 0; font-family: monospace;">{$chave}</p>
        </div>

        <table style="width: 100%; border-collapse: collapse; margin: 16px 0;">
            <tr>
                <td style="padding: 8px 0; color: #64748b; font-size: 14px;">Plano:</td>
                <td style="padding: 8px 0; color: #1e293b; font-size: 14px; font-weight: 600; text-align: right;">{$planoNome}</td>
            </tr>
            <tr>
                <td style="padding: 8px 0; color: #64748b; font-size: 14px;">Periodo:</td>
                <td style="padding: 8px 0; color: #1e293b; font-size: 14px; font-weight: 600; text-align: right;">{$tipo}</td>
            </tr>
            <tr>
                <td style="padding: 8px 0; color: #64748b; font-size: 14px;">Valor pago:</td>
                <td style="padding: 8px 0; color: #1e293b; font-size: 14px; font-weight: 600; text-align: right;">R$ {$valor}</td>
            </tr>
        </table>

        <div style="background: #eff6ff; border-radius: 8px; padding: 16px; margin: 20px 0;">
            <p style="color: #1e40af; font-size: 14px; font-weight: 600; margin: 0 0 8px;">Como ativar:</p>
            <ol style="color: #334155; font-size: 13px; margin: 0; padding-left: 20px;">
                <li>Abra o PDV Pro no seu computador</li>
                <li>A ativacao sera feita automaticamente</li>
                <li>Caso nao ative sozinho, va em Licenca &gt; Ativar e cole a chave acima</li>
            </ol>
        </div>

        <p style="color: #94a3b8; font-size: 12px; margin-top: 24px;">
            Guarde esta chave em local seguro. Ela esta vinculada ao seu computador e nao pode ser usada em outra maquina.<br>
            Em caso de duvidas, entre em contato com o suporte.
        </p>
    </div>

    <div style="background: #f8fafc; padding: 16px; text-align: center; border-top: 1px solid #e2e8f0;">
        <p style="color: #94a3b8; font-size: 12px; margin: 0;">PDV Pro - Sistema de Ponto de Venda</p>
    </div>
</div>
</body>
</html>
HTML;

        sendMail($pdo, $email, $subject, $body);

        logApi($pdo, $pagamento['licenca_id'] ?? null, $pagamento['cliente_id'] ?? null, 'email_licenca_enviado', [
            'email' => $email,
            'chave' => substr($chave, 0, 4) . '-****-****-****',
        ]);
    } catch (\Throwable $e) {
        // Nao bloquear webhook se email falhar
        logApi($pdo, $pagamento['licenca_id'] ?? null, $pagamento['cliente_id'] ?? null, 'email_licenca_erro', [
            'erro' => $e->getMessage(),
        ]);
    }
}

// ============================================
//   Handlers SaaS
// ============================================

function handleRegistrarSaas(PDO $pdo): void {
    $input = getInput();

    // Autenticar via API_SECRET (chamado pelo sistema SaaS, nao pelo usuario)
    $secret = $input['api_secret'] ?? ($_SERVER['HTTP_X_API_SECRET'] ?? '');
    if (empty($secret) || !hash_equals(API_SECRET, $secret)) {
        jsonResponse(403, ['ok' => false, 'mensagem' => 'Acesso nao autorizado.']);
    }

    $razaoSocial = trim($input['razao_social'] ?? '');
    $nomeFantasia = trim($input['nome_fantasia'] ?? '') ?: null;
    $cnpj = trim($input['cnpj'] ?? '') ?: null;
    $cpf = trim($input['cpf'] ?? '') ?: null;
    $email = trim($input['email'] ?? '') ?: null;
    $telefone = trim($input['telefone'] ?? '') ?: null;
    $whatsapp = trim($input['whatsapp'] ?? '') ?: null;
    $contatoNome = trim($input['contato_nome'] ?? '') ?: null;
    $cidade = trim($input['cidade'] ?? '') ?: null;
    $uf = trim($input['uf'] ?? '') ?: null;
    $planoSlug = trim($input['plano_slug'] ?? 'saas-free');

    // Validacoes
    if (empty($razaoSocial)) {
        jsonResponse(400, ['ok' => false, 'mensagem' => 'Razao Social e obrigatoria.']);
    }
    if (empty($cnpj) && empty($cpf)) {
        jsonResponse(400, ['ok' => false, 'mensagem' => 'CNPJ ou CPF e obrigatorio.']);
    }
    if (empty($email)) {
        jsonResponse(400, ['ok' => false, 'mensagem' => 'Email e obrigatorio.']);
    }

    // Verificar se CNPJ/CPF ja existe
    if ($cnpj) {
        $stmt = $pdo->prepare("SELECT id FROM clientes WHERE cnpj = ?");
        $stmt->execute([$cnpj]);
        if ($stmt->fetch()) {
            jsonResponse(409, ['ok' => false, 'mensagem' => 'CNPJ ja cadastrado no sistema.']);
        }
    }

    // Buscar plano SaaS
    $stmt = $pdo->prepare("SELECT * FROM planos WHERE slug = ? AND tipo_produto = 'saas' AND ativo = 1 LIMIT 1");
    $stmt->execute([$planoSlug]);
    $plano = $stmt->fetch();

    if (!$plano) {
        // Fallback: buscar qualquer plano saas free/trial
        $stmt = $pdo->prepare("SELECT * FROM planos WHERE tipo_produto = 'saas' AND preco = 0 AND ativo = 1 LIMIT 1");
        $stmt->execute();
        $plano = $stmt->fetch();
    }

    $planoId = $plano ? (int)$plano['id'] : null;

    // Calcular data de vencimento do trial
    $trialDias = 15; // padrao
    if ($plano && !empty($plano['recursos'])) {
        $recursos = json_decode($plano['recursos'], true);
        if (isset($recursos['trial_dias'])) {
            $trialDias = (int)$recursos['trial_dias'];
        }
    }
    $dataVencimento = date('Y-m-d H:i:s', strtotime("+{$trialDias} days"));

    // Se plano pago, vencimento sera controlado pelo pagamento
    if ($plano && (float)$plano['preco'] > 0) {
        $dataVencimento = date('Y-m-d H:i:s', strtotime('+3 days')); // 3 dias para pagar
    }

    // Gerar API token e chave de licenca
    $apiToken = bin2hex(random_bytes(32));
    $chave = 'S' . strtoupper(bin2hex(random_bytes(7))); // S = SaaS
    $chave = substr($chave, 0, 4) . '-' . substr($chave, 4, 4) . '-' . substr($chave, 8, 4) . '-' . substr($chave, 12, 4);

    // Garantir chave unica
    $check = $pdo->prepare("SELECT COUNT(*) FROM licencas WHERE chave = ?");
    $check->execute([$chave]);
    while ($check->fetchColumn() > 0) {
        $chave = 'S' . strtoupper(bin2hex(random_bytes(7)));
        $chave = substr($chave, 0, 4) . '-' . substr($chave, 4, 4) . '-' . substr($chave, 8, 4) . '-' . substr($chave, 12, 4);
        $check->execute([$chave]);
    }

    try {
        $pdo->beginTransaction();

        // Criar cliente
        $stmt = $pdo->prepare("INSERT INTO clientes (razao_social, nome_fantasia, cnpj, cpf, email, telefone, whatsapp, contato_nome, cidade, uf, plano_id, status, api_token) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $status = ($plano && (float)$plano['preco'] == 0) ? 'trial' : 'ativo';
        $stmt->execute([$razaoSocial, $nomeFantasia, $cnpj, $cpf, $email, $telefone, $whatsapp, $contatoNome, $cidade, $uf, $planoId, $status, $apiToken]);
        $clienteId = (int)$pdo->lastInsertId();

        // Criar licenca SaaS
        $stmt = $pdo->prepare("INSERT INTO licencas (chave, cliente_id, plano_id, tipo, status, data_ativacao, data_vencimento, ultimo_check, ip_ativacao, observacoes) VALUES (?,?,?,?,?,NOW(),?,NOW(),?,?)");
        $stmt->execute([
            $chave,
            $clienteId,
            $planoId,
            $plano ? $plano['periodo'] : 'mensal',
            'ativa',
            $dataVencimento,
            $_SERVER['REMOTE_ADDR'] ?? '',
            'Registro SaaS - ' . ($plano ? $plano['nome'] : 'Sem plano')
        ]);
        $licencaId = (int)$pdo->lastInsertId();

        $pdo->commit();

        logApi($pdo, $licencaId, $clienteId, 'registrar_saas', [
            'cnpj' => $cnpj,
            'email' => $email,
            'chave' => $chave,
            'plano' => $planoSlug,
        ]);

        $response = [
            'ok' => true,
            'mensagem' => 'Cliente SaaS registrado com sucesso.',
            'chave' => $chave,
            'cliente_id' => $clienteId,
            'licenca_id' => $licencaId,
            'api_token' => $apiToken,
            'plano' => $plano ? $plano['nome'] : null,
            'plano_slug' => $plano ? $plano['slug'] : null,
            'trial_dias' => ($plano && (float)$plano['preco'] == 0) ? $trialDias : null,
            'data_vencimento' => $dataVencimento,
        ];

        // Se plano pago, criar cobranca no Asaas
        if ($plano && (float)$plano['preco'] > 0) {
            try {
                require_once APP_PATH . '/includes/asaas.php';
                $asaas = new Asaas($pdo);
                $cpfCnpj = $cnpj ?: $cpf;
                $customer = $asaas->getOrCreateCustomer([
                    'razao_social' => $razaoSocial,
                    'nome_fantasia' => $nomeFantasia ?: $razaoSocial,
                    'cnpj' => $cnpj,
                    'cpf' => $cpf,
                    'email' => $email,
                    'telefone' => $telefone ?? $whatsapp ?? '',
                ]);
                $asaasCustomerId = $customer['id'];
                $pdo->prepare("UPDATE clientes SET asaas_customer_id = ? WHERE id = ?")->execute([$asaasCustomerId, $clienteId]);

                $subscription = $asaas->createSubscription([
                    'customer' => $asaasCustomerId,
                    'billingType' => 'UNDEFINED',
                    'value' => (float)$plano['preco'],
                    'cycle' => strtoupper($plano['periodo'] === 'mensal' ? 'MONTHLY' : ($plano['periodo'] === 'trimestral' ? 'QUARTERLY' : 'YEARLY')),
                    'description' => 'PDV Pro SaaS - ' . $plano['nome'],
                    'externalReference' => "saas-{$clienteId}-{$licencaId}",
                ]);

                $paymentUrl = $subscription['paymentLink'] ?? ($subscription['invoiceUrl'] ?? null);
                $response['payment_url'] = $paymentUrl;
                $response['asaas_subscription_id'] = $subscription['id'] ?? null;

            } catch (\Throwable $e) {
                $response['payment_error'] = 'Cobranca sera gerada em breve. Entre em contato se necessario.';
                logApi($pdo, $licencaId, $clienteId, 'registrar_saas_asaas_erro', ['erro' => $e->getMessage()]);
            }
        }

        jsonResponse(201, $response);
    } catch (\PDOException $e) {
        $pdo->rollBack();
        jsonResponse(500, ['ok' => false, 'mensagem' => 'Erro ao cadastrar. Tente novamente.']);
    }
}

function handleValidarSaas(PDO $pdo): void {
    $input = getInput();

    $secret = $input['api_secret'] ?? ($_SERVER['HTTP_X_API_SECRET'] ?? '');
    if (empty($secret) || !hash_equals(API_SECRET, $secret)) {
        jsonResponse(403, ['ok' => false, 'mensagem' => 'Acesso nao autorizado.']);
    }

    $chave = strtoupper(trim($input['chave'] ?? ''));
    if (empty($chave)) {
        jsonResponse(400, ['ok' => false, 'mensagem' => 'Chave obrigatoria.']);
    }

    $stmt = $pdo->prepare("
        SELECT l.*, p.nome as plano_nome, p.slug as plano_slug, p.preco as plano_preco,
               p.limite_nfce, p.limite_terminais, p.recursos as plano_recursos,
               c.status as cliente_status, c.razao_social, c.email
        FROM licencas l
        LEFT JOIN planos p ON l.plano_id = p.id
        LEFT JOIN clientes c ON l.cliente_id = c.id
        WHERE l.chave = ?
    ");
    $stmt->execute([$chave]);
    $licenca = $stmt->fetch();

    if (!$licenca) {
        jsonResponse(404, ['ok' => false, 'status' => 'invalida', 'mensagem' => 'Licenca nao encontrada.']);
    }

    // Atualizar ultimo check
    $pdo->prepare("UPDATE licencas SET ultimo_check = NOW() WHERE id = ?")->execute([$licenca['id']]);

    // Verificar vencimento
    $vencida = false;
    if ($licenca['data_vencimento'] && strtotime($licenca['data_vencimento']) < time()) {
        $vencida = true;
    }

    // Verificar status
    $ativa = in_array($licenca['status'], ['ativa', 'disponivel']) && !$vencida;
    $bloqueada = in_array($licenca['status'], ['revogada', 'bloqueada']);
    $clienteInativo = in_array($licenca['cliente_status'], ['inativo', 'inadimplente']);

    if ($bloqueada) {
        jsonResponse(200, ['ok' => false, 'status' => 'bloqueada', 'mensagem' => 'Licenca bloqueada. Entre em contato com o suporte.']);
    }

    if ($clienteInativo) {
        jsonResponse(200, ['ok' => false, 'status' => 'inadimplente', 'mensagem' => 'Conta inadimplente. Regularize seu pagamento.']);
    }

    if ($vencida) {
        jsonResponse(200, [
            'ok' => false,
            'status' => 'expirada',
            'mensagem' => 'Periodo de teste expirado. Escolha um plano para continuar.',
            'data_vencimento' => $licenca['data_vencimento'],
        ]);
    }

    // Calcular dias restantes
    $diasRestantes = null;
    if ($licenca['data_vencimento']) {
        $diasRestantes = max(0, (int)ceil((strtotime($licenca['data_vencimento']) - time()) / 86400));
    }

    jsonResponse(200, [
        'ok' => true,
        'status' => 'ativa',
        'plano' => $licenca['plano_nome'],
        'plano_slug' => $licenca['plano_slug'],
        'plano_preco' => (float)($licenca['plano_preco'] ?? 0),
        'limite_nfce' => (int)($licenca['limite_nfce'] ?? 0),
        'limite_terminais' => (int)($licenca['limite_terminais'] ?? 0),
        'data_vencimento' => $licenca['data_vencimento'],
        'dias_restantes' => $diasRestantes,
        'cliente' => $licenca['razao_social'],
    ]);
}
