<?php
require_once dirname(__DIR__, 2) . '/app/config.php';
require_once APP_PATH . '/includes/functions.php';

try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    die('Servidor indisponivel.');
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    die('ID obrigatorio.');
}

$stmt = $pdo->prepare("SELECT * FROM versoes WHERE id = ? AND ativa = 1");
$stmt->execute([$id]);
$versao = $stmt->fetch();

if (!$versao || !$versao['arquivo_path'] || !file_exists($versao['arquivo_path'])) {
    http_response_code(404);
    die('Arquivo nao encontrado.');
}

// Incrementar downloads (exceto admin preview)
if (empty($_GET['admin'])) {
    $pdo->prepare("UPDATE versoes SET downloads = downloads + 1 WHERE id = ?")->execute([$id]);
}

// Enviar arquivo
$filePath = $versao['arquivo_path'];
$fileName = $versao['arquivo_nome'];
$fileSize = filesize($filePath);

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . $fileSize);
header('Cache-Control: no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

readfile($filePath);
exit;
