<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Le planning s'affiche par défaut en vue Mois : on bascule aussi les préférences déjà enregistrées. */
final class Version20261111120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Planning : vue Mois par défaut pour tous les comptes'; }

    public function up(Schema $schema): void
    {
        foreach ($this->connection->fetchAllAssociative('SELECT id, preferences FROM app_user WHERE preferences IS NOT NULL') as $row) {
            $prefs = json_decode((string) $row['preferences'], true);
            if (!is_array($prefs)) continue;
            $prefs['planningView'] = 'month';
            $this->addSql('UPDATE app_user SET preferences = ? WHERE id = ?', [json_encode($prefs), (int) $row['id']]);
        }
    }

    public function down(Schema $schema): void
    {
    }
}
