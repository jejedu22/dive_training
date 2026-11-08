<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108150000 extends AbstractMigration
{
    private const PALETTE = ['#1f6fb5', '#c0392b', '#1e8449', '#7d3c98', '#d35400', '#0e8a96', '#8d5524', '#c2378f', '#3949ab', '#7a7d0f', '#00796b', '#5d6d7e'];

    public function getDescription(): string { return 'Couleur des sections'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE section ADD color VARCHAR(7) DEFAULT NULL');
        // Sections existantes : couleurs de la palette, dans l'ordre de création
        foreach ($this->connection->fetchFirstColumn('SELECT id FROM section ORDER BY id') as $i => $id) {
            $this->addSql('UPDATE section SET color = ? WHERE id = ?', [self::PALETTE[$i % count(self::PALETTE)], (int) $id]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE section DROP color');
    }
}
