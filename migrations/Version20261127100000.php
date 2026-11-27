<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * App-Zugänge: Tokens der Exponio-App für die Upload-Schnittstelle (gespeichert als SHA-256-Hash).
 * Geschrieben für MySQL 8 und MariaDB 10.11+.
 */
final class Version20261127100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'App-Zugänge für die Upload-Schnittstelle der Exponio-App';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Diese Migration ist für MySQL/MariaDB geschrieben.');
        $this->addSql('CREATE TABLE app_token (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(80) NOT NULL, token_hash VARCHAR(64) NOT NULL, hint VARCHAR(8) NOT NULL, created_at DATETIME NOT NULL, last_used_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_89E42CF6B3BC57DA (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Diese Migration ist für MySQL/MariaDB geschrieben.');
        $this->addSql('DROP TABLE app_token');
    }
}
