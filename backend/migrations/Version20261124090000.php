<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124090000 extends AbstractMigration
{
    public function getDescription(): string { return 'Liens de partage du livret d\'un stagiaire et suivi des consultations'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE share_link ADD diver_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE share_link ADD last_opened_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE share_link ADD open_count INT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE INDEX IDX_8B6B9468FB321ACA ON share_link (diver_id)');
        $this->addSql('ALTER TABLE share_link ADD CONSTRAINT FK_8B6B9468FB321ACA FOREIGN KEY (diver_id) REFERENCES diver (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE share_link DROP CONSTRAINT FK_8B6B9468FB321ACA');
        $this->addSql('DROP INDEX IDX_8B6B9468FB321ACA');
        $this->addSql('ALTER TABLE share_link DROP diver_id');
        $this->addSql('ALTER TABLE share_link DROP last_opened_at');
        $this->addSql('ALTER TABLE share_link DROP open_count');
    }
}
