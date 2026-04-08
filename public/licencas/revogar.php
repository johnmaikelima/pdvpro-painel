<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    flash('danger', 'Licenca invalida.');
    redirect('index.php');
}

$stmt = $pdo->prepare("UPDATE licencas SET status = 'revogada' WHERE id = ? AND status = 'ativa'");
$stmt->execute([$id]);

if ($stmt->rowCount()) {
    flash('success', 'Licenca revogada com sucesso.');
} else {
    flash('warning', 'Licenca nao encontrada ou ja estava inativa.');
}

redirect($_SERVER['HTTP_REFERER'] ?? 'index.php');
