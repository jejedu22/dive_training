<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123090000 extends AbstractMigration
{
    public function getDescription(): string { return 'Liens de partage : code d\'accès facultatif et blocage après codes erronés'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE share_link ADD code_hash VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE share_link ADD failed_attempts INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE share_link ADD locked_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE share_link DROP code_hash');
        $this->addSql('ALTER TABLE share_link DROP failed_attempts');
        $this->addSql('ALTER TABLE share_link DROP locked_until');
    }
}
