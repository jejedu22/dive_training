<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Ordre des lignes d\'eau dans leur bassin'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lane ADD position INT DEFAULT 0 NOT NULL');
        // Lignes existantes : numérotées dans leur ordre de création, bassin par bassin
        $this->addSql('UPDATE lane SET position = sub.rn FROM (SELECT id, ROW_NUMBER() OVER (PARTITION BY pool_id ORDER BY id) - 1 AS rn FROM lane) sub WHERE lane.id = sub.id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lane DROP position');
    }
}
