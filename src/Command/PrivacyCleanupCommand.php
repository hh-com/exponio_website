<?php

namespace App\Command;

use App\Repository\InquiryRepository;
use App\Service\SettingsProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Datensparsamkeit (Art. 5 DSGVO): löscht Kontaktnachrichten nach der eingestellten Frist.
 *
 *   15 3 * * * cd /var/www/website && php bin/console app:privacy:cleanup --quiet
 */
#[AsCommand(name: 'app:privacy:cleanup', description: 'Löscht Kontaktnachrichten, die älter als die eingestellte Aufbewahrungsfrist sind.')]
final class PrivacyCleanupCommand extends Command
{
    public function __construct(
        private readonly InquiryRepository $inquiries,
        private readonly SettingsProvider $settings,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $months = $this->settings->get()->getMessageRetentionMonths();
        $deleted = $this->inquiries->deleteOlderThan(new \DateTimeImmutable(sprintf('-%d months', $months)));
        (new SymfonyStyle($input, $output))->success(sprintf('%d Nachrichten älter als %d Monate gelöscht.', $deleted, $months));

        return Command::SUCCESS;
    }
}
