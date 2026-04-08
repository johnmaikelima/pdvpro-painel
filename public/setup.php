<?php
// Endpoint temporario para rodar migrations no servidor
// REMOVER DEPOIS DE USAR
$secret = $_GET['key'] ?? '';
if ($secret !== 'pdvpro2026setup') {
    http_response_code(403);
    die('Acesso negado');
}

require_once dirname(__DIR__) . '/app/config.php';

echo "<pre>";
echo "PDV Pro Admin - Setup Remoto\n";
echo "============================\n\n";

try {
    $dsn = sprintf('mysql:host=%s;port=%s;charset=%s', DB_HOST, DB_PORT, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    die("Erro MySQL: " . $e->getMessage());
}

$pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `" . DB_NAME . "`");
echo "[OK] Banco '" . DB_NAME . "' pronto\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    arquivo VARCHAR(255) NOT NULL,
    executado_em DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$stmt = $pdo->query("SELECT arquivo FROM migrations");
$executadas = $stmt->fetchAll(PDO::FETCH_COLUMN);

$dir = dirname(__DIR__) . '/app/migrations';
$arquivos = glob($dir . '/*.sql');
sort($arquivos);

$novas = 0;
foreach ($arquivos as $arquivo) {
    $nome = basename($arquivo);
    if (in_array($nome, $executadas)) {
        echo "[--] {$nome} (ja executado)\n";
        continue;
    }

    echo "[>>] Executando {$nome}... ";
    $sql = file_get_contents($arquivo);
    $sql = preg_replace('/^--.*$/m', '', $sql);
    $statements = array_filter(array_map('trim', explode(';', $sql)), fn($s) => !empty($s));

    try {
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
        $pdo->prepare("INSERT INTO migrations (arquivo) VALUES (?)")->execute([$nome]);
        echo "OK\n";
        $novas++;
    } catch (PDOException $e) {
        echo "ERRO: " . $e->getMessage() . "\n";
    }
}

echo "\n{$novas} migration(s) executada(s).\n";

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "\nTabelas: " . implode(', ', $tables) . "\n";
echo "\nPRONTO! Agora delete este arquivo (setup.php) por seguranca.\n";
echo "</pre>";
