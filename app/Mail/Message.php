<?php

declare(strict_types=1);

namespace App\Mail;

use InvalidArgumentException;

/**
 * Email en texte brut. Ne contient JAMAIS de mot de passe ni de donnée financière (docs/04-securite.md §13, D6) :
 * seulement des liens à usage unique et des informations de compte.
 */
final class Message
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $textBody,
    ) {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Destinataire invalide.');
        }
        if (preg_match('/[\r\n]/', $subject) || mb_strlen($subject) > 150) {
            throw new InvalidArgumentException('Objet invalide.');
        }
    }
}
