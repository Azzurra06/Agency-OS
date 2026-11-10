<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Clock;
use RuntimeException;

/**
 * Transport de test (profil portfolio, développement) : AUCUN email n'est envoyé.
 * Les messages sont écrits dans storage/mail-capture (droits 600, purgés après 7 jours).
 */
final class CaptureTransport implements Transport
{
    public function __construct(private readonly string $directory, private readonly Clock $clock = new Clock())
    {
    }

    public function name(): string
    {
        return 'capture';
    }

    public function send(Message $message, string $from): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Répertoire de capture des emails indisponible.');
        }
        $file = sprintf('%s/%s-%s.json', $this->directory, $this->clock->nowUtc()->format('Ymd\THis'), bin2hex(random_bytes(4)));
        $payload = json_encode([
            'from' => $from,
            'to' => $message->to,
            'subject' => $message->subject,
            'body' => $message->textBody,
            'captured_at' => $this->clock->nowUtc()->format(DATE_ATOM),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($file, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Capture de l\'email impossible.');
        }
        chmod($file, 0600);
    }

    /** @return list<string> fichiers capturés, du plus ancien au plus récent */
    public function captured(): array
    {
        $files = glob($this->directory . '/*.json') ?: [];
        sort($files);

        return $files;
    }

    public function purgeOlderThanDays(int $days = 7): int
    {
        $limit = $this->clock->nowUtc()->getTimestamp() - $days * 86400;
        $count = 0;
        foreach ($this->captured() as $file) {
            if ((int) filemtime($file) < $limit && unlink($file)) {
                $count++;
            }
        }

        return $count;
    }
}
