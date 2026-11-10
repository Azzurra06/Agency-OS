<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Config;
use App\Support\Logger;

/**
 * Façade d'envoi d'emails.
 *
 * - profil portfolio : transport « capture » imposé (aucun envoi réel, vérifié par Config) ;
 * - liste blanche facultative (MAIL_ALLOWLIST, ex. « @azzurra-pro.com ») : toute autre adresse est bloquée ;
 * - le journal ne contient ni destinataire ni contenu.
 */
final class Mailer
{
    public function __construct(
        private readonly Transport $transport,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    public function transportName(): string
    {
        return $this->transport->name();
    }

    /** @return bool true si le message a été remis au transport */
    public function send(Message $message): bool
    {
        if (!$this->isAllowed($message->to)) {
            $this->logger->warning('Email bloqué par la liste blanche', ['transport' => $this->transport->name(), 'reason' => 'allowlist']);

            return false;
        }
        $this->transport->send($message, $this->config->mailFrom);
        $this->logger->info('Email remis au transport', ['transport' => $this->transport->name()]);

        return true;
    }

    private function isAllowed(string $address): bool
    {
        $allowlist = array_filter(array_map('trim', explode(',', $this->config->mailAllowlist)));
        if ($allowlist === []) {
            return true;
        }
        $address = mb_strtolower($address);
        foreach ($allowlist as $entry) {
            $entry = mb_strtolower($entry);
            if ($address === $entry || (str_starts_with($entry, '@') && str_ends_with($address, $entry))) {
                return true;
            }
        }

        return false;
    }
}
