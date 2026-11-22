<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hinweis an die KI je Objekt: geht bei jeder Erkennung mit.
 * Geschrieben für MySQL 8 und MariaDB 10.11+.
 */
final class Version20261122233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Objekte: Hinweis an die KI';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Diese Migration ist für MySQL/MariaDB geschrieben.');
        $this->addSql('ALTER TABLE item ADD ai_hint LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Diese Migration ist für MySQL/MariaDB geschrieben.');
        $this->addSql('ALTER TABLE item DROP ai_hint');
    }
}
