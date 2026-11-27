<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mehrere Modelle für die Erkennung: Zeilen in settings.ai_recognizers statt Anbieter und Modell, dazu die Ergebnisse
 * je Modell (recognition_result). Ein bisher gewählter Anbieter bzw. ein eingetragenes Modell wird zur ersten Zeile.
 * Geschrieben für MySQL 8 und MariaDB 10.11+.
 */
final class Version20261127140000 extends AbstractMigration
{
    private const TABLE_OPTIONS = 'DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`';

    public function getDescription(): string
    {
        return 'Mehrere Modelle für die Erkennung und Ergebnisse je Modell';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Diese Migration ist für MySQL/MariaDB geschrieben.');

        $this->addSql('CREATE TABLE recognition_result (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, provider VARCHAR(20) NOT NULL, model VARCHAR(80) NOT NULL, model_used VARCHAR(80) DEFAULT NULL, data JSON DEFAULT NULL, confidence DOUBLE PRECISION DEFAULT NULL, error LONGTEXT DEFAULT NULL, duration_ms INT NOT NULL, input_tokens INT DEFAULT NULL, output_tokens INT DEFAULT NULL, cost DOUBLE PRECISION DEFAULT NULL, applied TINYINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, item_id INT NOT NULL, INDEX IDX_D0AA9A48126F525E (item_id), PRIMARY KEY (id)) '.self::TABLE_OPTIONS);
        $this->addSql('ALTER TABLE recognition_result ADD CONSTRAINT FK_D0AA9A48126F525E FOREIGN KEY (item_id) REFERENCES item (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE settings ADD ai_recognizers JSON DEFAULT NULL');
        foreach ($this->connection->fetchAllAssociative('SELECT id, ai_provider, ai_model FROM settings') as $row) {
            $provider = \is_string($row['ai_provider']) && '' !== trim($row['ai_provider']) ? strtolower(trim($row['ai_provider'])) : null;
            $model = \is_string($row['ai_model']) && '' !== trim($row['ai_model']) ? trim($row['ai_model']) : null;
            if (null === $provider && null === $model) {
                continue; // weiter AI_PROVIDER aus .env mit dessen Standardmodell
            }
            // Ein Modell ohne Anbieter galt bisher für AI_PROVIDER aus .env
            $provider ??= strtolower(trim((string) ($_ENV['AI_PROVIDER'] ?? $_SERVER['AI_PROVIDER'] ?? 'gemini')));
            $rows = [['provider' => $provider, 'model' => $model, 'active' => true, 'priceInput' => null, 'priceOutput' => null]];
            $this->addSql('UPDATE settings SET ai_recognizers = ? WHERE id = ?', [json_encode($rows, \JSON_THROW_ON_ERROR), $row['id']]);
        }
        $this->addSql('ALTER TABLE settings DROP ai_provider, DROP ai_model');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Diese Migration ist für MySQL/MariaDB geschrieben.');

        $this->addSql('ALTER TABLE settings ADD ai_provider VARCHAR(20) DEFAULT NULL, ADD ai_model VARCHAR(80) DEFAULT NULL');
        foreach ($this->connection->fetchAllAssociative('SELECT id, ai_recognizers FROM settings WHERE ai_recognizers IS NOT NULL') as $row) {
            $rows = json_decode((string) $row['ai_recognizers'], true);
            foreach (\is_array($rows) ? $rows : [] as $entry) {
                if (\is_array($entry) && true === ($entry['active'] ?? false)) {
                    $this->addSql('UPDATE settings SET ai_provider = ?, ai_model = ? WHERE id = ?', [$entry['provider'] ?? null, $entry['model'] ?? null, $row['id']]);
                    break;
                }
            }
        }
        $this->addSql('ALTER TABLE settings DROP ai_recognizers');
        $this->addSql('DROP TABLE recognition_result');
    }
}
