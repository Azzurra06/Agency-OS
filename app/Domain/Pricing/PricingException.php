<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use DomainException;

/**
 * Calcul impossible (code stable, traduit par l'interface) : margin_plus_variable_rate_too_high, empty_scenario…
 */
final class PricingException extends DomainException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
