<?php

namespace App\Command;

use App\Entity\ItemImage;
use App\Media\MediaStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Erzeugt die Webbilder (WebP 2000/800/400 px) neu aus den Originalen im Upload-Ordner der App.
 * Nützlich nach einem Update, Umzug oder einer neuen Bildqualität. Da der Dateiname vom Inhalt
 * des Originals abhängt, bleiben alle Adressen gleich. Im Backend gesetzte Drehungen bleiben erhalten.
 */
#[AsCommand(name: 'app:media:rebuild', description: 'Erzeugt fehlende (oder mit --all alle) Webbilder neu aus den App-Uploads.')]
final class MediaRebuildCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaStorage $media,
        private readonly string $scanDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Auch vorhandene Bilder neu erzeugen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $all = (bool) $input->getOption('all');
        $done = $skipped = $missing = 0;

        /** @var list<ItemImage> $images */
        $images = $this->em->getRepository(ItemImage::class)->findBy([], ['id' => 'ASC']);
        foreach ($images as $image) {
            $path = (string) $image->getPath();
            if (!$all && is_file($this->media->absolutePath($path, 400))) {
                ++$skipped;
                continue;
            }
            $original = null === $image->getSourceFile() ? null : $this->scanDir.'/'.$image->getSourceFile();
            if (null === $original || !is_file($original)) {
                ++$missing;
                $io->writeln(sprintf('<comment>kein Original</comment> Bild #%d (%s)', $image->getId(), $path));
                continue;
            }
            // Pfad ohne Hash: der Hash hängt vom Original (und der Drehung) ab und ergibt wieder denselben Namen
            $saved = $this->media->store($original, MediaStorage::stripContentHash($path), $image->getRotation());
            $image->setPath($saved['path'])->setDimensions($saved['width'], $saved['height'])->setHash($saved['hash']);
            ++$done;
        }
        $this->em->flush();

        $io->success(sprintf('%d Bilder erzeugt, %d vorhanden, %d ohne Original (z. B. im Backend hochgeladen).', $done, $skipped, $missing));

        return Command::SUCCESS;
    }
}
