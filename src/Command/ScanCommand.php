<?php

namespace App\Command;

use App\Duplicate\DuplicateDetector;
use App\Duplicate\DuplicateOutcome;
use App\Entity\Item;
use App\Import\FolderImporter;
use App\Recognition\RecognitionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Für den Cronjob: neue Upload-Ordner importieren, erkennen lassen und auf Duplikate prüfen.
 * Ein sicheres Duplikat wird dem vorhandenen Objekt zugeordnet (kein neues Objekt), ein unsicheres
 * erscheint mit Hinweis unter „Prüfen & freigeben“.
 *
 *   * /5 * * * * cd /var/www/website && php bin/console app:scan --quiet
 */
#[AsCommand(name: 'app:scan', description: 'Importiert neue Upload-Ordner, lässt sie per KI erkennen und prüft auf Duplikate.')]
final class ScanCommand extends Command
{
    public function __construct(
        private readonly FolderImporter $importer,
        private readonly RecognitionService $recognition,
        private readonly DuplicateDetector $duplicates,
        private readonly LockFactory $locks,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximal so viele Ordner bzw. Erkennungen pro Lauf', '20')
            ->addOption('no-recognize', null, InputOption::VALUE_NONE, 'Nur importieren, nicht erkennen')
            ->addOption('retry-failed', null, InputOption::VALUE_NONE, 'Fehlgeschlagene Erkennungen erneut versuchen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $lock = $this->locks->createLock('app-scan', 1800);
        if (!$lock->acquire()) {
            $io->note('Ein anderer Scan läuft bereits.');

            return Command::SUCCESS;
        }

        try {
            $limit = max(1, (int) $input->getOption('limit'));
            $result = $this->importer->importNew($limit);
            foreach ($result['imported'] as $item) {
                $io->writeln(sprintf('<info>importiert</info> %s → #%d (%d Bilder)', $item->getSourceFolder(), $item->getId(), $item->getImages()->count()));
            }
            foreach ($result['failed'] as $folder => $message) {
                $io->writeln(sprintf('<error>fehlgeschlagen</error> %s: %s', $folder, $message));
            }

            if (!$input->getOption('no-recognize')) {
                // zuerst Duplikatprüfungen nachholen, die bei einem früheren Lauf fehlgeschlagen sind (höchstens ein Versuch je Lauf)
                $this->duplicates->checkPending($limit, static function (Item $item, DuplicateOutcome $outcome) use ($io): void {
                    $io->writeln(sprintf('<info>Duplikatprüfung</info> %s: %s', self::label($item), $outcome->describe()));
                });

                $stats = $this->recognition->recognizePending($limit, (bool) $input->getOption('retry-failed'), function (Item $item, bool $ok) use ($io): void {
                    $io->writeln($ok
                        ? sprintf('<info>erkannt</info> %s: %s (%.0f %%)', self::label($item), $item->getTitle(), 100 * (float) $item->getAiConfidence())
                        : sprintf('<error>Erkennung fehlgeschlagen</error> %s: %s', self::label($item), $item->getAiError()));
                    $duplicate = $this->recognition->lastDuplicate();
                    if ($ok && DuplicateOutcome::SKIPPED !== $duplicate->result) {
                        $io->writeln('  → '.$duplicate->describe());
                    }
                });
                $io->writeln(sprintf('Erkennung: %d erfolgreich, %d fehlgeschlagen.', $stats['ok'], $stats['failed']));
            }
        } finally {
            $lock->release();
        }

        return [] === $result['failed'] ? Command::SUCCESS : Command::FAILURE;
    }

    /** Objektnummer, bei einem zugeordneten Duplikat (das Objekt gibt es nicht mehr) der Upload-Ordner */
    private static function label(Item $item): string
    {
        return null !== $item->getId() ? '#'.$item->getId() : (string) $item->getSourceFolder();
    }
}
