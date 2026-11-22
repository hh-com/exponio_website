<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Duplikat-Erkennung: Upload-Historie (item_upload), Zähler und Duplikat-Hinweis am Objekt,
 * Wahrnehmungs-Hash und Upload-Zuordnung je Foto, Einstellungen für den Vergleich.
 *
 * Jedes vorhandene Objekt bekommt genau einen Upload-Eintrag (Ordner aus source_folder, Zeitpunkt aus created_at),
 * dem alle seine Fotos zugeordnet werden. Die Hashes der vorhandenen Fotos berechnet danach
 * "php bin/console app:duplicates:scan" (mit --dry-run nur anzeigen).
 * Geschrieben für MySQL 8 und MariaDB 10.11+.
 */
final class Version20261121180000 extends AbstractMigration
{
    private const TABLE_OPTIONS = 'DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`';

    public function getDescription(): string
    {
        return 'Duplikat-Erkennung: Upload-Historie, Upload-Zähler, Duplikat-Hinweis, Bild-Hash, Einstellungen';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Diese Migration ist für MySQL/MariaDB geschrieben.');

        $this->addSql('CREATE TABLE item_upload (id INT AUTO_INCREMENT NOT NULL, folder VARCHAR(100) DEFAULT NULL, uploaded_at DATETIME NOT NULL, result VARCHAR(20) NOT NULL, manual TINYINT DEFAULT 0 NOT NULL, specimen VARCHAR(10) DEFAULT NULL, confidence DOUBLE PRECISION DEFAULT NULL, better TINYINT DEFAULT NULL, images_replaced TINYINT DEFAULT 0 NOT NULL, proposal TINYINT DEFAULT 0 NOT NULL, reason LONGTEXT DEFAULT NULL, comparison JSON DEFAULT NULL, recognized JSON DEFAULT NULL, snapshot JSON DEFAULT NULL, undone_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, item_id INT NOT NULL, INDEX IDX_914493D0126F525E (item_id), INDEX idx_item_upload_result (result, uploaded_at), PRIMARY KEY (id)) '.self::TABLE_OPTIONS);
        $this->addSql('ALTER TABLE item_upload ADD CONSTRAINT FK_914493D0126F525E FOREIGN KEY (item_id) REFERENCES item (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE item ADD upload_count INT DEFAULT 1 NOT NULL, ADD duplicate_status VARCHAR(10) DEFAULT NULL, ADD duplicate_confidence DOUBLE PRECISION DEFAULT NULL, ADD duplicate_reason LONGTEXT DEFAULT NULL, ADD duplicate_result JSON DEFAULT NULL, ADD duplicate_of_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE item ADD CONSTRAINT FK_1F1B251E2CC33300 FOREIGN KEY (duplicate_of_id) REFERENCES item (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_1F1B251E2CC33300 ON item (duplicate_of_id)');
        $this->addSql('CREATE INDEX idx_item_upload_count ON item (upload_count)');

        // Fotos: Hash, Upload-Zuordnung; ohne Objekt (item_id NULL) = ausgeblendetes, früheres Foto
        $this->addSql('ALTER TABLE item_image ADD hash VARCHAR(16) DEFAULT NULL, ADD upload_id INT DEFAULT NULL, CHANGE item_id item_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE item_image ADD CONSTRAINT FK_EF9A1F8FCCCFBA31 FOREIGN KEY (upload_id) REFERENCES item_upload (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_EF9A1F8FCCCFBA31 ON item_image (upload_id)');

        $this->addSql('ALTER TABLE settings ADD ai_duplicate_prompt LONGTEXT DEFAULT NULL, ADD duplicate_check TINYINT DEFAULT 1 NOT NULL, ADD duplicate_threshold DOUBLE PRECISION DEFAULT 0.85 NOT NULL, ADD duplicate_hint_threshold DOUBLE PRECISION DEFAULT 0.5 NOT NULL, ADD duplicate_auto_replace_published TINYINT DEFAULT 0 NOT NULL');

        // genau ein Upload-Eintrag je vorhandenem Objekt, alle Fotos gehören dazu
        $this->addSql("INSERT INTO item_upload (item_id, folder, uploaded_at, result, manual, images_replaced, proposal, created_at) SELECT id, source_folder, created_at, 'new', 0, 0, 0, created_at FROM item");
        $this->addSql('UPDATE item_image img INNER JOIN item_upload u ON u.item_id = img.item_id SET img.upload_id = u.id');
        $this->addSql('UPDATE item SET upload_count = 1');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Diese Migration ist für MySQL/MariaDB geschrieben.');

        // ausgeblendete (frühere) Fotos haben kein Objekt mehr; ihre Dateien bleiben im Medienordner liegen
        $this->addSql('DELETE FROM item_image WHERE item_id IS NULL');
        $this->addSql('ALTER TABLE item_image DROP FOREIGN KEY FK_EF9A1F8FCCCFBA31');
        $this->addSql('DROP INDEX IDX_EF9A1F8FCCCFBA31 ON item_image');
        $this->addSql('ALTER TABLE item_image DROP hash, DROP upload_id, CHANGE item_id item_id INT NOT NULL');

        $this->addSql('ALTER TABLE item DROP FOREIGN KEY FK_1F1B251E2CC33300');
        $this->addSql('DROP INDEX IDX_1F1B251E2CC33300 ON item');
        $this->addSql('DROP INDEX idx_item_upload_count ON item');
        $this->addSql('ALTER TABLE item DROP upload_count, DROP duplicate_status, DROP duplicate_confidence, DROP duplicate_reason, DROP duplicate_result, DROP duplicate_of_id');

        $this->addSql('ALTER TABLE settings DROP ai_duplicate_prompt, DROP duplicate_check, DROP duplicate_threshold, DROP duplicate_hint_threshold, DROP duplicate_auto_replace_published');
        $this->addSql('DROP TABLE item_upload');
    }
}
