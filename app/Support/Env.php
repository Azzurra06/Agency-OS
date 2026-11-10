<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Lecture du fichier config/.env (format KEY=VALUE).
 * Les variables d'environnement réelles du processus ont priorité sur le fichier.
 * Aucune valeur n'est exportée dans $_ENV ou getenv() : la configuration reste en mémoire.
 */
final class Env
{
    /** @var array<string, string> */
    private array $values = [];

    /**
     * @param array<string, string> $values
     */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function load(string $path): self
    {
        $values = [];
        if (is_file($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                throw new RuntimeException('Fichier de configuration illisible.');
            }
            foreach ($lines as $number => $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (!preg_match('/^([A-Z][A-Z0-9_]*)\s*=\s*(.*)$/', $line, $match)) {
                    throw new RuntimeException(sprintf('Ligne %d invalide dans le fichier de configuration.', $number + 1));
                }
                $values[$match[1]] = self::unquote($match[2]);
            }
        }
        foreach ($values as $key => $_) {
            $real = getenv($key);
            if (is_string($real) && $real !== '') {
                $values[$key] = $real;
            }
        }

        return new self($values);
    }

    /**
     * @param array<string, string> $values
     */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->values[$key] ?? $default;
    }

    public function require(string $key): string
    {
        $value = $this->values[$key] ?? null;
        if ($value === null || $value === '') {
            throw new RuntimeException(sprintf('Variable de configuration manquante : %s', $key));
        }

        return $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->values[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $key, int $default): int
    {
        $value = $this->values[$key] ?? null;

        return ($value !== null && preg_match('/^-?\d+$/', $value)) ? (int) $value : $default;
    }

    private static function unquote(string $value): string
    {
        $value = trim($value);
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            return substr($value, 1, -1);
        }
        $hash = strpos($value, ' #');

        return $hash === false ? $value : rtrim(substr($value, 0, $hash));
    }
}
