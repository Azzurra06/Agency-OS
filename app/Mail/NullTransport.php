<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Transport inerte : le message est ignoré (utile pour certains tests).
 */
final class NullTransport implements Transport
{
    public function name(): string
    {
        return 'null';
    }

    public function send(Message $message, string $from): void
    {
    }
}
