<?php
/**
 * Mailer — currently a thin stub around PHP's built-in mail().
 *
 * KNOWN LIMITATION (explicit user decision): mail() depends on a configured
 * local MTA/sendmail and has no delivery guarantees, auth, or TLS. This is
 * intentionally simple for now. To swap in real SMTP later, replace only the
 * internals of send() below (e.g. open a socket / use a future SMTP client) —
 * every caller in the app uses this same Mailer::send() signature, so no
 * other file needs to change.
 */

declare(strict_types=1);

final class Mailer
{
    private string $fromAddress;
    private string $fromName;

    public function __construct(?string $fromAddress = null, ?string $fromName = null)
    {
        $this->fromAddress = $fromAddress ?? (env('MAIL_FROM_ADDRESS', 'no-reply@example.com') ?? 'no-reply@example.com');
        $this->fromName = $fromName ?? (env('MAIL_FROM_NAME', 'Jadwal Interchange') ?? 'Jadwal Interchange');
    }

    /**
     * Send a plain-text email. Returns true on success, false on failure.
     * Never throws — callers decide how to surface a failure to the user.
     */
    public function send(string $to, string $subject, string $body): bool
    {
        if ($to === '' || $subject === '') {
            return false;
        }

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $headers = [];
        $headers[] = 'From: ' . $this->encodeFromHeader();
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';

        return @mail($to, $encodedSubject, $body, implode("\r\n", $headers));
    }

    private function encodeFromHeader(): string
    {
        $name = '=?UTF-8?B?' . base64_encode($this->fromName) . '?=';
        return $name . ' <' . $this->fromAddress . '>';
    }
}
