<?php
// ============================================
//   PDV Pro - Painel Admin - Configuracoes
// ============================================

// Banco de dados MySQL (usa env do Coolify ou fallback local)
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'pdvpro_admin');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// App
define('APP_NAME', 'PDV Pro Admin');
define('APP_VERSION', '1.0.0');
define('APP_URL', getenv('APP_URL') ?: 'http://localhost:8080');

// Caminhos
define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', __DIR__);

// Seguranca
define('SESSION_LIFETIME', 28800); // 8 horas
define('CSRF_TOKEN_NAME', '_token');

// API
define('API_SECRET', $_ENV['API_SECRET'] ?? 'pdvpro_api_2026_secret_key_change_this');

// Licenca - defaults
define('TRIAL_DAYS', 15);
define('FREE_NFCE_LIMIT', 50);
