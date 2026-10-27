<?php

declare(strict_types=1);

/**
 * Mail versturen zoals Asclepius (web/content/mail.php): SMTP uit $mailSettings['smtp'] in auth.php,
 * anders PHP mail(). In tests vangt $GLOBALS['fmMailCapture'] (array) de berichten op.
 *
 * $mailSettings = ['from_email' => 'kvtbot@kvt.nl', 'from_name' => 'Forum Magnum',
 *     'smtp' => ['host' => '…', 'port' => 587, 'encryption' => 'tls', 'username' => '…', 'password' => '…']];
 */

function fm_mail_header(string $value): string
{
    return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
}

/** @return array{0:string,1:string} [headers, body] als multipart/alternative. */
function fm_mail_mime(string $fromEmail, string $fromName, string $to, string $subject, string $plain, string $html): array
{
    $boundary = 'fm_' . bin2hex(random_bytes(12));
    $headers = [
        'From: ' . ($fromName !== '' ? fm_mail_header($fromName) . ' <' . $fromEmail . '>' : $fromEmail),
        'To: ' . $to,
        'Subject: ' . fm_mail_header($subject),
        'Date: ' . date(DATE_RFC2822),
        'Message-ID: <' . uniqid('forum-', true) . '@kvt.nl>',
        'MIME-Version: 1.0',
        'Auto-Submitted: auto-generated',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    $norm = static fn (string $s): string => str_replace(["\r\n", "\r"], "\n", $s);
    $body = '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $norm($plain) . "\r\n"
        . '--' . $boundary . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $norm($html) . "\r\n"
        . '--' . $boundary . '--';

    return [implode("\r\n", $headers), $body];
}

/** @param resource $socket */
function fm_smtp_expect($socket, array $codes): void
{
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }
    if (!in_array((int) substr($response, 0, 3), $codes, true)) {
        throw new RuntimeException('SMTP: ' . trim($response));
    }
}

/** @param resource $socket */
function fm_smtp_cmd($socket, string $command, array $codes): void
{
    fwrite($socket, $command . "\r\n");
    fm_smtp_expect($socket, $codes);
}

function fm_smtp_send(array $smtp, string $fromEmail, string $to, string $headers, string $body): bool
{
    $host = trim((string) ($smtp['host'] ?? ''));
    $port = (int) ($smtp['port'] ?? 25);
    if ($host === '' || $port <= 0) {
        return false;
    }
    $timeout = max(5, (int) ($smtp['timeout'] ?? 20));
    $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
    $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
    if ($socket === false) {
        return false;
    }
    stream_set_timeout($socket, $timeout);
    try {
        fm_smtp_expect($socket, [220]);
        fm_smtp_cmd($socket, 'EHLO forum-magnum.kvt.nl', [250]);
        if (($smtp['encryption'] ?? '') === 'tls') {
            fm_smtp_cmd($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS');
            }
            fm_smtp_cmd($socket, 'EHLO forum-magnum.kvt.nl', [250]);
        }
        $user = trim((string) ($smtp['username'] ?? ''));
        if ($user !== '') {
            fm_smtp_cmd($socket, 'AUTH LOGIN', [334]);
            fm_smtp_cmd($socket, base64_encode($user), [334]);
            fm_smtp_cmd($socket, base64_encode((string) ($smtp['password'] ?? '')), [235]);
        }
        fm_smtp_cmd($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        fm_smtp_cmd($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
        fm_smtp_cmd($socket, 'DATA', [354]);
        fwrite($socket, $headers . "\r\n\r\n" . str_replace("\n.", "\n..", $body) . "\r\n.\r\n");
        fm_smtp_expect($socket, [250]);
        fm_smtp_cmd($socket, 'QUIT', [221]);
        fclose($socket);

        return true;
    } catch (Throwable $e) {
        fclose($socket);
        error_log('Forum Magnum mail: ' . $e->getMessage());

        return false;
    }
}

/** Eén mail naar één ontvanger. true = afgeleverd bij SMTP of mail(). */
function fm_send_mail(string $to, string $subject, string $plain, string $html): bool
{
    $to = strtolower(trim($to));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    if (isset($GLOBALS['fmMailCapture']) && is_array($GLOBALS['fmMailCapture'])) {
        $GLOBALS['fmMailCapture'][] = ['to' => $to, 'subject' => $subject, 'plain' => $plain, 'html' => $html];

        return true;
    }
    $settings = is_array($GLOBALS['mailSettings'] ?? null) ? $GLOBALS['mailSettings'] : [];
    $fromEmail = (string) ($settings['from_email'] ?? 'kvtbot@kvt.nl');
    $fromName = (string) ($settings['from_name'] ?? 'Forum Magnum');
    [$headers, $body] = fm_mail_mime($fromEmail, $fromName, $to, $subject, $plain, $html);
    $smtp = (array) ($settings['smtp'] ?? []);
    if ($smtp !== [] && fm_smtp_send($smtp, $fromEmail, $to, $headers, $body)) {
        return true;
    }
    // mail() zet To/Subject zelf.
    $mailHeaders = implode("\r\n", array_filter(explode("\r\n", $headers), static fn ($l) => !str_starts_with($l, 'To: ') && !str_starts_with($l, 'Subject: ')));

    return @mail($to, fm_mail_header($subject), $body, $mailHeaders);
}
