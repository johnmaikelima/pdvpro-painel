<?php
// ============================================
//   Balcão PDV - Painel Admin - Migration Runner
// ============================================

require_once __DIR__ . '/config.php';

echo "Kaixa Admin - Migration Runner\n";
echo "================================\n\n";

// Conectar sem selecionar banco (para criar se necessario)
try {
    $dsn = sprintf('mysql:host=%s;port=%s;charset=%s', DB_HOST, DB_PORT, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    die("Erro ao conectar no MySQL: " . $e->getMessage() . "\n" .
        "Verifique se o MySQL/MariaDB esta rodando.\n");
}

// Criar banco se nao existe
$pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `" . DB_NAME . "`");
echo "[OK] Banco '" . DB_NAME . "' pronto\n";

// Verificar tabela de migrations
$pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    arquivo VARCHAR(255) NOT NULL,
    executado_em DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Buscar migrations ja executadas
$stmt = $pdo->query("SELECT arquivo FROM migrations");
$executadas = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Buscar arquivos de migration
$dir = __DIR__ . '/migrations';
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
    // Remover comentarios de linha inteira
    $sql = preg_replace('/^--.*$/m', '', $sql);
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn($s) => !empty($s)
    );

    try {
        foreach ($statements as $statement) {
            if (!empty(trim($statement))) {
                $pdo->exec($statement);
            }
        }

        $pdo->prepare("INSERT INTO migrations (arquivo) VALUES (?)")->execute([$nome]);
        echo "OK\n";
        $novas++;
    } catch (PDOException $e) {
        echo "ERRO: " . $e->getMessage() . "\n";
    }
}

if ($novas === 0) {
    echo "\nNenhuma migration nova para executar.\n";
} else {
    echo "\n{$novas} migration(s) executada(s) com sucesso.\n";
}

echo "\nPronto!\n";
