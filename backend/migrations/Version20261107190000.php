<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107190000 extends AbstractMigration
{
    public function getDescription(): string { return 'Comptes : activation / désactivation'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD active BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('UPDATE app_user SET email = LOWER(TRIM(email))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP active');
    }
}
