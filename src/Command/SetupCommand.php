<?php

namespace App\Command;

use App\Entity\AttributeDefinition;
use App\Entity\Category;
use App\Entity\CollectionArea;
use App\Entity\Language;
use App\Entity\Page;
use App\Entity\Topic;
use App\Repository\CategoryRepository;
use App\Repository\CollectionAreaRepository;
use App\Repository\LanguageRepository;
use App\Repository\PageRepository;
use App\Repository\TopicRepository;
use App\Service\SettingsProvider;
use App\Translation\TranslatableInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Intl\Languages;

/**
 * Legt Startdaten an. Bei einer bestehenden Installation ergänzt der Befehl nur Fehlendes
 * (neue Sprachen, neue Merkmale vorhandener Objektarten) und überschreibt nichts.
 */
#[AsCommand(name: 'app:setup', description: 'Legt Sprachen, Einstellungen, Objektarten mit Merkmalen, Länder, Themen und Seiten an bzw. ergänzt Fehlendes.')]
final class SetupCommand extends Command
{
    private const ENGLISH = 'en';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SettingsProvider $settings,
        private readonly LanguageRepository $languages,
        private readonly CollectionAreaRepository $areas,
        private readonly CategoryRepository $categories,
        private readonly TopicRepository $topics,
        private readonly PageRepository $pages,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->writeln(sprintf('Sprachen: %d ergänzt.', $this->setupLanguages()));
        $this->setupAreas($io);
        $this->setupRegions($io);
        $this->setupTopics($io);
        $this->setupPages($io);
        $this->setupSettings();

        $io->success('Fertig. Admin anlegen: php bin/console app:user:create deine@adresse.de');

        return Command::SUCCESS;
    }

    /** Alle Sprachen mit zweibuchstabigem ISO-639-1-Code; Deutsch und Englisch sind aktiv. */
    private function setupLanguages(): int
    {
        $existing = [];
        foreach ($this->languages->findAll() as $language) {
            $existing[$language->getCode()] = true;
        }
        $added = 0;
        foreach (Languages::getNames('de') as $code => $nameDe) {
            if (2 !== \strlen($code) || isset($existing[$code])) {
                continue;
            }
            try {
                $native = Languages::getName($code, $code);
            } catch (\Throwable) {
                $native = $nameDe;
            }
            $language = new Language($code, mb_substr(mb_strtoupper(mb_substr($native, 0, 1)).mb_substr($native, 1), 0, 80), mb_substr($nameDe, 0, 80));
            if (\in_array($code, Language::FILE_LOCALES, true)) {
                $language->setActive(true)->setPosition(Language::SOURCE === $code ? 0 : 10);
            }
            $this->em->persist($language);
            ++$added;
        }
        $this->em->flush();

        return $added;
    }

    private function setupAreas(SymfonyStyle $io): void
    {
        $existing = [];
        foreach ($this->areas->findAllWithDefinitions() as $area) {
            $existing[$area->getName()] = $area;
        }
        $addedAreas = $addedAttributes = 0;
        foreach (DefaultData::areas() as $position => $data) {
            $area = $existing[$data['de']] ?? null;
            if (null === $area) {
                $area = (new CollectionArea($data['de'], $data['icon']))->setDescription($data['descDe'])->setPosition($position * 10);
                $this->english($area, ['name' => $data['en'], 'description' => $data['descEn']]);
                $this->em->persist($area);
                ++$addedAreas;
            }
            foreach ($data['attributes'] as $index => $a) {
                if (null !== $area->getDefinition($a[0])) {
                    continue;
                }
                $optionsDe = $optionsEn = [];
                foreach ($a[4] ?? [] as [$value, $de, $en]) {
                    $optionsDe[$value] = $de;
                    $optionsEn[$value] = '' !== $en ? $en : $de;
                }
                $definition = new AttributeDefinition($a[0], $a[1], $a[3], $optionsDe, $a[5] ?? false, $a[6] ?? false, $a[7] ?? null, $a[8] ?? null);
                $definition->setPosition($index * 10);
                $definition->translationFor(self::ENGLISH)->setOptionLabels($optionsEn);
                $this->english($definition, ['label' => $a[2]]);
                $area->addAttributeDefinition($definition);
                ++$addedAttributes;
            }
        }
        $this->em->flush();
        $io->writeln(sprintf('Objektarten: %d angelegt, %d Merkmale ergänzt.', $addedAreas, $addedAttributes));
    }

    private function setupRegions(SymfonyStyle $io): void
    {
        if (0 !== $this->categories->count([])) {
            $io->writeln('Länder vorhanden – übersprungen.');

            return;
        }
        $position = 0;
        foreach (DefaultData::regions() as $nameDe => [$nameEn, $children]) {
            $parent = (new Category($nameDe))->setPosition($position += 10);
            $this->english($parent, ['name' => $nameEn]);
            $this->em->persist($parent);
            $childPosition = 0;
            foreach ($children as $childDe => $childEn) {
                $child = (new Category($childDe, $parent))->setPosition($childPosition += 10);
                $this->english($child, ['name' => $childEn]);
                $this->em->persist($child);
            }
        }
        $this->em->flush();
        $io->writeln('Länder angelegt.');
    }

    private function setupTopics(SymfonyStyle $io): void
    {
        if (0 !== $this->topics->count([])) {
            $io->writeln('Themen vorhanden – übersprungen.');

            return;
        }
        $position = 0;
        foreach (DefaultData::topics() as $de => $en) {
            $topic = (new Topic($de))->setPosition($position += 10);
            $this->english($topic, ['name' => $en]);
            $this->em->persist($topic);
        }
        $this->em->flush();
        $io->writeln('Themen angelegt.');
    }

    private function setupPages(SymfonyStyle $io): void
    {
        if (0 !== $this->pages->count([])) {
            $io->writeln('Seiten vorhanden – übersprungen.');

            return;
        }
        foreach (DefaultData::pages() as $index => $data) {
            $page = (new Page($data['de'], $data['contentDe']))->setPosition($index * 10);
            $this->english($page, ['title' => $data['en'], 'content' => $data['contentEn']]);
            $this->em->persist($page);
            if ('privacy' === $data['key']) {
                $this->settings->get()->setPrivacyPage($page);
            }
        }
        $this->em->flush();
        $io->writeln('Seiten angelegt – Impressum und Datenschutz bitte vervollständigen.');
    }

    private function setupSettings(): void
    {
        $settings = $this->settings->get();
        if (null === $settings->translation(self::ENGLISH)) {
            $this->english($settings, ['tagline' => 'Stamps, covers and more – carefully catalogued']);
        }
        if (null === $settings->getDefaultScanArea()) {
            $settings->setDefaultScanArea($this->areas->findActive()[0] ?? null);
        }
        $this->em->flush();
    }

    /**
     * Englische Texte der Startdaten (gelten als von Hand übersetzt und aktuell).
     *
     * @param TranslatableInterface<\App\Entity\AbstractTranslation> $entity
     * @param array<string, string>                                  $values
     */
    private function english(TranslatableInterface $entity, array $values): void
    {
        $translation = $entity->translationFor(self::ENGLISH);
        foreach ($values as $field => $text) {
            $translation->set($field, $text);
        }
        $translation->markManual($entity->sourceHash());
    }
}
