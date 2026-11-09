<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110150000 extends AbstractMigration
{
    public function getDescription(): string { return 'Sections : utilise des bouteilles ou non (oui par défaut)'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE section ADD uses_tanks BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE section DROP uses_tanks');
    }
}
