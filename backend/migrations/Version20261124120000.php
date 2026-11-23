<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Cursus modifiables par les administrateurs'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE curriculum_definition (code VARCHAR(10) NOT NULL, data JSON NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_by VARCHAR(120) DEFAULT NULL, PRIMARY KEY (code))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE curriculum_definition');
    }
}
