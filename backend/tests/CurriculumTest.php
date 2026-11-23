<?php
namespace App\Tests;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/** Modification des cursus par les administrateurs. */
class CurriculumTest extends ApiTestCase
{
    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getRepository(User::class)->findOneBy(['email' => 'ref@x.fr'])->setRoles(['ROLE_ADMIN'])->setLevel('E3');
        $em->flush();
        $this->admin = $this->login('ref@x.fr');
    }

    public function testEditCurriculumKeepsEvaluations(): void
    {
        $this->assertSame(403, $this->call('GET', '/api/curricula', null, $this->inst)[0], 'administrateurs seulement');
        [, $list] = $this->call('GET', '/api/curricula', null, $this->admin);
        $this->assertSame(['N1', 'N2', 'N3', 'INIT'], array_column($list, 'code'));
        $this->assertSame('mft', $list[0]['origin']);

        // un stagiaire évalué sur « Vidage du masque »
        [, $t] = $this->call('POST', '/api/trainees', ['firstName' => 'Léa', 'lastName' => 'Martin', 'curriculum' => 'N1'], $this->admin);
        $e = $t['enrolments'][0]['id'];
        $this->call('POST', "/api/enrolments/$e/evaluations", ['skills' => ['N1.ventiler.masque'], 'status' => 'A', 'place' => 'pool'], $this->admin);

        [, $c] = $this->call('GET', '/api/curricula/N1', null, $this->admin);
        $this->assertSame(1, $c['usage']['skills']['N1.ventiler.masque']);
        $def = $c['definition'];
        // renommer un élément, ajouter un élément et une compétence, supprimer un élément non évalué
        $toList = fn (array $skills) => array_map(fn ($k, $l) => ['key' => $k, 'label' => $l], array_keys($skills), $skills);
        $def['competencies'] = array_map(fn ($x) => ['skills' => $toList($x['skills'])] + $x, $def['competencies']);
        $def['competencies'][4]['skills'][2]['label'] = 'Vidage de masque (renommé)';
        $def['competencies'][4]['skills'][] = ['label' => 'Ventilation sur détendeur de secours'];
        array_pop($def['competencies'][0]['skills']); // « Choix de son matériel personnel »
        $def['competencies'][] = ['title' => 'Nage avec palmes, masque et tuba', 'quals' => ['N1'], 'skills' => [['label' => '200 m PMT']]];
        $def['delayMonths'] = 18;
        [$code, $saved] = $this->call('PUT', '/api/curricula/N1', $def, $this->admin);
        $this->assertSame(200, $code, json_encode($saved));
        $this->assertSame('modified', $saved['origin']);
        $comp = $saved['definition']['competencies'];
        $this->assertSame('Vidage de masque (renommé)', $comp[4]['skills']['N1.ventiler.masque'], 'clé conservée');
        $this->assertArrayHasKey('N1.ventiler.ventilationsurdete', $comp[4]['skills']);
        $this->assertSame('N1.nageavecpalmesmasq', $comp[11]['key']);
        $this->assertCount(2, $comp[0]['skills']);

        // la fiche du stagiaire suit le référentiel modifié
        [, $f] = $this->call('GET', "/api/trainees/{$t['id']}", null, $this->admin);
        $this->assertSame(12, $f['enrolments'][0]['progress']['total']);
        $this->assertSame('A', $f['enrolments'][0]['skills']['N1.ventiler.masque']['status']);
        $this->assertCount(12, $this->call('GET', '/api/mft', null, $this->inst)[1]['curricula'][0]['competencies']);

        // un élément évalué ne peut pas être supprimé
        $def2 = $def;
        array_splice($def2['competencies'][4]['skills'], 2, 1);
        [$code, $r] = $this->call('PUT', '/api/curricula/N1', $def2, $this->admin);
        $this->assertSame(409, $code);
        $this->assertStringContainsString('évalué 1 fois', $r['message']);
        // contrôles
        $bad = $def; $bad['qualifications'] = ['X1' => 'Autre'];
        $this->assertSame(400, $this->call('PUT', '/api/curricula/N1', $bad, $this->admin)[0], 'qualification N1 obligatoire');
        $bad = $def; $bad['competencies'][0]['quals'] = [];
        $this->assertSame(400, $this->call('PUT', '/api/curricula/N1', $bad, $this->admin)[0]);

        // retour au MFT : impossible si un élément ajouté a été évalué, possible sinon
        $this->call('POST', "/api/enrolments/$e/evaluations", ['skills' => ['N1.ventiler.ventilationsurdete'], 'status' => 'ECA', 'place' => 'pool'], $this->admin);
        $this->assertSame(409, $this->call('DELETE', '/api/curricula/N1', null, $this->admin)[0]);
        $ev = $this->call('GET', "/api/trainees/{$t['id']}", null, $this->admin)[1]['enrolments'][0]['evaluations'][0]['id'];
        $this->call('DELETE', "/api/evaluations/$ev", null, $this->admin);
        [$code, $c] = $this->call('DELETE', '/api/curricula/N1', null, $this->admin);
        $this->assertSame([200, 'mft', 11], [$code, $c['origin'], count($c['definition']['competencies'])]);
    }

    public function testClubCurriculumAndInitiatorModules(): void
    {
        [$code, $c] = $this->call('POST', '/api/curricula', ['code' => 'n4', 'name' => 'Guide de palanquée', 'copyFrom' => 'N3'], $this->admin);
        $this->assertSame(201, $code, json_encode($c));
        $this->assertSame(['N4', 'club'], [$c['code'], $c['origin']]);
        $this->assertArrayHasKey('N4', $c['definition']['qualifications']);
        $this->assertStringStartsWith('N4.', $c['definition']['competencies'][0]['key']);
        $this->assertSame(409, $this->call('POST', '/api/curricula', ['code' => 'N4', 'copyFrom' => 'N1'], $this->admin)[0]);
        [, $t] = $this->call('POST', '/api/trainees', ['firstName' => 'A', 'lastName' => 'B', 'curriculum' => 'N4'], $this->admin);
        $this->assertSame('Guide de palanquée', $t['enrolments'][0]['name']);
        $this->assertSame(409, $this->call('DELETE', '/api/curricula/N4', null, $this->admin)[0], 'a des stagiaires');

        // initiateur : modifier un module, prérequis verrouillés
        [, $c] = $this->call('GET', '/api/curricula/INIT', null, $this->admin);
        $def = $c['definition'];
        $def['modules'] = array_map(fn ($m) => [
            'phases' => array_map(fn ($n, $items) => ['name' => $n, 'items' => array_map(fn ($k, $l) => ['key' => $k, 'label' => $l], array_keys($items), $items)], array_keys($m['phases']), $m['phases']),
            'requirements' => array_map(fn ($k, $l) => ['key' => $k, 'label' => $l], array_keys($m['requirements']), $m['requirements']),
        ] + $m, $def['modules']);
        $def['modules'][0]['minSessions'] = 4;
        $def['modules'][2]['phases'][0]['items'][] = ['label' => 'Évaluer le cursus'];
        [$code, $r] = $this->call('PUT', '/api/curricula/INIT', $def, $this->admin);
        $this->assertSame(200, $code, json_encode($r));
        $this->assertSame(4, $r['definition']['modules'][0]['minSessions']);
        $this->assertCount(5, $r['definition']['modules'][2]['phases']['Organiser']);
        $bad = $def; $bad['prerequisites'] = ['Licence'];
        [$code, $r] = $this->call('PUT', '/api/curricula/INIT', $bad, $this->admin);
        $this->assertSame(400, $code);
        $this->assertStringContainsString('utilisé par les règles de l\'examen', $r['message']);
    }
}
