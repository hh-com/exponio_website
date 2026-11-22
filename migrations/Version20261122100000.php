<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Eigenes Logo im Backend: Pfad der erzeugten Logo-Dateien im Medienordner (leer = mitgeliefertes Standardlogo).
 * Geschrieben für MySQL 8 und MariaDB 10.11+.
 */
final class Version20261122100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Einstellungen: eigenes Logo (logo_path)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Diese Migration ist für MySQL/MariaDB geschrieben.');

        $this->addSql('ALTER TABLE settings ADD logo_path VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Diese Migration ist für MySQL/MariaDB geschrieben.');

        // die Logo-Dateien bleiben im Medienordner (media/brand/) liegen
        $this->addSql('ALTER TABLE settings DROP logo_path');
    }
}
