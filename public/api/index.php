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

match($action) {
    'registrar' => handleRegistrar($pdo),
    'validar' => handleValidar($pdo),
    'ativar' => handleAtivar($pdo),
    'reportar_nfce' => handleReportarNfce($pdo),
    'checar_atualizacao' => handleChecarAtualizacao($pdo),
    'webhook_asaas' => handleWebhookAsaas($pdo),
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

    logApi($pdo, $licenca['id'], $licenca['cliente_id'], 'validar', ['resultado' => 'ativa']);

    jsonResponse(200, [
        'status' => 'ativa',
        'tipo' => $licenca['tipo'],
        'plano' => $licenca['plano_nome'] ?? 'Sem plano',
        'dias_restantes' => $diasRestantes,
        'data_vencimento' => $licenca['data_vencimento'],
        'nfce_emitidas_mes' => $nfceEmitidas,
        'nfce_limite_mes' => $limiteNfce,
        'nfce_liberada' => $nfceLiberada,
        'alerta_vencimento' => $diasRestantes <= 7,
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
    // Versao mais recente do desktop
    jsonResponse(200, [
        'versao_atual' => APP_VERSION,
        'url_download' => null,
        'obrigatoria' => false,
        'changelog' => null,
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
        $stmt = $pdo->prepare("SELECT id FROM pagamentos WHERE referencia = ?");
        $stmt->execute([$asaasId]);
        $row = $stmt->fetch();
        if ($row) $pagamentoId = (int)$row['id'];
    }

    if (!$pagamentoId) {
        jsonResponse(200, ['ok' => true, 'msg' => 'Pagamento nao encontrado localmente, ignorado.']);
    }

    // Buscar dados do pagamento
    $stmt = $pdo->prepare("SELECT p.*, l.id as licenca_id, l.chave, l.tipo as licenca_tipo, c.email as cliente_email, c.razao_social FROM pagamentos p LEFT JOIN licencas l ON p.licenca_id = l.id LEFT JOIN clientes c ON p.cliente_id = c.id WHERE p.id = ?");
    $stmt->execute([$pagamentoId]);
    $pagamento = $stmt->fetch();

    if (!$pagamento) {
        jsonResponse(200, ['ok' => true, 'msg' => 'Pagamento local nao encontrado.']);
    }

    switch ($event) {
        case 'PAYMENT_CONFIRMED':
        case 'PAYMENT_RECEIVED':
            // Marcar pagamento como pago
            $pdo->prepare("UPDATE pagamentos SET status = 'pago', data_pagamento = NOW(), referencia = ? WHERE id = ?")
                ->execute([$asaasId, $pagamentoId]);

            // Ativar/renovar licenca
            if ($pagamento['licenca_id']) {
                $dias = diasPlano($pagamento['licenca_tipo'] ?? 'mensal');
                $vencimento = date('Y-m-d H:i:s', strtotime("+{$dias} days"));

                $pdo->prepare("UPDATE licencas SET status = 'ativa', data_vencimento = ? WHERE id = ?")
                    ->execute([$vencimento, $pagamento['licenca_id']]);

                // Atualizar cliente para ativo
                if ($pagamento['cliente_id']) {
                    $pdo->prepare("UPDATE clientes SET status = 'ativo' WHERE id = ?")->execute([$pagamento['cliente_id']]);
                }
            }

            logApi($pdo, $pagamento['licenca_id'], $pagamento['cliente_id'], 'webhook_pagamento_confirmado', [
                'pagamento_id' => $pagamentoId,
                'asaas_id' => $asaasId,
            ]);
            break;

        case 'PAYMENT_OVERDUE':
            $pdo->prepare("UPDATE pagamentos SET status = 'pendente' WHERE id = ?")->execute([$pagamentoId]);
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
