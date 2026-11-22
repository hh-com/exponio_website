<?php

namespace App\Command;

use App\Repository\LanguageRepository;
use App\Translation\TranslationRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Für den Cronjob: übersetzt fehlende und veraltete Texte in alle freigeschalteten Sprachen.
 *
 *   * /10 * * * * cd /var/www/website && php bin/console app:translate --quiet
 */
#[AsCommand(name: 'app:translate', description: 'Übersetzt Oberfläche und Inhalte per KI in alle freigeschalteten Sprachen.')]
final class TranslateCommand extends Command
{
    public function __construct(
        private readonly LanguageRepository $languages,
        private readonly TranslationRunner $runner,
        private readonly LockFactory $locks,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('language', null, InputOption::VALUE_REQUIRED, 'Nur diese Sprache (z. B. fr)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Höchstens so viele KI-Anfragen je Sprache und Lauf', '20');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $lock = $this->locks->createLock('app-translate', 3600);
        if (!$lock->acquire()) {
            $io->note('Eine andere Übersetzung läuft bereits.');

            return Command::SUCCESS;
        }

        try {
            $only = $input->getOption('language');
            $targets = $this->languages->findTranslationTargets();
            if (\is_string($only)) {
                $targets = array_values(array_filter($targets, static fn ($l): bool => $l->getCode() === $only));
                if ([] === $targets) {
                    $io->error(sprintf('Die Sprache "%s" ist nicht freigeschaltet.', $only));

                    return Command::FAILURE;
                }
            }
            if ([] === $targets) {
                $io->note('Keine Sprache außer Deutsch freigeschaltet (Backend → Sprachen).');

                return Command::SUCCESS;
            }

            $limit = max(1, (int) $input->getOption('limit'));
            $failed = false;
            foreach ($targets as $language) {
                try {
                    $stats = $this->runner->run($language, $limit);
                    $io->writeln(sprintf('<info>%s</info> (%s): %s', $language->getNameDe(), $language->getCode(), $stats->summary()));
                } catch (\Throwable $e) {
                    $failed = true;
                    $io->writeln(sprintf('<error>%s</error>: %s', $language->getCode(), $e->getMessage()));
                }
            }

            return $failed ? Command::FAILURE : Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
