<?php
/**
 * Sending mail, without a library.
 *
 * Two transports:
 *   'mail'  PHP's mail(), which needs a working MTA on the machine. Fine on a
 *           normal hosting package, almost never configured on a NAS.
 *   'smtp'  a direct SMTP conversation, so any mailbox with SMTP access works.
 *
 * Only plain text is sent. The one mail this app produces is a reset link, and
 * an HTML part would add nothing but ways to get it wrong.
 */

declare(strict_types=1);

function mail_config(): array
{
    return array_merge([
        'enabled'   => false,
        'transport' => 'mail',
        'from'      => '',
        'from_name' => 'Apiary-Journal',
        'smtp'      => [],
    ], config()['mail'] ?? []);
}

function mail_enabled(): bool
{
    $c = mail_config();
    return !empty($c['enabled']) && $c['from'] !== '';
}

/**
 * Send one plain text message. Returns false and logs on failure; the caller
 * decides whether that is worth telling the user about.
 */
function send_mail(string $to, string $subject, string $body): bool
{
    if (!mail_enabled()) {
        error_log('[apiary-journal] mail requested but not configured');
        return false;
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('[apiary-journal] refusing to send to an invalid address');
        return false;
    }
    $c = mail_config();
    try {
        return ($c['transport'] === 'smtp')
            ? smtp_send($to, $subject, $body, $c)
            : mail_send_local($to, $subject, $body, $c);
    } catch (Throwable $e) {
        error_log('[apiary-journal] sending mail failed: ' . $e->getMessage());
        return false;
    }
}

/** RFC 2047 encoding, so umlauts survive a subject line. */
function mail_encode_header(string $value): string
{
    // A header must never span lines other than by folding; strip anything
    // that could start a new one.
    $value = str_replace(["\r", "\n"], ' ', $value);
    return preg_match('/[^\x20-\x7E]/', $value)
        ? '=?UTF-8?B?' . base64_encode($value) . '?='
        : $value;
}

function mail_headers(string $to, string $subject, array $c): array
{
    $from = $c['from'];
    $name = mail_encode_header((string)$c['from_name']);
    $host = preg_replace('/[^A-Za-z0-9.\-]/', '', (string)(explode('@', $from)[1] ?? 'localhost'));

    return [
        'Date'                      => date('r'),
        'From'                      => ($name !== '' ? "{$name} <{$from}>" : $from),
        'To'                        => $to,
        'Subject'                   => mail_encode_header($subject),
        'Message-ID'                => '<' . bin2hex(random_bytes(16)) . '@' . $host . '>',
        'MIME-Version'              => '1.0',
        'Content-Type'              => 'text/plain; charset=utf-8',
        'Content-Transfer-Encoding' => 'base64',
        'Auto-Submitted'            => 'auto-generated',
    ];
}

function mail_send_local(string $to, string $subject, string $body, array $c): bool
{
    $headers = mail_headers($to, $subject, $c);
    // mail() takes recipient and subject as arguments, not as headers.
    unset($headers['To'], $headers['Subject']);
    $lines = [];
    foreach ($headers as $k => $v) {
        $lines[] = "{$k}: {$v}";
    }
    return mail(
        $to,
        mail_encode_header($subject),
        chunk_split(base64_encode($body), 76, "\r\n"),
        implode("\r\n", $lines)
    );
}

/* --------------------------------------------------------------- SMTP ---- */

function smtp_read(&$fh, string $expect): string
{
    $reply = '';
    while (($line = fgets($fh, 1024)) !== false) {
        $reply .= $line;
        // The last line of a reply has a space in the fourth column, a
        // continuation has a hyphen.
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }
    if (strncmp($reply, $expect, strlen($expect)) !== 0) {
        throw new RuntimeException('SMTP expected ' . $expect . ', got: ' . trim($reply));
    }
    return $reply;
}

function smtp_write($fh, string $line): void
{
    if (fwrite($fh, $line . "\r\n") === false) {
        throw new RuntimeException('SMTP write failed');
    }
}

function smtp_send(string $to, string $subject, string $body, array $c): bool
{
    $s = array_merge([
        'host' => 'localhost', 'port' => 587, 'security' => 'starttls',
        'username' => '', 'password' => '', 'timeout' => 15,
    ], (array)$c['smtp']);

    $target  = ($s['security'] === 'tls' ? 'ssl://' : 'tcp://') . $s['host'] . ':' . (int)$s['port'];
    $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fh      = @stream_socket_client($target, $errNo, $errStr, (int)$s['timeout'],
                                     STREAM_CLIENT_CONNECT, $context);
    if (!$fh) {
        throw new RuntimeException("cannot reach {$target}: {$errStr}");
    }
    stream_set_timeout($fh, (int)$s['timeout']);

    try {
        smtp_read($fh, '220');
        $helo = gethostname() ?: 'localhost';
        smtp_write($fh, 'EHLO ' . $helo);
        smtp_read($fh, '250');

        if ($s['security'] === 'starttls') {
            smtp_write($fh, 'STARTTLS');
            smtp_read($fh, '220');
            if (!stream_socket_enable_crypto($fh, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS negotiation failed');
            }
            // The session restarts after the upgrade.
            smtp_write($fh, 'EHLO ' . $helo);
            smtp_read($fh, '250');
        }

        if ($s['username'] !== '') {
            smtp_write($fh, 'AUTH LOGIN');
            smtp_read($fh, '334');
            smtp_write($fh, base64_encode((string)$s['username']));
            smtp_read($fh, '334');
            smtp_write($fh, base64_encode((string)$s['password']));
            smtp_read($fh, '235');
        }

        smtp_write($fh, 'MAIL FROM:<' . $c['from'] . '>');
        smtp_read($fh, '250');
        smtp_write($fh, 'RCPT TO:<' . $to . '>');
        smtp_read($fh, '25');
        smtp_write($fh, 'DATA');
        smtp_read($fh, '354');

        $message = '';
        foreach (mail_headers($to, $subject, $c) as $k => $v) {
            $message .= "{$k}: {$v}\r\n";
        }
        $message .= "\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
        // A lone dot on its own line ends DATA, so any such line is escaped.
        $message = preg_replace('/^\./m', '..', $message);

        smtp_write($fh, $message . "\r\n.");
        smtp_read($fh, '250');
        smtp_write($fh, 'QUIT');
    } finally {
        fclose($fh);
    }
    return true;
}
