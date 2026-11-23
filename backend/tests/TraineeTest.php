<?php
namespace App\Tests;

use App\Entity\User;
use App\Training\Mft;
use Doctrine\ORM\EntityManagerInterface;

/** Suivi MFT : stagiaires, évaluations, règles de validation et délivrance. */
class TraineeTest extends ApiTestCase
{
    private int $section;

    protected function setUp(): void
    {
        parent::setUp();
        $this->section = $this->call('POST', '/api/sections', ['name' => 'Niveau 1'], $this->inst)[1]['id']; // inst encadre la section
    }

    private function level(string $email, ?string $level, bool $canIssue = false): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getRepository(User::class)->findOneBy(['email' => $email])->setLevel($level)->setCanIssue($canIssue);
        $em->flush();
        $em->clear();
    }

    /** Valide tous les éléments des compétences de la qualification $qual (sauf $except). */
    private function validateAll(int $enrolment, string $curriculum, string $place, array $except = []): void
    {
        foreach (Mft::curriculum($curriculum)['competencies'] as $c) {
            if (in_array($c['key'], $except, true)) continue;
            [$code, $r] = $this->call('POST', "/api/enrolments/$enrolment/evaluations", ['skills' => array_keys($c['skills']), 'status' => 'V', 'date' => '2026-10-01', 'place' => $place], $this->inst);
            $this->assertSame(201, $code, $c['key'] . ' : ' . json_encode($r));
        }
    }

    public function testReferentialAndTraineeCreation(): void
    {
        [, $mft] = $this->call('GET', '/api/mft', null, $this->inst2 ?? $this->inst);
        $this->assertSame(['N1', 'N2', 'N3', 'INIT'], array_column($mft['curricula'], 'code'));
        $this->assertCount(11, $mft['curricula'][0]['competencies']);

        // un encadrant crée un stagiaire dans SA section seulement
        $other = $this->call('POST', '/api/sections', ['name' => 'Autre'], $this->ref)[1]['id'];
        $this->assertSame(403, $this->call('POST', '/api/trainees', ['firstName' => 'A', 'lastName' => 'B', 'sectionId' => $other], $this->inst)[0]);
        [$code, $t] = $this->call('POST', '/api/trainees', ['firstName' => 'Léa', 'lastName' => 'Martin', 'sectionId' => $this->section, 'curriculum' => 'N1', 'startedAt' => '2026-09-15'], $this->inst);
        $this->assertSame(201, $code);
        $this->assertSame('Niveau 1', $t['sections'][0]['name']);
        $this->assertSame(['N1', '2026-09-15', 'MFT décembre 2025'], [$t['enrolments'][0]['curriculum'], $t['enrolments'][0]['startedAt'], $t['enrolments'][0]['version']]);
        $this->assertSame(409, $this->call('POST', "/api/trainees/{$t['id']}/enrolments", ['curriculum' => 'N1'], $this->inst)[0], 'un seul suivi par cursus');
        [, $list] = $this->call('GET', '/api/trainees', null, $this->inst);
        $this->assertSame(['validated' => 0, 'started' => 0, 'total' => 11], $list[0]['enrolments'][0]['progress']);

        // les encadrants d'autres sections consultent mais n'évaluent pas
        $inst2 = $this->login('inst2@x.fr');
        [, $f] = $this->call('GET', "/api/trainees/{$t['id']}", null, $inst2);
        $this->assertFalse($f['canEvaluate']);
        $e = $t['enrolments'][0]['id'];
        $this->assertSame(403, $this->call('POST', "/api/enrolments/$e/evaluations", ['skills' => ['N1.immerger.canard'], 'status' => 'ECA', 'place' => 'pool'], $inst2)[0]);
    }

    public function testN1Rules(): void
    {
        [, $t] = $this->call('POST', '/api/trainees', ['firstName' => 'Léa', 'lastName' => 'Martin', 'sectionId' => $this->section, 'curriculum' => 'N1'], $this->inst);
        $e = $t['enrolments'][0]['id'];

        // ECA / A : tout encadrant de la section ; historique conservé, statut = dernière évaluation
        $this->call('POST', "/api/enrolments/$e/evaluations", ['skills' => ['N1.ventiler.masque'], 'status' => 'ECA', 'date' => '2026-09-20', 'place' => 'pool', 'comment' => 'stress'], $this->inst);
        [$code, $f] = $this->call('POST', "/api/enrolments/$e/evaluations", ['skills' => ['N1.ventiler.masque'], 'status' => 'A', 'date' => '2026-09-27', 'place' => 'pool'], $this->inst);
        $this->assertSame(201, $code);
        $this->assertSame('A', $f['enrolments'][0]['skills']['N1.ventiler.masque']['status']);
        $this->assertSame('ECA', $f['enrolments'][0]['competencies'][4]['status'], 'compétence commencée');
        $this->assertCount(2, $f['enrolments'][0]['evaluations']);
        $this->assertSame(400, $this->call('POST', "/api/enrolments/$e/evaluations", ['skills' => ['N2.relai.relai'], 'status' => 'A', 'place' => 'pool'], $this->inst)[0], 'élément d\'un autre cursus');
        $this->assertSame(400, $this->call('POST', "/api/enrolments/$e/evaluations", ['skills' => ['N1.ventiler.masque'], 'status' => 'A', 'place' => 'pool', 'date' => '2099-01-01'], $this->inst)[0], 'date future');

        // V : niveau E1 minimum
        [$code, $r] = $this->call('POST', "/api/enrolments/$e/evaluations", ['skills' => ['N1.ventiler.masque'], 'status' => 'V', 'place' => 'pool'], $this->inst);
        $this->assertSame(403, $code);
        $this->assertStringContainsString('E1 minimum', $r['message']);
        $this->level('inst@x.fr', 'E1');
        $this->assertSame(201, $this->call('POST', "/api/enrolments/$e/evaluations", ['skills' => ['N1.ventiler.masque'], 'status' => 'V', 'date' => '2026-10-01', 'place' => 'pool'], $this->inst)[0], 'N1 : validation en piscine possible');
        // « Respecter le milieu » : en milieu naturel
        [$code, $r] = $this->call('POST', "/api/enrolments/$e/evaluations", ['skills' => ['N1.milieu.aisance'], 'status' => 'V', 'place' => 'pool'], $this->inst);
        $this->assertSame(403, $code);
        $this->assertStringContainsString('milieu naturel', $r['message']);

        // délai de 15 mois à partir de la première compétence validée
        $this->validateAll($e, 'N1', 'pool', ['N1.milieu']);
        [, $f] = $this->call('GET', "/api/trainees/{$t['id']}", null, $this->inst);
        $this->assertSame(['2026-10-01', '2028-01-01'], [$f['enrolments'][0]['firstValidatedAt'], $f['enrolments'][0]['deadline']]);
        $this->assertSame(10, $f['enrolments'][0]['progress']['validated']);
        $this->assertStringContainsString('E3 minimum ou au président', $f['enrolments'][0]['qualifications'][0]['cannotIssue']);

        // délivrance : E3 ou président, toutes compétences validées
        $this->call('POST', "/api/enrolments/$e/evaluations", ['skills' => ['N1.milieu.aisance'], 'status' => 'V', 'date' => '2026-10-10', 'place' => 'natural', 'site' => 'Carrière', 'depth' => 6], $this->inst);
        $this->assertSame(403, $this->call('POST', "/api/enrolments/$e/issue", ['qualification' => 'N1'], $this->inst)[0]);
        $this->level('ref@x.fr', null, true); // président
        [$code, $f] = $this->call('POST', "/api/enrolments/$e/issue", ['qualification' => 'N1', 'date' => '2026-10-12'], $this->ref);
        $this->assertSame(200, $code);
        $this->assertSame('done', $f['enrolments'][0]['status']);
        $this->assertSame('2026-10-12', $f['enrolments'][0]['issued']['N1']['date']);
        $this->assertSame(400, $this->call('POST', "/api/enrolments/$e/evaluations", ['skills' => ['N1.immerger.canard'], 'status' => 'A', 'place' => 'pool'], $this->inst)[0], 'suivi clôturé');
    }

    public function testN2NaturalOnlyFinalCompetenciesAndQualifications(): void
    {
        [, $t] = $this->call('POST', '/api/trainees', ['firstName' => 'Paul', 'lastName' => 'Durand', 'sectionId' => $this->section, 'curriculum' => 'N2'], $this->inst);
        $e = $t['enrolments'][0]['id'];
        $this->level('inst@x.fr', 'E1');
        $this->assertStringContainsString('E2 minimum', $this->call('POST', "/api/enrolments/$e/evaluations", ['skills' => ['N2.ventiler.masque'], 'status' => 'V', 'place' => 'natural'], $this->inst)[1]['message']);
        $this->level('inst@x.fr', 'E2');
        // milieu naturel uniquement (piscine et fosse : travail seulement), sauf connaissances théoriques
        $this->assertSame(403, $this->call('POST', "/api/enrolments/$e/evaluations", ['skills' => ['N2.ventiler.masque'], 'status' => 'V', 'place' => 'pit'], $this->inst)[0]);
        $this->assertSame(201, $this->call('POST', "/api/enrolments/$e/evaluations", ['skills' => ['N2.ventiler.masque'], 'status' => 'A', 'place' => 'pit'], $this->inst)[0]);
        $this->assertSame(201, $this->call('POST', "/api/enrolments/$e/evaluations", ['skills' => ['N2.theoriePE40.accidents'], 'status' => 'V', 'place' => 'pool'], $this->inst)[0]);

        // « Intervenir en relai » (PE40) : en fin de formation, après les autres compétences PE40
        [$code, $r] = $this->call('POST', "/api/enrolments/$e/evaluations", ['skills' => ['N2.relai.relai'], 'status' => 'V', 'place' => 'natural'], $this->inst);
        $this->assertSame(403, $code);
        $this->assertStringContainsString('fin de formation', $r['message']);
        foreach (['N2.equiper', 'N2.immerger', 'N2.milieu', 'N2.ventiler', 'N2.communiquer', 'N2.surface', 'N2.theoriePE40'] as $key) {
            $c = array_values(array_filter(Mft::curriculum('N2')['competencies'], fn ($x) => $x['key'] === $key))[0];
            $this->call('POST', "/api/enrolments/$e/evaluations", ['skills' => array_keys($c['skills']), 'status' => 'V', 'place' => 'natural'], $this->inst);
        }
        [$c2, $r2] = $this->call("POST", "/api/enrolments/$e/evaluations", ["skills" => ["N2.relai.relai"], "status" => "V", "place" => "natural"], $this->inst); $this->assertSame(201, $c2, json_encode($r2));

        // PE40 délivrable seul (E3), PA20 et N2 pas encore
        $this->level('ref@x.fr', 'E3');
        [, $f] = $this->call('GET', "/api/trainees/{$t['id']}", null, $this->ref);
        $q = array_column($f['enrolments'][0]['qualifications'], 'cannotIssue', 'code');
        $this->assertNull($q['PE40']);
        $this->assertStringContainsString('Planifier', $q['PA20']);
        $this->assertSame(200, $this->call('POST', "/api/enrolments/$e/issue", ['qualification' => 'PE40'], $this->ref)[0]);
        $this->assertSame(403, $this->call('POST', "/api/enrolments/$e/issue", ['qualification' => 'N2'], $this->ref)[0]);
        [, $f] = $this->call('GET', "/api/trainees/{$t['id']}", null, $this->ref);
        $this->assertSame('active', $f['enrolments'][0]['status']);
        $this->assertSame('Déjà délivrée', array_column($f['enrolments'][0]['qualifications'], 'cannotIssue', 'code')['PE40']);
    }

    public function testCorrectionsAndPrerequisites(): void
    {
        [, $t] = $this->call('POST', '/api/trainees', ['firstName' => 'Léa', 'lastName' => 'Martin', 'sectionId' => $this->section, 'curriculum' => 'N3'], $this->inst);
        $e = $t['enrolments'][0]['id'];
        [, $f] = $this->call('PUT', "/api/enrolments/$e", ['prerequisites' => ['RIFA Plongée (au moment de la certification)' => true]], $this->inst);
        $this->assertTrue($f['enrolments'][0]['prerequisites'][3]['done']);
        $this->assertSame(400, $this->call('PUT', "/api/enrolments/$e", ['prerequisites' => ['Inventé' => true]], $this->inst)[0]);

        [, $f] = $this->call('POST', "/api/enrolments/$e/evaluations", ['skills' => ['N3.organiser.site'], 'status' => 'ECA', 'place' => 'natural'], $this->inst);
        $ev = $f['enrolments'][0]['evaluations'][0]['id'];
        $this->assertSame(403, $this->call('DELETE', "/api/evaluations/$ev", null, $this->login('inst2@x.fr'))[0], 'pas l\'auteur');
        $this->assertSame(409, $this->call('DELETE', "/api/enrolments/$e", null, $this->ref)[0], 'suivi avec évaluations');
        $this->assertSame(200, $this->call('DELETE', "/api/evaluations/$ev", null, $this->inst)[0]);
        $this->assertSame(403, $this->call('DELETE', "/api/enrolments/$e", null, $this->inst)[0]);
        [$code, $f] = $this->call('DELETE', "/api/enrolments/$e", null, $this->ref);
        $this->assertSame([200, []], [$code, $f['enrolments']]);

        // archivage
        $this->call('PUT', "/api/trainees/{$t['id']}", ['archived' => true, 'licence' => 'A-12-345678'], $this->inst);
        $this->assertCount(0, $this->call('GET', '/api/trainees', null, $this->inst)[1]);
        $this->assertSame('A-12-345678', $this->call('GET', '/api/trainees?archived=1', null, $this->inst)[1][0]['licence']);
    }

    public function testInstructorLevelOnAccounts(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getRepository(User::class)->findOneBy(['email' => 'ref@x.fr'])->setRoles(['ROLE_ADMIN']);
        $em->flush();
        $admin = $this->login('ref@x.fr');
        $id = $em->getRepository(User::class)->findOneBy(['email' => 'inst@x.fr'])->getId();
        [, $u] = $this->call('PUT', "/api/users/$id", ['level' => 'E3', 'tsi' => true], $admin);
        $this->assertSame(['E3', true, false], [$u['level'], $u['tsi'], $u['canIssue']]);
        $this->assertSame(400, $this->call('PUT', "/api/users/$id", ['level' => 'E5'], $admin)[0]);
        [, $u] = $this->call('POST', '/api/users', ['email' => 'new@x.fr', 'name' => 'New', 'password' => 'password1', 'level' => 'E2'], $admin);
        $this->assertSame('E2', $u['level']);
        $this->assertSame('E3', $this->call('GET', '/api/me', null, $this->inst)[1]['level']);
    }

    public function testInitiatorTraining(): void
    {
        [, $t] = $this->call('POST', '/api/trainees', ['firstName' => 'Marc', 'lastName' => 'Petit', 'sectionId' => $this->section, 'curriculum' => 'INIT'], $this->inst);
        $e = $t['enrolments'][0]['id'];
        $this->assertSame(['validated' => 0, 'started' => 0, 'total' => 3], $t['enrolments'][0]['progress']);
        $this->assertCount(3, $t['enrolments'][0]['modules']);

        // dossier : stage initial -> livret valable 3 ans
        [, $f] = $this->call('PUT', "/api/enrolments/$e", ['dossier' => ['stageInitial' => ['date' => '2026-09-12', 'ctr' => 'CTR Est']]], $this->inst);
        $this->assertSame('2029-09-12', $f['enrolments'][0]['deadline']);
        $this->assertTrue($f['enrolments'][0]['prerequisites'][1]['done'], 'stage initial coché');

        $all = fn (string $v, array $keys) => array_fill_keys($keys, $v);
        $m1 = ['objectifs', 'cursus', 'justifier', 'strategie', 'accueillir', 'animer', 'mettre', 'evaluer'];
        $session = fn (array $items, string $level, string $theme) => $this->call('POST', "/api/enrolments/$e/sessions", ['module' => 'm1', 'date' => '2026-10-01', 'level' => $level, 'theme' => $theme, 'items' => $items], $this->inst);
        $this->assertSame(400, $session(['inconnu' => 'A'], 'N1', 'x')[0]);
        $this->assertSame(400, $session(['animer' => 'A'], 'N9', 'x')[0], 'niveau inconnu');
        // séance incomplète (un item NT), puis 5 complètes dont 2 validées, sur le même thème et niveau
        [$code, $f] = $session(['animer' => 'ECA'], 'N1', 'Vidage de masque');
        $this->assertSame(201, $code);
        $this->assertFalse($f['enrolments'][0]['modules'][0]['sessions'][0]['complete']);
        for ($i = 0; $i < 3; $i++) $session($all('ECA', $m1), 'N1', 'Vidage de masque');
        for ($i = 0; $i < 2; $i++) $session($all('A', $m1), 'N1', 'Vidage de masque');
        [, $f] = $this->call('GET', "/api/trainees/{$t['id']}", null, $this->inst);
        $mod = $f['enrolments'][0]['modules'][0];
        $this->assertSame(['sessions' => 6, 'complete' => 5, 'validated' => 2, 'themes' => 1, 'levels' => 1], $mod['counts']);
        $this->assertStringContainsString('tuteur', $mod['cannotValidate'], 'inst n\'est pas tuteur');

        // tuteur : E3 + TSI (ou E4) ; thèmes et niveaux différents exigés
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getRepository(User::class)->findOneBy(['email' => 'inst@x.fr'])->setLevel('E3')->setTsi(true);
        $em->flush();
        [$code, $r] = $this->call('POST', "/api/enrolments/$e/modules/m1", ['action' => 'validate'], $this->inst);
        $this->assertSame(403, $code);
        $this->assertStringContainsString('thèmes et des niveaux', $r['message']);
        $session($all('A', $m1), 'PA20', 'Lâcher-reprise d\'embout');
        [$code, $f] = $this->call('POST', "/api/enrolments/$e/modules/m1", ['action' => 'validate', 'date' => '2026-10-05'], $this->inst);
        $this->assertSame(200, $code);
        $this->assertSame('2026-10-05', $f['enrolments'][0]['modules'][0]['validated']['date']);
        $this->assertSame(400, $session($all('A', $m1), 'N1', 'x')[0], 'module validé');

        // module 2 : exigences pratiques (mannequin, intervention) ; module 3 dispense refusée
        $m2 = ['accueillir', 'deroulement', 'securiser', 'reagir'];
        foreach ([['N1', 'Fosse'], ['PA20', 'Piscine'], ['N1', 'Sortie'], ['PE40', 'Bassin'], ['N2', 'Piscine']] as $i => [$lvl, $th]) {
            $this->call('POST', "/api/enrolments/$e/sessions", ['module' => 'm2', 'level' => $lvl, 'theme' => $th, 'items' => $all($i < 2 ? 'A' : 'ECA', $m2)], $this->inst);
        }
        $this->assertStringContainsString('mannequin', $this->call('POST', "/api/enrolments/$e/modules/m2", ['action' => 'validate'], $this->inst)[1]['message']);
        $this->call('POST', "/api/enrolments/$e/modules/m2", ['action' => 'requirement', 'key' => 'mannequin'], $this->inst);
        $this->call('POST', "/api/enrolments/$e/modules/m2", ['action' => 'requirement', 'key' => 'intervention'], $this->inst);
        $this->assertSame(200, $this->call('POST', "/api/enrolments/$e/modules/m2", ['action' => 'validate'], $this->inst)[0]);
        $this->assertSame(400, $this->call('POST', "/api/enrolments/$e/modules/m3", ['action' => 'exempt'], $this->inst)[0]);
        $this->call('POST', "/api/enrolments/$e/sessions", ['module' => 'm3', 'level' => 'N1', 'theme' => 'Cursus N1', 'items' => $all('A', ['nature', 'planifier', 'logistique', 'humains'])], $this->inst);
        $this->assertSame(200, $this->call('POST', "/api/enrolments/$e/modules/m3", ['action' => 'validate'], $this->inst)[0]);

        // examen : référent, après stage initial et RIFA Plongée cochés
        [, $f] = $this->call('GET', "/api/trainees/{$t['id']}", null, $this->ref);
        $this->assertStringContainsString('RIFA', $f['enrolments'][0]['qualifications'][0]['cannotIssue']);
        $this->assertSame(3, $f['enrolments'][0]['progress']['validated']);
        $this->call('PUT', "/api/enrolments/$e", ['prerequisites' => ['Stage initial réalisé (14 h, encadré par un E4)' => true, 'RIFA Plongée' => true]], $this->ref);
        $this->assertSame(403, $this->call('POST', "/api/enrolments/$e/issue", ['qualification' => 'INIT'], $this->inst)[0], 'référents seulement');
        [$code, $f] = $this->call('POST', "/api/enrolments/$e/issue", ['qualification' => 'INIT', 'date' => '2026-11-20'], $this->ref);
        $this->assertSame([200, 'done'], [$code, $f['enrolments'][0]['status']]);
    }
}
