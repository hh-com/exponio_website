<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bilder im Backend zuschneiden: Ausschnitt gegenüber dem Original, damit app:media:rebuild ihn beibehält.
 * Geschrieben für MySQL 8 und MariaDB 10.11+.
 */
final class Version20261122230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bilder: Zuschnitt gegenüber dem Original';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Diese Migration ist für MySQL/MariaDB geschrieben.');
        $this->addSql('ALTER TABLE item_image ADD crop JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Diese Migration ist für MySQL/MariaDB geschrieben.');
        $this->addSql('ALTER TABLE item_image DROP crop');
    }
}
