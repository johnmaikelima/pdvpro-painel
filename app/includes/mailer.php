<?php
/**
 * Envio de email via SMTP puro (sem dependencias externas)
 */
function sendMail(PDO $pdo, string $to, string $subject, string $bodyHtml): void
{
    $host = getConfig($pdo, 'smtp_host');
    $port = (int)getConfig($pdo, 'smtp_port', '587');
    $user = getConfig($pdo, 'smtp_user');
    $pass = getConfig($pdo, 'smtp_pass');
    $fromName = getConfig($pdo, 'smtp_from_name', 'Kaixa');
    $fromEmail = getConfig($pdo, 'smtp_from_email') ?: $user;
    $encryption = getConfig($pdo, 'smtp_encryption', 'tls');

    if (empty($host) || empty($user) || empty($pass)) {
        throw new Exception('SMTP nao configurado. Acesse Configuracoes > Email.');
    }

    // Conectar ao servidor SMTP
    $prefix = ($encryption === 'ssl') ? 'ssl://' : '';
    $socket = @fsockopen($prefix . $host, $port, $errno, $errstr, 10);
    if (!$socket) {
        throw new Exception("Nao foi possivel conectar ao SMTP: {$errstr} ({$errno})");
    }

    stream_set_timeout($socket, 10);

    // Ler banner
    smtpRead($socket);

    // EHLO
    smtpWrite($socket, "EHLO kaixa\r\n");
    smtpRead($socket);

    // STARTTLS se for TLS
    if ($encryption === 'tls') {
        smtpWrite($socket, "STARTTLS\r\n");
        $resp = smtpRead($socket);
        if (strpos($resp, '220') !== 0) {
            throw new Exception('STARTTLS nao suportado pelo servidor.');
        }
        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);

        // EHLO novamente apos TLS
        smtpWrite($socket, "EHLO kaixa\r\n");
        smtpRead($socket);
    }

    // AUTH LOGIN
    smtpWrite($socket, "AUTH LOGIN\r\n");
    smtpRead($socket);
    smtpWrite($socket, base64_encode($user) . "\r\n");
    smtpRead($socket);
    smtpWrite($socket, base64_encode($pass) . "\r\n");
    $resp = smtpRead($socket);
    if (strpos($resp, '235') !== 0) {
        fclose($socket);
        throw new Exception('Falha na autenticacao SMTP. Verifique usuario e senha.');
    }

    // MAIL FROM
    smtpWrite($socket, "MAIL FROM:<{$fromEmail}>\r\n");
    smtpRead($socket);

    // RCPT TO
    smtpWrite($socket, "RCPT TO:<{$to}>\r\n");
    smtpRead($socket);

    // DATA
    smtpWrite($socket, "DATA\r\n");
    smtpRead($socket);

    // Montar email
    $boundary = md5(uniqid(time()));
    $headers = "From: {$fromName} <{$fromEmail}>\r\n";
    $headers .= "To: {$to}\r\n";
    $headers .= "Subject: {$subject}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: base64\r\n";

    $body = $headers . "\r\n" . chunk_split(base64_encode($bodyHtml));
    $body .= "\r\n.\r\n";

    smtpWrite($socket, $body);
    smtpRead($socket);

    // QUIT
    smtpWrite($socket, "QUIT\r\n");
    fclose($socket);
}

function smtpWrite($socket, string $data): void
{
    fwrite($socket, $data);
}

function smtpRead($socket): string
{
    $response = '';
    while ($line = fgets($socket, 515)) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') break;
    }
    return $response;
}
