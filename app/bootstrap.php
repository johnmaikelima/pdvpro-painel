<?php
// ============================================
//   PDV Pro - Painel Admin - Bootstrap
// ============================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

// Sessao
session_start();

// Conexao MySQL
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
    );
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    die('Erro ao conectar no banco de dados: ' . $e->getMessage() .
        '<br><br>Verifique se o MySQL esta rodando e se o banco "' . DB_NAME . '" foi criado.' .
        '<br>Execute: <code>php app/migrate.php</code> para criar o banco.');
}
