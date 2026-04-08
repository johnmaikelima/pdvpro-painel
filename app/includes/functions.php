<?php
// ============================================
//   PDV Pro - Painel Admin - Funcoes Helper
// ============================================

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function isLoggedIn(): bool {
    return !empty($_SESSION['admin_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        redirect('/auth/login.php');
    }
}

function currentUser(): ?array {
    return $_SESSION['admin_user'] ?? null;
}

function flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function csrf(): string {
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

function csrfField(): string {
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . csrf() . '">';
}

function verifyCsrf(): bool {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    return hash_equals($_SESSION[CSRF_TOKEN_NAME] ?? '', $token);
}

function e(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatDate(?string $date, string $format = 'd/m/Y'): string {
    if (!$date) return '-';
    return date($format, strtotime($date));
}

function formatDateTime(?string $date): string {
    if (!$date) return '-';
    return date('d/m/Y H:i', strtotime($date));
}

function formatMoney(float $value): string {
    return 'R$ ' . number_format($value, 2, ',', '.');
}

function generateLicenseKey(string $tipo = 'mensal'): string {
    $prefix = match($tipo) {
        'anual' => 'A',
        'trimestral' => 'T',
        default => 'M',
    };
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $key = $prefix;
    for ($i = 1; $i < 16; $i++) {
        $key .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return substr($key, 0, 4) . '-' . substr($key, 4, 4) . '-' . substr($key, 8, 4) . '-' . substr($key, 12, 4);
}

function generateApiToken(): string {
    return bin2hex(random_bytes(32));
}

function diasPlano(string $tipo): int {
    return match($tipo) {
        'mensal' => 30,
        'trimestral' => 90,
        'anual' => 365,
        default => 30,
    };
}

function statusBadge(string $status): string {
    $map = [
        'ativa' => 'success',
        'trial' => 'info',
        'expirada' => 'warning',
        'bloqueada' => 'danger',
        'revogada' => 'danger',
        'disponivel' => 'secondary',
        'cancelada' => 'dark',
    ];
    $color = $map[$status] ?? 'secondary';
    return '<span class="badge bg-' . $color . '">' . e(ucfirst($status)) . '</span>';
}

function planoBadge(string $plano): string {
    $map = [
        'free' => 'secondary',
        'basic' => 'primary',
        'pro' => 'warning',
    ];
    $color = $map[$plano] ?? 'secondary';
    return '<span class="badge bg-' . $color . '">' . e(strtoupper($plano)) . '</span>';
}

function getConfig(PDO $pdo, string $chave, string $default = ''): string {
    try {
        $stmt = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = ?");
        $stmt->execute([$chave]);
        $row = $stmt->fetch();
        return $row ? ($row['valor'] ?? $default) : $default;
    } catch (\Throwable $e) {
        return $default;
    }
}

function setConfig(PDO $pdo, string $chave, ?string $valor): void {
    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
        ->execute([$chave, $valor]);
}

function getAllConfigs(PDO $pdo, string $prefix = ''): array {
    if ($prefix) {
        $stmt = $pdo->prepare("SELECT chave, valor FROM configuracoes WHERE chave LIKE ?");
        $stmt->execute([$prefix . '%']);
    } else {
        $stmt = $pdo->query("SELECT chave, valor FROM configuracoes");
    }
    $configs = [];
    foreach ($stmt->fetchAll() as $row) {
        $configs[$row['chave']] = $row['valor'] ?? '';
    }
    return $configs;
}

function paginate(PDO $pdo, string $query, array $params, int $page, int $perPage = 20): array {
    $countQuery = preg_replace('/SELECT .+ FROM/i', 'SELECT COUNT(*) FROM', $query);
    $countQuery = preg_replace('/ORDER BY .+$/i', '', $countQuery);
    $stmt = $pdo->prepare($countQuery);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $totalPages = max(1, ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare($query . " LIMIT {$perPage} OFFSET {$offset}");
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    return [
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $totalPages,
    ];
}
