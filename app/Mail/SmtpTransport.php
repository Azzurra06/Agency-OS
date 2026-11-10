<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Config;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

/**
 * SMTP authentifié via PHPMailer (décision D6), TLS obligatoire et certificat vérifié.
 * Interdit en profil portfolio (refusé par Config) : prévu pour le SaaS commercial.
 */
final class SmtpTransport implements Transport
{
    public function __construct(private readonly Config $config)
    {
        if (!class_exists(PHPMailer::class)) {
            throw new RuntimeException('PHPMailer n\'est pas installé.');
        }
        if ($config->smtpHost === '' || $config->smtpUser === '') {
            throw new RuntimeException('SMTP non configuré.');
        }
    }

    public function name(): string
    {
        return 'smtp';
    }

    public function send(Message $message, string $from): void
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $this->config->smtpHost;
        $mail->Port = $this->config->smtpPort;
        $mail->SMTPAuth = true;
        $mail->Username = $this->config->smtpUser;
        $mail->Password = $this->config->smtpPassword;
        $mail->SMTPSecure = $this->config->smtpPort === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAutoTLS = true;
        $mail->Timeout = 10;
        $mail->SMTPOptions = ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]];
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->setFrom($from, 'Agency OS');
        $mail->addAddress($message->to);
        $mail->Subject = $message->subject;
        $mail->Body = $message->textBody;
        $mail->isHTML(false);
        $mail->send();
    }
}
