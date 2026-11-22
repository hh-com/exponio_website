<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Ai\DefaultPrompts;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Überarbeitung 2026-11-21: Übersetzungstabellen für alle Sprachen, Sprachtabelle,
 * Gemini-Einstellungen, Mängel, Bildseite, Schätzwert-Freigabe; Verkaufsfelder entfallen.
 *
 * Vorhandene Texte (Deutsch und Englisch) werden in die Übersetzungstabellen übernommen,
 * bevor die alten Spalten entfernt werden. Englische Texte haben noch keinen Hash und werden
 * deshalb beim ersten Lauf von app:translate einmal neu übersetzt.
 * Danach "php bin/console app:setup" ausführen (legt die Sprachen an und ergänzt neue Merkmale).
 */
final class Version20261121120000 extends AbstractMigration
{
    private const TRANSLATION_COLUMNS = 'id INT AUTO_INCREMENT NOT NULL, locale VARCHAR(12) NOT NULL, origin VARCHAR(10) NOT NULL, source_hash VARCHAR(40) DEFAULT NULL, updated_at DATETIME NOT NULL';
    private const TABLE_OPTIONS = 'DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`';

    public function getDescription(): string
    {
        return 'Übersetzungstabellen, Sprachen, Oberflächentexte, Gemini, Mängel, Bildseite, Schätzwert-Freigabe; Verkaufsfelder entfernt';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Diese Migration ist für MySQL/MariaDB geschrieben.');

        // Daten, die in PHP umgewandelt werden, jetzt lesen (die SQL-Befehle unten laufen erst danach)
        $definitions = $this->connection->fetchAllAssociative('SELECT id, label_de, label_en, options FROM attribute_definition');
        $settings = $this->connection->fetchAllAssociative('SELECT id, ai_instructions FROM settings');

        // ---- neue Tabellen ----
        $this->createTranslationTable('item_translation', 'item_id', 'item', 'title VARCHAR(255) DEFAULT NULL, description LONGTEXT DEFAULT NULL, series VARCHAR(255) DEFAULT NULL, defects LONGTEXT DEFAULT NULL, meta_description VARCHAR(300) DEFAULT NULL', 'uniq_item_translation', 'FK_54011D59126F525E');
        $this->createTranslationTable('item_image_translation', 'image_id', 'item_image', 'alt VARCHAR(255) DEFAULT NULL', 'uniq_item_image_translation', 'FK_F68F61173DA5256D');
        $this->createTranslationTable('collection_area_translation', 'area_id', 'collection_area', 'name VARCHAR(150) DEFAULT NULL, description LONGTEXT DEFAULT NULL', 'uniq_collection_area_translation', 'FK_ED593581BD0F409C');
        $this->createTranslationTable('category_translation', 'category_id', 'category', 'name VARCHAR(150) DEFAULT NULL, description LONGTEXT DEFAULT NULL, INDEX idx_category_translation_name (name)', 'uniq_category_translation', 'FK_3F2070412469DE2');
        $this->createTranslationTable('topic_translation', 'topic_id', 'topic', 'name VARCHAR(150) DEFAULT NULL, description LONGTEXT DEFAULT NULL', 'uniq_topic_translation', 'FK_6F17DF171F55203D');
        $this->createTranslationTable('page_translation', 'page_id', 'page', 'title VARCHAR(150) DEFAULT NULL, content LONGTEXT DEFAULT NULL', 'uniq_page_translation', 'FK_A3D51B1DC4663E4');
        $this->createTranslationTable('attribute_definition_translation', 'definition_id', 'attribute_definition', 'label VARCHAR(120) DEFAULT NULL, option_labels JSON NOT NULL', 'uniq_attribute_definition_translation', 'FK_329745B6D11EA911');
        $this->createTranslationTable('settings_translation', 'settings_id', 'settings', 'tagline VARCHAR(255) DEFAULT NULL, intro LONGTEXT DEFAULT NULL, footer_text LONGTEXT DEFAULT NULL', 'uniq_settings_translation', 'FK_DB9CEA9959949888');
        $this->addSql('CREATE TABLE language (code VARCHAR(12) NOT NULL, native_name VARCHAR(80) NOT NULL, name_de VARCHAR(80) NOT NULL, rtl TINYINT NOT NULL, active TINYINT NOT NULL, position INT NOT NULL, ui_translated_at DATETIME DEFAULT NULL, INDEX idx_language_active (active, position), PRIMARY KEY (code)) '.self::TABLE_OPTIONS);
        $this->addSql('CREATE TABLE ui_translation (id INT AUTO_INCREMENT NOT NULL, locale VARCHAR(12) NOT NULL, message_key VARCHAR(190) NOT NULL, text LONGTEXT NOT NULL, source_hash VARCHAR(40) NOT NULL, origin VARCHAR(10) NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_ui_translation (locale, message_key), PRIMARY KEY (id)) '.self::TABLE_OPTIONS);

        // ---- neue Spalten ----
        $this->addSql('ALTER TABLE item ADD show_estimate TINYINT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE item_image ADD side VARCHAR(10) DEFAULT NULL, ADD source_file VARCHAR(190) DEFAULT NULL');
        $this->addSql('ALTER TABLE settings ADD owner_name VARCHAR(120) DEFAULT NULL, ADD ai_model VARCHAR(80) DEFAULT NULL, ADD ai_translation_model VARCHAR(80) DEFAULT NULL, ADD ai_recognition_prompt LONGTEXT DEFAULT NULL, ADD ai_translation_prompt LONGTEXT DEFAULT NULL, ADD gemini_api_key LONGTEXT DEFAULT NULL, ADD allow_ai_crawlers TINYINT NOT NULL DEFAULT 1, ADD message_retention_months INT NOT NULL DEFAULT 6');
        $this->addSql('ALTER TABLE inquiry MODIFY locale VARCHAR(12) NOT NULL');

        // ---- Texte übernehmen: Deutsch = Ausgangstext, Englisch = Übersetzung ----
        $this->copyTexts('item_translation', 'item_id', 'item', ['title' => 'title', 'description' => 'description', 'series' => 'series']);
        $this->copyTexts('collection_area_translation', 'area_id', 'collection_area', ['name' => 'name', 'description' => 'description']);
        $this->copyTexts('category_translation', 'category_id', 'category', ['name' => 'name', 'description' => 'description']);
        $this->copyTexts('topic_translation', 'topic_id', 'topic', ['name' => 'name', 'description' => 'description']);
        $this->copyTexts('page_translation', 'page_id', 'page', ['title' => 'title', 'content' => 'content']);
        $this->copyTexts('settings_translation', 'settings_id', 'settings', ['tagline' => 'tagline', 'intro' => 'intro', 'footer_text' => 'footer_text']);

        // Merkmale: Optionen [{value, de, en}] → Werte in attribute_definition, Beschriftungen je Sprache
        foreach ($definitions as $row) {
            $options = json_decode((string) $row['options'], true) ?: [];
            $values = $de = $en = [];
            foreach ($options as $option) {
                $values[] = (string) $option['value'];
                $de[(string) $option['value']] = (string) ($option['de'] ?? $option['value']);
                if ('' !== (string) ($option['en'] ?? '')) {
                    $en[(string) $option['value']] = (string) $option['en'];
                }
            }
            $this->addSql('UPDATE attribute_definition SET options = ? WHERE id = ?', [json_encode($values), $row['id']]);
            $this->addSql("INSERT INTO attribute_definition_translation (definition_id, locale, origin, source_hash, updated_at, label, option_labels) VALUES (?, 'de', 'source', NULL, NOW(), ?, ?)", [$row['id'], $row['label_de'], json_encode($de, \JSON_UNESCAPED_UNICODE)]);
            if (null !== $row['label_en'] || [] !== $en) {
                $this->addSql("INSERT INTO attribute_definition_translation (definition_id, locale, origin, source_hash, updated_at, label, option_labels) VALUES (?, 'en', 'manual', NULL, NOW(), ?, ?)", [$row['id'], $row['label_en'], json_encode($en ?: new \stdClass(), \JSON_UNESCAPED_UNICODE)]);
            }
        }

        // Zusätzliche KI-Hinweise werden Teil des (jetzt editierbaren) Erkennungs-Prompts
        foreach ($settings as $row) {
            $hints = trim((string) $row['ai_instructions']);
            if ('' !== $hints) {
                $this->addSql('UPDATE settings SET ai_recognition_prompt = ? WHERE id = ?', [DefaultPrompts::RECOGNITION."\n\nZusätzliche Hinweise des Sammlers:\n".$hints, $row['id']]);
            }
        }

        // ---- alte Spalten entfernen (Verkauf, Texte je Sprache) ----
        $this->addSql('ALTER TABLE inquiry DROP FOREIGN KEY FK_5A3903F0126F525E');
        $this->addSql('DROP INDEX IDX_5A3903F0126F525E ON inquiry');
        $this->addSql('ALTER TABLE inquiry DROP item_id, DROP phone');
        $this->addSql('ALTER TABLE item DROP title_de, DROP title_en, DROP description_de, DROP description_en, DROP series_de, DROP series_en, DROP availability, DROP price, ALTER show_estimate DROP DEFAULT');
        $this->addSql('ALTER TABLE collection_area DROP name_de, DROP name_en, DROP description_de, DROP description_en');
        $this->addSql('DROP INDEX idx_category_name_de ON category');
        $this->addSql('ALTER TABLE category DROP name_de, DROP name_en, DROP description_de, DROP description_en');
        $this->addSql('ALTER TABLE topic DROP name_de, DROP name_en, DROP description_de, DROP description_en');
        $this->addSql('ALTER TABLE page DROP title_de, DROP title_en, DROP content_de, DROP content_en');
        $this->addSql('ALTER TABLE attribute_definition DROP label_de, DROP label_en');
        $this->addSql('ALTER TABLE settings DROP tagline_de, DROP tagline_en, DROP intro_de, DROP intro_en, DROP footer_text_de, DROP footer_text_en, DROP show_prices, DROP ai_instructions, ALTER allow_ai_crawlers DROP DEFAULT, ALTER message_retention_months DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Die Texte liegen jetzt in Übersetzungstabellen; zurück geht es nur über eine Datensicherung.');
    }

