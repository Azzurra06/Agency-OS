<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * Journal technique (fichiers JSON par jour, hors du répertoire public).
 *
 * Règles (docs/04-securite.md §7.2) :
 *  - seules les clés de contexte de la liste blanche sont conservées ;
 *  - tout champ évoquant un secret ou un montant est masqué ;
 *  - les nombres présents dans les messages d'exception sont masqués ;
 *  - les corps de requête ne sont jamais journalisés.
 */
class Logger
{
    private const ALLOWED_KEYS = [
        'event', 'error_ref', 'exception', 'file', 'line', 'route', 'method', 'status',
        'user_public_id', 'org_public_id', 'request_id', 'job', 'run_key', 'items_ok', 'items_failed',
        'duration_ms', 'reason', 'transport', 'migration', 'count',
    ];

    private const SENSITIVE_PATTERN = '/pass|pwd|secret|token|cookie|session|key|auth|csrf|amount|montant|price|prix|revenue|cost|salary|salaire|charge|mrr|arr|email/i';

    public function __construct(private readonly string $directory, private readonly Clock $clock = new Clock())
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    public function exception(Throwable $e, string $errorRef): void
    {
        $this->write('error', self::scrub($e->getMessage()), [
            'error_ref' => $errorRef,
            'exception' => $e::class,
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
        ]);
    }

    /**
     * Masque les nombres (montants potentiels) et les adresses email d'un texte libre.
     */
    public static function scrub(string $text): string
    {
        $text = preg_replace('/[^\s@]+@[^\s@]+\.[^\s@]+/', '[email]', $text) ?? '';
        $text = preg_replace('/\d[\d\s.,]*\d|\d/', '[n]', $text) ?? '';

        return mb_substr($text, 0, 500);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function sanitize(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            if (!in_array($key, self::ALLOWED_KEYS, true)) {
                continue;
            }
            if ($key !== 'error_ref' && $key !== 'run_key' && preg_match(self::SENSITIVE_PATTERN, $key)) {
                $clean[$key] = '[redacted]';
                continue;
            }
            $clean[$key] = is_scalar($value) || $value === null ? $value : '[non-scalar]';
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function write(string $level, string $message, array $context): void
    {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0700, true);
        }
        $now = $this->clock->nowUtc();
        $line = json_encode([
            'ts' => $now->format('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'message' => self::scrub($message),
            'context' => self::sanitize($context),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $file = $this->directory . '/app-' . $now->format('Y-m-d') . '.log';
        $fresh = !is_file($file);
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
        if ($fresh) {
            @chmod($file, 0600);
        }
    }
}
