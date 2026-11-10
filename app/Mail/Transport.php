<?php

declare(strict_types=1);

namespace App\Mail;

interface Transport
{
    public function name(): string;

    public function send(Message $message, string $from): void;
}
