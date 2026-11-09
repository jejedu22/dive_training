<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Une ligne d'eau peut être partagée par plusieurs sections : une rangée par (ligne, section). */
final class Version20261110120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Plusieurs sections sur une même ligne d\'eau'; }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS UNIQ_3C9D7A6859E5119CA128F72F');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3C9D7A6859E5119CA128F72FD823E37A ON slot_allocation (slot_id, lane_id, section_id)');
        $this->addSql('DROP INDEX IF EXISTS UNIQ_4CE0129263B21C5A128F72F');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4CE0129263B21C5A128F72FD823E37A ON override_allocation (override_id, lane_id, section_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_3C9D7A6859E5119CA128F72FD823E37A');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3C9D7A6859E5119CA128F72F ON slot_allocation (slot_id, lane_id)');
        $this->addSql('DROP INDEX UNIQ_4CE0129263B21C5A128F72FD823E37A');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4CE0129263B21C5A128F72F ON override_allocation (override_id, lane_id)');
    }
}
