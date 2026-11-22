<?php

namespace App\Command;

use App\Duplicate\Candidate;
use App\Duplicate\DuplicateDetector;
use App\Entity\ItemImage;
use App\Enum\DuplicateStatus;
use App\Media\MediaStorage;
use App\Repository\ItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Prüft vorhandene Objekte nachträglich auf Duplikate:
 *
 * 1. berechnet fehlende Wahrnehmungs-Hashes der Fotos (Bestand vor der Duplikat-Erkennung),
 * 2. vergleicht jedes noch nicht geprüfte Objekt mit älteren Objekten (Vorauswahl, dann ein KI-Aufruf).
 *
 * Bestandsobjekte werden nie automatisch zusammengeführt: Ein Treffer erscheint als „mögliches Duplikat“
 * unter „Prüfen & freigeben“. Mit --dry-run wird nur die Vorauswahl gezeigt (nichts gespeichert, keine KI-Kosten).
 */
#[AsCommand(name: 'app:duplicates:scan', description: 'Prüft vorhandene Objekte nachträglich auf Duplikate (setzt nur Hinweise).')]
final class DuplicatesScanCommand extends Command
{
    public function __construct(
        private readonly ItemRepository $items,
        private readonly DuplicateDetector $duplicates,
        private readonly MediaStorage $media,
        private readonly EntityManagerInterface $em,
        private readonly LockFactory $locks,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur die Vorauswahl zeigen: nichts speichern, keine KI-Aufrufe')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Höchstens so viele KI-Vergleiche pro Lauf', '20')
            ->addOption('item', null, InputOption::VALUE_REQUIRED, 'Nur dieses Objekt prüfen (Nummer)')
            ->addOption('recheck', null, InputOption::VALUE_NONE, 'Auch Objekte prüfen, die schon als „kein Duplikat“ geprüft wurden');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        // dieselbe Sperre wie app:scan: nicht gleichzeitig mit einem Import zusammenführen
        $lock = $this->locks->createLock('app-scan', 1800);
        if (!$dryRun && !$lock->acquire()) {
            $io->note('app:scan läuft gerade – bitte später erneut starten.');

            return Command::SUCCESS;
        }

        $rows = [];
        $compared = 0;
        try {
            $this->fillHashes($io, $dryRun);

            $limit = max(1, (int) $input->getOption('limit'));
            $id = $input->getOption('item');
            $items = $this->items->findForDuplicateScan((bool) $input->getOption('recheck'), is_numeric($id) ? (int) $id : null);
            foreach ($items as $item) {
                if (DuplicateStatus::Pending === $item->getDuplicateStatus()) {
                    continue; // frische Importe prüft app:scan
                }
                if ($dryRun) {
                    $candidates = $this->duplicates->candidates($item, true);
                    if ([] !== $candidates) {
                        $rows[] = [sprintf('#%d %s', $item->getId(), $item->getTitle()), self::describeCandidates($candidates), 'Vorauswahl (keine KI)'];
                    }
                    continue;
                }
                if ($compared >= $limit) {
                    $io->note(sprintf('Limit von %d KI-Vergleichen erreicht – den Rest prüft der nächste Lauf.', $limit));
                    break;
                }
                $outcome = $this->duplicates->checkExisting($item);
                if ([] !== $outcome->candidates) {
                    ++$compared;
                    $rows[] = [sprintf('#%d %s', $item->getId(), $item->getTitle()), self::describeCandidates($outcome->candidates), $outcome->describe()];
                }
            }
        } finally {
            if (!$dryRun) {
                $lock->release();
            }
        }

        if ([] === $rows) {
            $io->success($dryRun ? 'Keine Kandidaten gefunden.' : 'Keine Duplikate gefunden.');

            return Command::SUCCESS;
        }
        $io->table(['Objekt', 'Kandidaten (Punkte)', 'Ergebnis'], $rows);
        $io->success($dryRun
            ? sprintf('%d Objekte mit Kandidaten. Ohne --dry-run vergleicht die KI sie und markiert Treffer als „mögliches Duplikat“.', \count($rows))
            : sprintf('%d Objekte verglichen. Treffer stehen unter „Prüfen & freigeben“.', $compared));

        return Command::SUCCESS;
    }

    private function fillHashes(SymfonyStyle $io, bool $dryRun): void
    {
        $images = $this->em->getRepository(ItemImage::class)->findBy(['hash' => null]);
        if ([] === $images) {
            return;
        }
        if ($dryRun) {
            $io->writeln(sprintf('%d Fotos ohne Wahrnehmungs-Hash (werden ohne --dry-run berechnet).', \count($images)));

            return;
        }
        $done = 0;
        foreach ($images as $image) {
            $hash = null === $image->getPath() ? null : $this->media->hash($image->getPath());
            if (null !== $hash) {
                $image->setHash($hash);
                ++$done;
            }
        }
        $this->em->flush();
        $io->writeln(sprintf('Wahrnehmungs-Hashes: %d von %d Fotos berechnet (einfarbige oder fehlende Bilder bekommen keinen).', $done, \count($images)));
    }

    /** @param list<Candidate> $candidates */
    private static function describeCandidates(array $candidates): string
    {
        return implode("\n", array_map(
            static fn (Candidate $c): string => sprintf('#%d (%d): %s', $c->item->getId(), $c->score, implode(', ', $c->reasons)),
            $candidates,
        ));
    }
}