    private function createTranslationTable(string $table, string $parentColumn, string $parentTable, string $columns, string $unique, string $foreignKey): void
    {
        $this->addSql(sprintf(
            'CREATE TABLE %1$s (%2$s, %3$s, %4$s INT NOT NULL, UNIQUE INDEX %5$s (%4$s, locale), INDEX %7$s (%4$s), PRIMARY KEY (id)) %6$s',
            $table, self::TRANSLATION_COLUMNS, $columns, $parentColumn, $unique, self::TABLE_OPTIONS,
            // Doctrine benennt Index und Fremdschlüssel mit demselben Hash
            str_replace('FK_', 'IDX_', $foreignKey),
        ));
        $this->addSql(sprintf('ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (id) ON DELETE CASCADE', $table, $foreignKey, $parentColumn, $parentTable));
    }

    /**
     * Kopiert die Spalten <feld>_de als Ausgangstext und <feld>_en (falls vorhanden) als englische Übersetzung.
     *
     * @param array<string, string> $fields Zielspalte => Präfix der alten Spalten
     */
    private function copyTexts(string $table, string $parentColumn, string $parentTable, array $fields): void
    {
        $target = implode(', ', array_keys($fields));
        $de = implode(', ', array_map(static fn (string $f): string => $f.'_de', $fields));
        $en = implode(', ', array_map(static fn (string $f): string => $f.'_en', $fields));
        $anyEn = implode(' OR ', array_map(static fn (string $f): string => $f.'_en IS NOT NULL', $fields));

        $this->addSql(sprintf("INSERT INTO %s (%s, locale, origin, source_hash, updated_at, %s) SELECT id, 'de', 'source', NULL, NOW(), %s FROM %s", $table, $parentColumn, $target, $de, $parentTable));
        $this->addSql(sprintf("INSERT INTO %s (%s, locale, origin, source_hash, updated_at, %s) SELECT id, 'en', 'ai', NULL, NOW(), %s FROM %s WHERE %s", $table, $parentColumn, $target, $en, $parentTable, $anyEn));
    }
}
