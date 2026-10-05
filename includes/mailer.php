<?php
declare(strict_types=1);

// Sending e-mail. The delivery mode comes from UIN_MAIL_MODE (see config.php):
//   log  - nothing is sent; the message is written to the PHP error log (development default)
//   smtp - plain SMTP without login to host:port, e.g. a local Mailpit on 127.0.0.1:1025
//   mail - PHP mail(), for a server that has a mail system configured
// Include with require_once.

// Sends a plain text message. Throws RuntimeException when delivery fails.
function send_mail(string $to, string $subject, string $body): void
{
    $cfg = (require __DIR__ . '/../config.php')['mail'];

    // A line break in these would let an attacker add mail headers.
    if (preg_match('/[\r\n]/', $to . $subject) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Bad mail header');
    }

    if ($cfg['mode'] === 'log') {
        error_log("ICQ1.0 mail (log mode) to $to, subject: $subject\n$body");
        return;
    }

    if ($cfg['mode'] === 'mail') {
        $headers = "From: {$cfg['from']}\r\nContent-Type: text/plain; charset=UTF-8";
        if (!mail($to, $subject, $body, $headers)) {
            throw new RuntimeException('mail() failed');
        }
        return;
    }

    smtp_send($cfg['host'], $cfg['port'], $cfg['from'], $to, $subject, $body);
}

// Minimal SMTP client: no login, no encryption. Enough for a local test server.
function smtp_send(string $host, int $port, string $from, string $to, string $subject, string $body): void
{
    $fp = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 5);
    if ($fp === false) {
        throw new RuntimeException("SMTP connect failed: $errstr");
    }
    stream_set_timeout($fp, 5);

    // Reads one (possibly multi-line) reply and checks its status code.
    $expect = static function (array $codes) use ($fp): void {
        do {
            $line = fgets($fp, 515);
            if ($line === false) {
                throw new RuntimeException('SMTP connection lost');
            }
        } while (isset($line[3]) && $line[3] === '-');

        if (!in_array(substr($line, 0, 3), $codes, true)) {
            throw new RuntimeException('SMTP error: ' . trim($line));
        }
    };
    $send = static function (string $cmd, array $codes) use ($fp, $expect): void {
        fwrite($fp, $cmd . "\r\n");
        $expect($codes);
    };

    try {
        $expect(['220']);
        $send('EHLO localhost', ['250']);
        $send("MAIL FROM:<$from>", ['250']);
        $send("RCPT TO:<$to>", ['250', '251']);
        $send('DATA', ['354']);

        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = str_replace("\n", "\r\n", preg_replace('/^\./m', '..', $body)); // dot-stuffing
        $message = "From: $from\r\nTo: $to\r\nSubject: $subject\r\n"
                 . 'Date: ' . date('r') . "\r\n"
                 . 'Message-ID: <' . bin2hex(random_bytes(8)) . "@icq1.local>\r\n"
                 . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n"
                 . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                 . $body . "\r\n.";
        $send($message, ['250']);
        $send('QUIT', ['221']);
    } finally {
        fclose($fp);
    }
}
