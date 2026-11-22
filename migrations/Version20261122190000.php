<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * OpenAI (ChatGPT) als KI-Anbieter: eigener verschlüsselter API-Key.
 * Geschrieben für MySQL 8 und MariaDB 10.11+.
 */
final class Version20261122190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Einstellungen: OpenAI-API-Key';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Diese Migration ist für MySQL/MariaDB geschrieben.');

        $this->addSql('ALTER TABLE settings ADD openai_api_key LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Diese Migration ist für MySQL/MariaDB geschrieben.');

        $this->addSql('ALTER TABLE settings DROP openai_api_key');
    }
}
