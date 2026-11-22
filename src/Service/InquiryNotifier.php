<?php

namespace App\Service;

use App\Entity\Inquiry;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Schickt neue Kontaktnachrichten an die Kontakt-Adresse aus den Einstellungen.
 * Fehler beim Versand werden protokolliert; die Nachricht bleibt im Backend gespeichert.
 */
final class InquiryNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly SettingsProvider $settings,
        private readonly LoggerInterface $logger,
        private readonly string $mailerFrom,
    ) {
    }

    public function notify(Inquiry $inquiry): bool
    {
        $settings = $this->settings->get();
        $to = $settings->getContactEmail();
        if (null === $to || '' === $to) {
            return false;
        }

        $subject = 'Neue Nachricht über die Website';

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, $settings->getSiteName()))
            ->to($to)
            ->replyTo(new Address($inquiry->getEmail(), $inquiry->getName()))
            ->subject($subject)
            ->textTemplate('emails/inquiry.txt.twig')
            ->context([
                'inquiry' => $inquiry,
            ]);

        try {
            $this->mailer->send($email);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Benachrichtigung konnte nicht gesendet werden: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return false;
        }
    }
}
