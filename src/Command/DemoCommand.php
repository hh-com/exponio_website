<?php

namespace App\Command;

use App\Entity\CollectionArea;
use App\Entity\Item;
use App\Entity\ItemImage;
use App\Enum\AttributeType;
use App\Enum\ImageSide;
use App\Enum\ItemStatus;
use App\Import\FolderImporter;
use App\Media\MediaStorage;
use App\Repository\CategoryRepository;
use App\Repository\CollectionAreaRepository;
use App\Repository\TopicRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Erzeugt Demo-Objekte mit gezeichneten Testbildern (nur für Entwicklung/Vorschau).
 * Mit --scan-folders werden zusätzlich Upload-Ordner wie von der App angelegt.
 */
#[AsCommand(name: 'app:demo', description: 'Erzeugt Demo-Objekte und optional Test-Upload-Ordner (Entwicklung).')]
final class DemoCommand extends Command
{
    private const PALETTE = [[214, 92, 70], [46, 104, 160], [72, 135, 94], [173, 120, 52], [126, 82, 150], [190, 70, 110], [60, 130, 140], [150, 110, 80]];
    private const TITLES = [
        ['Bedeutende Deutsche', 'Famous Germans', 'Persönlichkeiten'],
        ['Wohlfahrtsmarke Blumen', 'Charity stamp flowers', 'Pflanzen & Blumen'],
        ['Europa CEPT', 'Europa CEPT', 'Europa (CEPT)'],
        ['Olympische Sommerspiele', 'Summer Olympics', 'Olympische Spiele'],
        ['Dampflokomotiven', 'Steam locomotives', 'Eisenbahn'],
        ['Segelschiffe', 'Sailing ships', 'Schiffe & Seefahrt'],
        ['Burgen und Schlösser', 'Castles and palaces', 'Architektur & Bauwerke'],
        ['Weihnachten', 'Christmas', 'Religion & Weihnachten'],
        ['Heimische Vögel', 'Native birds', 'Tiere'],
        ['Luftpost-Jubiläum', 'Airmail anniversary', 'Luft- & Raumfahrt'],
        ['Gemälde alter Meister', 'Old master paintings', 'Kunst & Gemälde'],
        ['Alpenlandschaften', 'Alpine landscapes', 'Landschaften'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CollectionAreaRepository $areas,
        private readonly CategoryRepository $categories,
        private readonly TopicRepository $topics,
        private readonly MediaStorage $media,
        private readonly FolderImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('items', null, InputOption::VALUE_REQUIRED, 'Anzahl veröffentlichter Demo-Objekte', '36')
            ->addOption('scan-folders', null, InputOption::VALUE_REQUIRED, 'Anzahl Test-Upload-Ordner im SCAN_DIR', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $areas = $this->areas->findActive();
        if ([] === $areas) {
            $io->error('Zuerst "php bin/console app:setup" ausführen.');

            return Command::FAILURE;
        }
        mt_srand(4711);
        $categories = array_values(array_filter($this->categories->findAllOrdered(), static fn ($c): bool => null !== $c->getParent() || $c->getChildren()->isEmpty()));
        $topics = [];
        foreach ($this->topics->findAllOrdered() as $topic) {
            $topics[$topic->getName()] = $topic;
        }
        $tmp = sys_get_temp_dir().'/demo-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($tmp);

        $count = max(0, (int) $input->getOption('items'));
        for ($n = 0; $n < $count; ++$n) {
            $area = 0 === $n % 4 ? $areas[mt_rand(0, \count($areas) - 1)] : $areas[0];
            [$seriesDe, $seriesEn, $topicName] = self::TITLES[$n % \count(self::TITLES)];
            $category = $categories[mt_rand(0, \count($categories) - 1)];
            $year = mt_rand(1872, 2015);

            $item = (new Item())
                ->setArea($area)
                ->setCategory($category)
                ->setTitle(sprintf('%s %d, %s', $category->getName(), $year, $seriesDe))
                ->setDescription(sprintf('Demo-Objekt aus der Serie „%s“ (%d). Dieses Stück wurde automatisch erzeugt, um das Layout der Website zu zeigen.', $seriesDe, $year))
                ->setSeries($seriesDe)
                ->setDefects(0 === $n % 4 ? 'kurzer Zahn oben rechts' : null)
                ->setYear($year)
                ->setCatalogValue(mt_rand(20, 8000) / 100)
                ->setShowEstimate(0 === $n % 3)
                ->setFeatured(0 === $n % 5);
            $english = $item->translationFor('en');
            $english->set('title', sprintf('%s %d, %s', $category->getName('en'), $year, $seriesEn))
                ->set('description', sprintf('Demo item from the "%s" series (%d). Generated automatically to preview the website layout.', $seriesEn, $year))
                ->set('series', $seriesEn);
            $english->markManual($item->sourceHash());
            if (isset($topics[$topicName])) {
                $item->addTopic($topics[$topicName]);
            }
            foreach ($area->getAttributeDefinitions() as $definition) {
                $value = match ($definition->getType()) {
                    AttributeType::Select => $definition->getOptionValues()[mt_rand(0, max(0, \count($definition->getOptionValues()) - 1))] ?? null,
                    AttributeType::Boolean => (string) mt_rand(0, 1),
                    AttributeType::Number => (string) mt_rand(5, 40),
                    AttributeType::Text => match ($definition->getCode()) {
                        'michel' => (string) mt_rand(1, 3500),
                        'face_value', 'denomination' => mt_rand(1, 100).' Pf',
                        default => null,
                    },
                };
                $item->setAttributeValue($definition, $value);
            }

            $color = self::PALETTE[$n % \count(self::PALETTE)];
            $front = $tmp.'/front.png';
            $this->drawStamp($front, $area, $category->getName(), $year, $color, $n);
            $this->addImage($item, $front, sprintf('items/demo-%d/01', $n + 1), 0, ImageSide::Front, 'Vorderseite: '.$item->getTitle());
            if (0 === $n % 3) {
                $back = $tmp.'/back.png';
                $this->drawBack($back, $n);
                $this->addImage($item, $back, sprintf('items/demo-%d/02', $n + 1), 1, ImageSide::Back, 'Rückseite mit Gummierung');
            }
            $item->setStatus(ItemStatus::Published);
            $item->setPublishedAt(new \DateTimeImmutable(sprintf('-%d hours', $count - $n)));
            $this->em->persist($item);
            if (0 === $n % 10) {
                $this->em->flush();
            }
        }
        $this->em->flush();
        $io->success(sprintf('%d Demo-Objekte angelegt.', $count));

        $folders = max(0, (int) $input->getOption('scan-folders'));
        if ($folders > 0) {
            $dir = $this->importer->getScanDir();
            (new Filesystem())->mkdir($dir);
            for ($i = 0; $i < $folders; ++$i) {
                $name = (new \DateTimeImmutable(sprintf('-%d minutes', $folders - $i)))->format('Y-m-d_His');
                (new Filesystem())->mkdir($dir.'/'.$name);
                $this->drawStamp($dir.'/'.$name.'/01.png', $areas[0], 'Deutschland', 1950 + $i * 7, self::PALETTE[$i % 8], $i);
                $this->drawBack($dir.'/'.$name.'/02.png', $i);
                // die App lädt WebP hoch – PNG reicht für den Test
                $io->writeln('Test-Ordner: '.$dir.'/'.$name);
            }
            $io->success('Test-Upload-Ordner angelegt. Import: php bin/console app:scan');
        }
        (new Filesystem())->remove($tmp);

        return Command::SUCCESS;
    }

    private function addImage(Item $item, string $file, string $base, int $position, ImageSide $side, string $alt): void
    {
        $saved = $this->media->store($file, $base);
        $item->addImage((new ItemImage())->setPath($saved['path'])->setPosition($position)->setDimensions($saved['width'], $saved['height'])->setHash($saved['hash'])->setSide($side)->setAlt($alt));
    }

    /** @param array{0: int, 1: int, 2: int} $rgb */
    private function drawStamp(string $file, CollectionArea $area, string $country, int $year, array $rgb, int $variant): void
    {
        $landscape = 1 === $variant % 3;
        [$w, $h] = $landscape ? [900, 720] : [720, 880];
        $img = imagecreatetruecolor($w, $h);
        $paper = self::color($img, 251, 248, 240);
        $main = self::color($img, ...$rgb);
        $light = self::color($img, $rgb[0] + 70, $rgb[1] + 70, $rgb[2] + 70);
        $dark = self::color($img, (int) ($rgb[0] * .45), (int) ($rgb[1] * .45), (int) ($rgb[2] * .45));
        imagefilledrectangle($img, 0, 0, $w, $h, $paper);
        imagefilledrectangle($img, 40, 40, $w - 40, $h - 40, $main);
        imagefilledrectangle($img, 70, 150, $w - 70, $h - 170, $light);

        // einfaches Motiv: Sonne und Hügel oder Kreise
        if (0 === $variant % 2) {
            imagefilledellipse($img, (int) ($w * .68), (int) ($h * .38), 170, 170, $paper);
            imagefilledpolygon($img, [70, $h - 170, (int) ($w * .35), (int) ($h * .42), (int) ($w * .6), $h - 170], $main);
            imagefilledpolygon($img, [(int) ($w * .4), $h - 170, (int) ($w * .7), (int) ($h * .5), $w - 70, $h - 170], $dark);
        } else {
            for ($r = 320; $r > 40; $r -= 60) {
                imagefilledellipse($img, (int) ($w / 2), (int) ($h * .47), $r, $r, intdiv($r, 60) % 2 ? $main : $paper);
            }
        }

        $font = $this->font();
        $label = mb_strtoupper(mb_strlen($country) > 14 ? mb_substr($country, 0, 13).'.' : $country);
        $value = (5 + $variant * 5 % 95).' Pf';
        if (null !== $font) {
            imagettftext($img, 38, 0, 70, 115, $paper, $font, $label);
            imagettftext($img, 64, 0, 70, $h - 75, $paper, $font, $value);
            imagettftext($img, 30, 0, $w - 210, $h - 85, $paper, $font, (string) $year);
        } else {
            imagestring($img, 5, 70, 90, $label, $paper);
            imagestring($img, 5, 70, $h - 100, $value.'  '.$year, $paper);
        }
        imagepng($img, $file);
    }

    private function drawBack(string $file, int $variant): void
    {
        $img = imagecreatetruecolor(720, 880);
        imagefilledrectangle($img, 0, 0, 720, 880, self::color($img, 246, 240, 222));
        $gum = self::color($img, 236, 226, 196);
        for ($i = 0; $i < 900; ++$i) {
            imagesetpixel($img, mt_rand(0, 719), mt_rand(0, 879), $gum);
        }
        if (0 === $variant % 2) {
            // Falzrest
            imagefilledrectangle($img, 260, 120, 460, 230, self::color($img, 255, 255, 250));
        }
        imagepng($img, $file);
    }

    private static function color(\GdImage $image, int $red, int $green, int $blue): int
    {
        $color = imagecolorallocate($image, max(0, min(255, $red)), max(0, min(255, $green)), max(0, min(255, $blue)));
        if (false === $color) {
            throw new \RuntimeException('Farbe konnte nicht angelegt werden.');
        }

        return $color;
    }

    private function font(): ?string
    {
        foreach (['/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf', '/usr/share/fonts/truetype/liberation/LiberationSerif-Bold.ttf', '/usr/share/fonts/dejavu/DejaVuSerif-Bold.ttf'] as $font) {
            if (is_file($font) && \function_exists('imagettftext')) {
                return $font;
            }
        }

        return null;
    }
}
