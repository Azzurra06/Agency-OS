<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Validation déclarative des entrées (docs/04-securite.md §5, « Validation » et « Mass assignment »).
 *
 * - Liste blanche : tout champ non déclaré est refusé (unknown_field).
 * - Décimaux : chaînes uniquement (decimal_must_be_string), jamais de nombres JSON.
 * - Taux : saisis en pourcentage (chaîne), convertis en fraction par le service métier.
 *
 * Règles disponibles : required, nullable, string, email, int, bool, decimal, percent,
 * enum:a,b,c, min:n, max:n (longueur pour les chaînes, valeur pour int/decimal/percent),
 * date (AAAA-MM-JJ), public_id.
 */
final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $valid = [];

    /**
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $rules
     */
    private function __construct(private readonly array $input, private readonly array $rules)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $rules
     */
    public static function make(array $input, array $rules): self
    {
        $validator = new self($input, $rules);
        $validator->run();

        return $validator;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string, mixed> */
    public function validated(): array
    {
        return $this->valid;
    }

    private function run(): void
    {
        foreach (array_keys($this->input) as $field) {
            if (!array_key_exists((string) $field, $this->rules)) {
                $this->errors[(string) $field] = 'unknown_field';
            }
        }

        foreach ($this->rules as $field => $rules) {
            $present = array_key_exists($field, $this->input);
            $value = $present ? $this->input[$field] : null;

            if (!$present || $value === null || $value === '') {
                if (in_array('required', $rules, true)) {
                    $this->errors[$field] = 'required';
                } elseif ($present && in_array('nullable', $rules, true)) {
                    $this->valid[$field] = null;
                }
                continue;
            }

            $error = $this->check($value, $rules);
            if ($error !== null) {
                $this->errors[$field] = $error;
                continue;
            }
            $this->valid[$field] = $this->cast($value, $rules);
        }
    }

    /**
     * @param list<string> $rules
     */
    private function check(mixed $value, array $rules): ?string
    {
        $type = $this->typeOf($rules);

        switch ($type) {
            case 'decimal':
            case 'percent':
                if (!is_string($value)) {
                    return 'decimal_must_be_string';
                }
                if (!preg_match('/^-?\d{1,10}(\.\d{1,2})?$/', $value)) {
                    return 'invalid_decimal';
                }
                break;
            case 'int':
                if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d{1,10}$/', $value))) {
                    return 'invalid_integer';
                }
                break;
            case 'bool':
                if (!is_bool($value)) {
                    return 'invalid_boolean';
                }
                break;
            case 'email':
                if (!is_string($value) || mb_strlen(trim($value)) > 190 || filter_var(trim($value), FILTER_VALIDATE_EMAIL) === false) {
                    return 'invalid_email';
                }
                break;
            case 'date':
                if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                    return 'invalid_date';
                }
                [$y, $m, $d] = array_map('intval', explode('-', $value));
                if (!checkdate($m, $d, $y)) {
                    return 'invalid_date';
                }
                break;
            case 'public_id':
                if (!is_string($value) || !PublicId::isValid($value)) {
                    return 'invalid_identifier';
                }
                break;
            default:
                if (!is_string($value)) {
                    return 'invalid_string';
                }
                if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
                    return 'invalid_characters';
                }
        }

        foreach ($rules as $rule) {
            if (str_starts_with($rule, 'enum:')) {
                if (!in_array((string) $value, explode(',', substr($rule, 5)), true)) {
                    return 'invalid_choice';
                }
            }
            if (str_starts_with($rule, 'min:') || str_starts_with($rule, 'max:')) {
                $bound = substr($rule, 4);
                $isMin = str_starts_with($rule, 'min:');
                if (in_array($type, ['decimal', 'percent', 'int'], true)) {
                    $cmp = Decimal::of((string) $value)->compare(Decimal::of($bound));
                    if (($isMin && $cmp < 0) || (!$isMin && $cmp > 0)) {
                        return $isMin ? 'too_small' : 'too_large';
                    }
                } else {
                    $length = mb_strlen((string) $value);
                    if (($isMin && $length < (int) $bound) || (!$isMin && $length > (int) $bound)) {
                        return $isMin ? 'too_short' : 'too_long';
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $rules
     */
    private function cast(mixed $value, array $rules): mixed
    {
        $text = is_scalar($value) ? (string) $value : '';

        return match ($this->typeOf($rules)) {
            'decimal' => Decimal::of($text),
            'percent' => Decimal::of($text)->div(Decimal::of(100)),
            'int' => (int) $text,
            'email' => mb_strtolower(trim($text)),
            'string' => trim($text),
            default => $value,
        };
    }

    /**
     * @param list<string> $rules
     */
    private function typeOf(array $rules): string
    {
        foreach (['decimal', 'percent', 'int', 'bool', 'email', 'date', 'public_id', 'string'] as $type) {
            if (in_array($type, $rules, true)) {
                return $type;
            }
        }

        return 'string';
    }
}
