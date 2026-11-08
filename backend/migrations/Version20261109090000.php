<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109090000 extends AbstractMigration
{
    public function getDescription(): string { return 'Comptes : téléphone, couleur d\'avatar, préférences, dates de création et de dernière connexion'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD phone VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD avatar_color VARCHAR(7) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD preferences JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP phone');
        $this->addSql('ALTER TABLE app_user DROP avatar_color');
        $this->addSql('ALTER TABLE app_user DROP preferences');
        $this->addSql('ALTER TABLE app_user DROP created_at');
        $this->addSql('ALTER TABLE app_user DROP last_login_at');
    }
}
