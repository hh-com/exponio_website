<?php

namespace App\Tests\Unit;

use App\Catalog\CatalogQuery;
use App\Catalog\SimilarItems;
use App\Entity\AttributeDefinition;
use App\Entity\Category;
use App\Entity\Item;
use App\Entity\Topic;
use App\Util\Slug;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class BasicsTest extends TestCase
{
    public function testSlugHandlesUmlautsIndependentOfIntl(): void
    {
        self::assertSame('oesterreich-1955-burgen-und-schloesser', Slug::make('Österreich 1955, Burgen und Schlösser'));
        self::assertSame('grosse-strasse', Slug::make('Große Straße'));
        self::assertSame('objekt', Slug::make('   ', 'objekt'));
    }

    public function testCatalogQueryParsesAndRoundTrips(): void
    {
        $request = Request::create('/de/katalog', 'GET', [
            'q' => ' Heuss ',
            'area' => '2',
            'region' => '3,4,x,4',
            'topic' => '7',
            'from' => '1950',
            'to' => 'abc',
            'attr' => ['condition' => 'mnh,used', 'BAD CODE' => 'x'],
            'sort' => 'year_desc',
            'page' => '3',
        ]);
        $query = CatalogQuery::fromRequest($request);

        self::assertSame('Heuss', $query->q);
        self::assertSame(2, $query->area);
        self::assertSame([3, 4], $query->regions);
        self::assertSame(1950, $query->yearFrom);
        self::assertNull($query->yearTo);
        self::assertSame(['condition' => ['mnh', 'used']], $query->attributes);
        self::assertSame('year_desc', $query->sort);
        self::assertSame(3, $query->page);

        $params = $query->toParams();
        self::assertSame('3,4', $params['region']);
        self::assertSame('mnh,used', $params['attr']['condition']);
        self::assertArrayNotHasKey('page', $query->withPage(1)->toParams());

        $query->fixedArea = 2;
        self::assertArrayNotHasKey('area', $query->toParams(), 'feste Filter gehören nicht in die URL');
    }

    public function testInvalidSortFallsBackToDefault(): void
    {
        self::assertSame('new', CatalogQuery::fromRequest(Request::create('/', 'GET', ['sort' => 'drop table']))->sort);
    }

    public function testAttributeOptionsTextParsing(): void
    {
        $definition = new AttributeDefinition('condition', 'Erhaltung');
        $definition->setOptionsText("mnh | postfrisch (**)\nUsed Stamp|gestempelt\n\n  | leer");

        self::assertSame(['mnh', 'used_stamp'], $definition->getOptionValues());
        self::assertSame([
            ['value' => 'mnh', 'label' => 'postfrisch (**)'],
            ['value' => 'used_stamp', 'label' => 'gestempelt'],
        ], $definition->getOptions());

        $definition->translationFor('en')->setOptionLabels(['mnh' => 'mint never hinged']);
        self::assertSame('mint never hinged', $definition->getOptionLabel('mnh', 'en'));
        self::assertSame('gestempelt', $definition->getOptionLabel('used_stamp', 'en'), 'ohne Übersetzung gilt Deutsch');
        self::assertSame('Erhaltung', $definition->getLabel('fr'));
    }

    public function testSimilarityPrefersSeriesRegionAndTopics(): void
    {
        $germany = new Category('Deutschland');
        $bund = new Category('Bund', $germany);
        $ddr = new Category('DDR', $germany);
        $animals = new Topic('Tiere');

        $item = (new Item())->setCategory($bund)->setYear(1960)->setSeries('Tiere')->addTopic($animals);
        $sameSeries = (new Item())->setCategory($bund)->setYear(1962)->setSeries('Tiere')->addTopic($animals);
        $sibling = (new Item())->setCategory($ddr)->setYear(1960);
        $unrelated = (new Item())->setYear(1890);

        $a = SimilarItems::score($item, $sameSeries);
        $b = SimilarItems::score($item, $sibling);
        $c = SimilarItems::score($item, $unrelated);
        self::assertGreaterThan($b, $a);
        self::assertGreaterThan($c, $b);
    }

    public function testItemAttributeValuesBelongToCurrentArea(): void
    {
        $stamps = new \App\Entity\CollectionArea('Briefmarken');
        $coins = new \App\Entity\CollectionArea('Münzen');
        $michel = new AttributeDefinition('michel', 'Michel-Nr.');
        $grade = new AttributeDefinition('grade', 'Erhaltung');
        $stamps->addAttributeDefinition($michel);
        $coins->addAttributeDefinition($grade);

        $item = (new Item())->setArea($stamps);
        $item->setAttributeValue($michel, '213 A');
        self::assertSame('213 A', $item->getAttributeValue('michel'));

        $item->setAttributeValue($michel, '');
        self::assertNull($item->getAttributeValue('michel'), 'leerer Wert entfernt das Merkmal');

        $item->setAttributeValue($michel, '1');
        $item->setArea($coins);
        $item->pruneForeignAttributes();
        self::assertCount(0, $item->getAttributeValues());
    }

    public function testTranslationsFallBackToGermanAndTrackSourceChanges(): void
    {
        $item = (new Item())->setTitle('Bund 1955, Theodor Heuss')->setDescription('Freimarke.');
        self::assertSame('Bund 1955, Theodor Heuss', $item->getTitle('fr'), 'ohne Übersetzung gilt Deutsch');
        self::assertFalse($item->isTranslatedTo('fr'));

        $hash = $item->sourceHash();
        $item->translationFor('fr')->applyMachineTranslation(['title' => 'RFA 1955, Theodor Heuss', 'description' => 'Timbre d’usage courant.'], $hash);
        self::assertSame('RFA 1955, Theodor Heuss', $item->getTitle('fr'));
        $french = $item->translation('fr');
        self::assertNotNull($french);
        self::assertTrue($french->isCurrent($item->sourceHash()));

        $item->setTitle('Bund 1955, 10 Pf Theodor Heuss');
        self::assertFalse($french->isCurrent($item->sourceHash()), 'geänderter deutscher Text macht die Übersetzung veraltet');
        self::assertSame(['fr'], array_map(static fn ($t): string => $t->getLocale(), $item->getOtherTranslations()));
    }
}
