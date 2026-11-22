<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Erstes Schema der Sammlung (MySQL 8 / MariaDB).
 */
final class Version20261120230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Grundschema: Objekte, Bilder, Merkmale, Sammelgebiete, Gebiete, Themen, Anfragen, Seiten, Einstellungen, Scan-Protokoll, Benutzer';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Diese Migration ist für MySQL/MariaDB geschrieben.');

        $this->addSql('CREATE TABLE inquiry (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, email VARCHAR(180) NOT NULL, phone VARCHAR(50) DEFAULT NULL, message LONGTEXT NOT NULL, locale VARCHAR(5) NOT NULL, status VARCHAR(20) NOT NULL, admin_note LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, item_id INT DEFAULT NULL, INDEX IDX_5A3903F0126F525E (item_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE page (id INT AUTO_INCREMENT NOT NULL, title_de VARCHAR(150) NOT NULL, title_en VARCHAR(150) DEFAULT NULL, content_de LONGTEXT DEFAULT NULL, content_en LONGTEXT DEFAULT NULL, show_in_footer TINYINT NOT NULL, position INT NOT NULL, active TINYINT NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE admin_user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_AD8A54A9E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE settings (id INT AUTO_INCREMENT NOT NULL, site_name VARCHAR(120) NOT NULL, tagline_de VARCHAR(255) DEFAULT NULL, tagline_en VARCHAR(255) DEFAULT NULL, intro_de LONGTEXT DEFAULT NULL, intro_en LONGTEXT DEFAULT NULL, contact_email VARCHAR(180) DEFAULT NULL, show_prices TINYINT NOT NULL, currency VARCHAR(3) NOT NULL, auto_publish TINYINT NOT NULL, auto_publish_threshold DOUBLE PRECISION NOT NULL, items_per_page INT NOT NULL, ai_instructions LONGTEXT DEFAULT NULL, footer_text_de LONGTEXT DEFAULT NULL, footer_text_en LONGTEXT DEFAULT NULL, default_scan_area_id INT DEFAULT NULL, privacy_page_id INT DEFAULT NULL, INDEX IDX_E545A0C5C500CB08 (default_scan_area_id), INDEX IDX_E545A0C5723984F4 (privacy_page_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE scan_job (id INT AUTO_INCREMENT NOT NULL, folder VARCHAR(100) NOT NULL, status VARCHAR(20) NOT NULL, image_count INT NOT NULL, message LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, item_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_8FFE2CAFECA209CD (folder), INDEX IDX_8FFE2CAF126F525E (item_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE attribute_definition (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(50) NOT NULL, label_de VARCHAR(120) NOT NULL, label_en VARCHAR(120) DEFAULT NULL, type VARCHAR(20) NOT NULL, options JSON NOT NULL, filterable TINYINT NOT NULL, show_in_card TINYINT NOT NULL, position INT NOT NULL, unit VARCHAR(20) DEFAULT NULL, ai_hint VARCHAR(255) DEFAULT NULL, area_id INT NOT NULL, UNIQUE INDEX uniq_area_code (area_id, code), INDEX IDX_6C5628BDBD0F409C (area_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE category (id INT AUTO_INCREMENT NOT NULL, name_de VARCHAR(150) NOT NULL, name_en VARCHAR(150) DEFAULT NULL, description_de LONGTEXT DEFAULT NULL, description_en LONGTEXT DEFAULT NULL, position INT NOT NULL, active TINYINT NOT NULL, created_at DATETIME NOT NULL, parent_id INT DEFAULT NULL, INDEX idx_category_name_de (name_de), INDEX IDX_64C19C1727ACA70 (parent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE item (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(20) NOT NULL, title_de VARCHAR(255) NOT NULL, title_en VARCHAR(255) DEFAULT NULL, description_de LONGTEXT DEFAULT NULL, description_en LONGTEXT DEFAULT NULL, year SMALLINT DEFAULT NULL, year_to SMALLINT DEFAULT NULL, issue_date DATETIME DEFAULT NULL, series_de VARCHAR(255) DEFAULT NULL, series_en VARCHAR(255) DEFAULT NULL, availability VARCHAR(20) NOT NULL, price NUMERIC(10, 2) DEFAULT NULL, catalog_value NUMERIC(10, 2) DEFAULT NULL, featured TINYINT NOT NULL, source_folder VARCHAR(100) DEFAULT NULL, ai_result JSON DEFAULT NULL, ai_confidence DOUBLE PRECISION DEFAULT NULL, ai_model VARCHAR(80) DEFAULT NULL, ai_error LONGTEXT DEFAULT NULL, recognized_at DATETIME DEFAULT NULL, storage_location VARCHAR(120) DEFAULT NULL, internal_notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, published_at DATETIME DEFAULT NULL, area_id INT DEFAULT NULL, category_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_1F1B251E5A5D2C2B (source_folder), INDEX idx_item_status_published (status, published_at), INDEX idx_item_year (year), INDEX IDX_1F1B251EBD0F409C (area_id), INDEX IDX_1F1B251E12469DE2 (category_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE item_topic (item_id INT NOT NULL, topic_id INT NOT NULL, INDEX IDX_B7E7C5CB126F525E (item_id), INDEX IDX_B7E7C5CB1F55203D (topic_id), PRIMARY KEY (item_id, topic_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE item_image (id INT AUTO_INCREMENT NOT NULL, path VARCHAR(190) NOT NULL, position INT NOT NULL, width INT NOT NULL, height INT NOT NULL, item_id INT NOT NULL, INDEX IDX_EF9A1F8F126F525E (item_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE item_attribute (id INT AUTO_INCREMENT NOT NULL, value VARCHAR(191) NOT NULL, item_id INT NOT NULL, definition_id INT NOT NULL, INDEX idx_attribute_value (definition_id, value), UNIQUE INDEX uniq_item_definition (item_id, definition_id), INDEX IDX_F6A0F90B126F525E (item_id), INDEX IDX_F6A0F90BD11EA911 (definition_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE collection_area (id INT AUTO_INCREMENT NOT NULL, name_de VARCHAR(120) NOT NULL, name_en VARCHAR(120) DEFAULT NULL, description_de LONGTEXT DEFAULT NULL, description_en LONGTEXT DEFAULT NULL, icon VARCHAR(30) NOT NULL, position INT NOT NULL, active TINYINT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE topic (id INT AUTO_INCREMENT NOT NULL, name_de VARCHAR(120) NOT NULL, name_en VARCHAR(120) DEFAULT NULL, description_de LONGTEXT DEFAULT NULL, description_en LONGTEXT DEFAULT NULL, position INT NOT NULL, active TINYINT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE inquiry ADD CONSTRAINT FK_5A3903F0126F525E FOREIGN KEY (item_id) REFERENCES item (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE settings ADD CONSTRAINT FK_E545A0C5C500CB08 FOREIGN KEY (default_scan_area_id) REFERENCES collection_area (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE settings ADD CONSTRAINT FK_E545A0C5723984F4 FOREIGN KEY (privacy_page_id) REFERENCES page (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE scan_job ADD CONSTRAINT FK_8FFE2CAF126F525E FOREIGN KEY (item_id) REFERENCES item (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE attribute_definition ADD CONSTRAINT FK_6C5628BDBD0F409C FOREIGN KEY (area_id) REFERENCES collection_area (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE category ADD CONSTRAINT FK_64C19C1727ACA70 FOREIGN KEY (parent_id) REFERENCES category (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE item ADD CONSTRAINT FK_1F1B251EBD0F409C FOREIGN KEY (area_id) REFERENCES collection_area (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE item ADD CONSTRAINT FK_1F1B251E12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE item_topic ADD CONSTRAINT FK_B7E7C5CB126F525E FOREIGN KEY (item_id) REFERENCES item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE item_topic ADD CONSTRAINT FK_B7E7C5CB1F55203D FOREIGN KEY (topic_id) REFERENCES topic (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE item_image ADD CONSTRAINT FK_EF9A1F8F126F525E FOREIGN KEY (item_id) REFERENCES item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE item_attribute ADD CONSTRAINT FK_F6A0F90B126F525E FOREIGN KEY (item_id) REFERENCES item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE item_attribute ADD CONSTRAINT FK_F6A0F90BD11EA911 FOREIGN KEY (definition_id) REFERENCES attribute_definition (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('SET FOREIGN_KEY_CHECKS = 0');
        $this->addSql('DROP TABLE topic');
        $this->addSql('DROP TABLE collection_area');
        $this->addSql('DROP TABLE item_attribute');
        $this->addSql('DROP TABLE item_image');
        $this->addSql('DROP TABLE item_topic');
        $this->addSql('DROP TABLE item');
        $this->addSql('DROP TABLE category');
        $this->addSql('DROP TABLE attribute_definition');
        $this->addSql('DROP TABLE scan_job');
        $this->addSql('DROP TABLE settings');
        $this->addSql('DROP TABLE admin_user');
        $this->addSql('DROP TABLE page');
        $this->addSql('DROP TABLE inquiry');
        $this->addSql('SET FOREIGN_KEY_CHECKS = 1');
    }
}
