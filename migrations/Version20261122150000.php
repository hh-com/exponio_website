<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * KI-Anbieter im Backend wählbar (Gemini, Claude) mit eigenem verschlüsselten API-Key je Anbieter.
 * Geschrieben für MySQL 8 und MariaDB 10.11+.
 */
final class Version20261122150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Einstellungen: KI-Anbieter (ai_provider) und Anthropic-API-Key';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Diese Migration ist für MySQL/MariaDB geschrieben.');

        $this->addSql('ALTER TABLE settings ADD ai_provider VARCHAR(20) DEFAULT NULL, ADD anthropic_api_key LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Diese Migration ist für MySQL/MariaDB geschrieben.');

        $this->addSql('ALTER TABLE settings DROP ai_provider, DROP anthropic_api_key');
    }
}
