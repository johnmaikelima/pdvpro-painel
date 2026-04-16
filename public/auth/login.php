<?php
require_once dirname(__DIR__, 2) . '/app/config.php';
require_once APP_PATH . '/includes/functions.php';
session_start();

if (isLoggedIn()) {
    redirect('/dashboard/');
}

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $erro = 'Token CSRF invalido.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $senha = $_POST['senha'] ?? '';

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Rate limiting: max 5 tentativas por IP a cada 15 minutos
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $loginKey = 'login_attempts_' . md5($ip);
        $attempts = $_SESSION[$loginKey] ?? ['count' => 0, 'first' => time()];

        if ($attempts['count'] >= 5 && (time() - $attempts['first']) < 900) {
            $restante = ceil((900 - (time() - $attempts['first'])) / 60);
            $erro = "Muitas tentativas. Aguarde {$restante} minutos.";
        } else {
            // Resetar contador se passou 15min
            if ((time() - $attempts['first']) >= 900) {
                $attempts = ['count' => 0, 'first' => time()];
            }

            $stmt = $pdo->prepare("SELECT * FROM admins WHERE email = ? AND ativo = 1 LIMIT 1");
            $stmt->execute([$email]);
            $admin = $stmt->fetch();

            if ($admin && password_verify($senha, $admin['senha'])) {
                // Login OK: resetar tentativas
                unset($_SESSION[$loginKey]);
                session_regenerate_id(true);

                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_user'] = [
                    'id' => $admin['id'],
                    'nome' => $admin['nome'],
                    'email' => $admin['email'],
                ];

                $pdo->prepare("UPDATE admins SET ultimo_acesso = NOW() WHERE id = ?")->execute([$admin['id']]);

                redirect('/dashboard/');
            } else {
                $attempts['count']++;
                $_SESSION[$loginKey] = $attempts;
                $erro = 'Email ou senha incorretos.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="<?= APP_URL ?>/assets/css/admin.css" rel="stylesheet">
</head>
<body>
<div class="login-container">
    <div class="login-card">
        <div class="text-center mb-4">
            <i class="fas fa-cash-register fa-3x text-primary mb-3"></i>
            <h3>Kaixa</h3>
            <p class="subtitle">Painel Administrativo</p>
        </div>

        <?php if ($erro): ?>
            <div class="alert alert-danger"><?= e($erro) ?></div>
        <?php endif; ?>

        <form method="POST">
            <?= csrfField() ?>

            <div class="mb-3">
                <label class="form-label">Email</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                    <input type="email" name="email" class="form-control" placeholder="admin@pdvpro.com.br"
                           value="<?= e($_POST['email'] ?? '') ?>" required autofocus>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label">Senha</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                    <input type="password" name="senha" class="form-control" placeholder="Digite sua senha" required>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-2">
                <i class="fas fa-sign-in-alt me-2"></i>Entrar
            </button>
        </form>

        <div class="text-center mt-4">
            <small class="text-muted"><?= APP_NAME ?> v<?= APP_VERSION ?></small>
        </div>
    </div>
</div>
</body>
</html>
