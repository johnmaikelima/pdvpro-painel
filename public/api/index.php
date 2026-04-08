<?php
// ============================================
//   PDV Pro - API REST de Licenciamento
//   Endpoints para o PDV Desktop se comunicar
// ============================================

require_once dirname(__DIR__, 2) . '/app/config.php';
require_once APP_PATH . '/includes/functions.php';

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
    'validar' => handleValidar($pdo),
    'ativar' => handleAtivar($pdo),
    'reportar_nfce' => handleReportarNfce($pdo),
    'checar_atualizacao' => handleChecarAtualizacao($pdo),
    'status' => jsonResponse(200, ['status' => 'online', 'version' => APP_VERSION]),
    default => jsonResponse(404, ['error' => 'Endpoint nao encontrado']),
};

// ============================================
//   Handlers
// ============================================

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
