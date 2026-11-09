<?php
namespace App\Tests;

/**
 * Planning d'occupation : créneaux, lignes ouvertes, plan habituel, exceptions (règles et dates),
 * modification ponctuelle d'une case, bouteilles par date et section.
 * Créneau lundi 20h–22h (Paris), fosse L1..L4 dont L1..L3 ouvertes. Plan : L1, L2 → N1 ; L3 → N2.
 * 2027 : 1er lundi de février = 1er ; 8 = 2e lundi ; 22 = dernier lundi de février.
 */
class PlanningTest extends ApiTestCase
{
    private array $lanes;
    private array $sec;
    private int $slot;

    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->call('POST', '/api/pools', ['name' => 'Fosse', 'lanes' => [['name' => 'L1'], ['name' => 'L2'], ['name' => 'L3'], ['name' => 'L4']]], $this->ref)[1];
        $this->lanes = array_column($pool['lanes'], 'id');
        foreach (['N1', 'N2', 'Apnée'] as $n) $this->sec[$n] = $this->call('POST', '/api/sections', ['name' => $n], $this->inst)[1]['id'];
        [$code, $slot] = $this->call('POST', '/api/slots', ['label' => 'Entraînement', 'weekdays' => [1], 'startTime' => '20:00', 'endTime' => '22:00',
            'laneIds' => array_slice($this->lanes, 0, 3)], $this->ref);
        $this->assertSame(201, $code);
        $this->slot = $slot['id'];
        $this->call('PUT', "/api/slots/{$this->slot}/allocations", ['allocations' => [
            ['laneId' => $this->lanes[0], 'sectionId' => $this->sec['N1']], ['laneId' => $this->lanes[1], 'sectionId' => $this->sec['N1']],
            ['laneId' => $this->lanes[2], 'sectionId' => $this->sec['N2']]]], $this->ref);
    }

    private function override(array $body): array
    {
        return $this->call('POST', "/api/slots/{$this->slot}/overrides", $body, $this->ref);
    }

    public function testSlotsAndHabitualPlan(): void
    {
        $slot = ['weekdays' => [1, 3], 'startTime' => '20:00', 'endTime' => '22:00'];
        $this->assertSame(403, $this->call('POST', '/api/slots', $slot, $this->inst)[0], 'seuls les référents planifient');
        foreach ([['weekdays' => []], ['weekdays' => [8]], ['endTime' => '19:00'], ['startTime' => '25:00']] as $bad) {
            $this->assertSame(400, $this->call('POST', '/api/slots', $bad + $slot, $this->ref)[0], json_encode($bad));
        }
        $this->assertSame(403, $this->call('PUT', "/api/slots/{$this->slot}/allocations", ['allocations' => []], $this->inst)[0]);
        $this->assertSame(400, $this->call('PUT', "/api/slots/{$this->slot}/allocations", ['allocations' => [['laneId' => $this->lanes[3], 'sectionId' => $this->sec['N1']]]], $this->ref)[0], 'L4 fermée');

        // heure locale du club, été comme hiver ; seulement les lignes ouvertes
        $occ = $this->planningOf('2027-02-08');
        $this->assertSame(['2027-02-08T20:00:00+01:00', 'Entraînement'], [$occ[0]['start'], $occ[0]['label']]);
        $this->assertSame(['L1', 'L2', 'L3'], array_column($occ[0]['lanes'], 'lane'));
        $this->assertSame(['N1', 'N1', 'N2'], $this->lanesOf('2027-02-08'));
        $this->assertSame('2027-06-07T20:00:00+02:00', $this->planningOf('2027-06-07')[0]['start']);
        $this->assertSame(['aucun'], $this->lanesOf('2027-02-09'), 'pas de créneau le mardi');

        // « mine » : sections encadrées par l'utilisateur
        $this->assertTrue($occ[0]['lanes'][0]['sections'][0]['mine']);
        $inst2 = $this->login('inst2@x.fr');
        $this->assertFalse($this->planningOf('2027-02-08', $inst2)[0]['lanes'][0]['sections'][0]['mine']);
    }

    public function testMonthlyRuleAndDateExceptions(): void
    {
        [$code, $o] = $this->override(['label' => 'Apnée en fosse', 'kind' => 'monthly', 'weekday' => 1, 'nth' => 1,
            'allocations' => array_map(fn ($l) => ['laneId' => $l, 'sectionId' => $this->sec['Apnée']], array_slice($this->lanes, 0, 3))]);
        $this->assertSame([201, 'le 1er lundi de chaque mois'], [$code, $o['rule']]);
        $this->assertSame(['Apnée', 'Apnée', 'Apnée'], $this->lanesOf('2027-02-01'));
        $this->assertSame(['N1', 'N1', 'N2'], $this->lanesOf('2027-02-08'));
        $occ = $this->planningOf('2027-02-01')[0];
        $this->assertTrue($occ['lanes'][0]['exception']);
        $this->assertSame('Apnée en fosse', $occ['exceptions'][0]['label']);

        // dernier lundi : L3 libérée ; date précise : piscine fermée ; une date l'emporte sur la règle
        $this->override(['kind' => 'monthly', 'weekday' => 1, 'nth' => -1, 'allocations' => [['laneId' => $this->lanes[2], 'sectionId' => null]]]);
        $this->assertSame(['N1', 'N1', 'libre'], $this->lanesOf('2027-02-22'));
        $this->override(['label' => 'Piscine fermée', 'kind' => 'date', 'date' => '2027-02-15', 'cancelled' => true]);
        $this->assertSame(['annulé'], $this->lanesOf('2027-02-15'));
        $this->override(['kind' => 'date', 'date' => '2027-03-01', 'allocations' => [['laneId' => $this->lanes[0], 'sectionId' => $this->sec['N1']]]]);
        $this->assertSame(['N1', 'Apnée', 'Apnée'], $this->lanesOf('2027-03-01'));

        // modifier puis supprimer la règle
        [$code, $o] = $this->call('PUT', "/api/overrides/{$o['id']}", ['nth' => 2], $this->ref);
        $this->assertSame([200, 'le 2e lundi de chaque mois'], [$code, $o['rule']]);
        $this->assertSame(['Apnée', 'Apnée', 'Apnée'], $this->lanesOf('2027-02-08'));
        $this->assertSame(204, $this->call('DELETE', "/api/overrides/{$o['id']}", null, $this->ref)[0]);
        $this->assertSame(['N1', 'N1', 'N2'], $this->lanesOf('2027-02-08'));

        foreach ([['kind' => 'weekly', 'cancelled' => true], ['kind' => 'date', 'date' => '2027-02-02', 'cancelled' => true],
                  ['kind' => 'monthly', 'weekday' => 3, 'nth' => 1, 'cancelled' => true], ['kind' => 'monthly', 'weekday' => 1, 'nth' => 5, 'cancelled' => true],
                  ['kind' => 'date', 'date' => '2027-02-01']] as $bad) {
            $this->assertSame(400, $this->override($bad)[0], json_encode($bad));
        }
        $this->assertSame(403, $this->call('POST', "/api/slots/{$this->slot}/overrides", ['kind' => 'date', 'date' => '2027-02-01', 'cancelled' => true], $this->inst)[0]);
    }

    public function testPunctualEditOfACell(): void
    {
        $url = "/api/slots/{$this->slot}/dates/2027-02-08";
        $this->assertSame(403, $this->call('PUT', $url, ['allocations' => [['laneId' => $this->lanes[2], 'sectionId' => $this->sec['Apnée']]]], $this->inst)[0]);

        // L3 → Apnée ce lundi-là seulement
        [$code, $o] = $this->call('PUT', $url, ['allocations' => [['laneId' => $this->lanes[2], 'sectionId' => $this->sec['Apnée']]]], $this->ref);
        $this->assertSame([200, 'Modification ponctuelle', 'date'], [$code, $o['label'], $o['kind']]);
        $this->assertSame(['N1', 'N1', 'Apnée'], $this->lanesOf('2027-02-08'));
        $this->assertSame(['N1', 'N1', 'N2'], $this->lanesOf('2027-02-15'));
        // une 2e case le même jour s'ajoute à la même modification
        $this->call('PUT', $url, ['allocations' => [['laneId' => $this->lanes[0], 'sectionId' => null]]], $this->ref);
        $this->assertSame(['libre', 'N1', 'Apnée'], $this->lanesOf('2027-02-08'));
        [, $slots] = $this->call('GET', '/api/slots', null, $this->ref);
        $this->assertCount(1, $slots[0]['overrides']);
        // remettre comme d'habitude : la modification disparaît quand elle est vide
        $this->call('PUT', $url, ['allocations' => [['laneId' => $this->lanes[0], 'sectionId' => $this->sec['N1']], ['laneId' => $this->lanes[2], 'sectionId' => 'same']]], $this->ref);
        $this->assertSame(['N1', 'N1', 'N2'], $this->lanesOf('2027-02-08'));
        [, $slots] = $this->call('GET', '/api/slots', null, $this->ref);
        $this->assertCount(0, $slots[0]['overrides']);
        // annuler le créneau ce jour-là, puis le rétablir
        $this->call('PUT', $url, ['cancelled' => true], $this->ref);
        $this->assertSame(['annulé'], $this->lanesOf('2027-02-08'));
        $this->call('PUT', $url, ['cancelled' => false], $this->ref);
        $this->assertSame(['N1', 'N1', 'N2'], $this->lanesOf('2027-02-08'));
        $this->assertSame(400, $this->call('PUT', "/api/slots/{$this->slot}/dates/2027-02-09", ['cancelled' => true], $this->ref)[0], 'mardi : pas de créneau');
    }

    public function testTanksByDateAndSection(): void
    {
        foreach ([['Léa', 'Martin'], ['Hugo', 'Bernard']] as [$f, $l]) {
            $d = $this->call('POST', '/api/divers', ['firstName' => $f, 'lastName' => $l], $this->inst)[1];
            $this->call('POST', "/api/sections/{$this->sec['N1']}/divers", ['diverId' => $d['id']], $this->inst);
            $divers[] = $d['id'];
        }
        $q = "/api/presences?slotId={$this->slot}&date=2027-02-08&sectionId={$this->sec['N1']}";
        [$code, $list] = $this->call('GET', $q, null, $this->inst);
        $this->assertSame([200, 0], [$code, $list['tankCount']]);
        $this->assertSame(['Bernard', 'Martin'], array_column(array_column($list['divers'], 'diver'), 'lastName'));

        $body = ['slotId' => $this->slot, 'date' => '2027-02-08', 'sectionId' => $this->sec['N1'], 'diverId' => $divers[0], 'usesTank' => true];
        [$code, $list] = $this->call('PUT', '/api/presences', $body, $this->inst);   // inst encadre N1
        $this->assertSame([200, 1], [$code, $list['tankCount']]);
        $this->assertSame(403, $this->call('PUT', '/api/presences', $body, $this->login('inst2@x.fr'))[0], 'pas encadrant de N1');
        $this->assertSame(200, $this->call('PUT', '/api/presences', ['diverId' => $divers[1]] + $body, $this->ref)[0], 'un référent peut');

        // visible dans le planning, uniquement à cette date
        $this->assertSame([(string) $this->sec['N1'] => 2], $this->planningOf('2027-02-08')[0]['tanks']);
        $this->assertSame([], $this->planningOf('2027-02-15')[0]['tanks']);
        $this->call('PUT', '/api/presences', ['usesTank' => false] + $body, $this->inst);
        $this->assertSame([(string) $this->sec['N1'] => 1], $this->planningOf('2027-02-08')[0]['tanks']);
    }

    public function testTwoSectionsOnTheSameLane(): void
    {
        // plan habituel : L1 partagée entre N1 et l'Apnée
        [$code] = $this->call('PUT', "/api/slots/{$this->slot}/allocations", ['allocations' => [
            ['laneId' => $this->lanes[0], 'sectionIds' => [$this->sec['N1'], $this->sec['Apnée']]],
            ['laneId' => $this->lanes[1], 'sectionIds' => [$this->sec['N1']]],
            ['laneId' => $this->lanes[2], 'sectionIds' => []]]], $this->ref);
        $this->assertSame(200, $code);
        $this->assertSame(['N1+Apnée', 'N1', 'libre'], $this->lanesOf('2027-02-08'));
        [, $slots] = $this->call('GET', '/api/slots', null, $this->inst);
        $this->assertCount(3, $slots[0]['allocations'], 'une rangée par section');
        $this->assertSame(400, $this->call('PUT', "/api/slots/{$this->slot}/allocations", ['allocations' => [
            ['laneId' => $this->lanes[0], 'sectionIds' => array_values($this->sec) + [3 => 999]]]], $this->ref)[0], 'au plus 3 sections');

        // « mine » si l'une des sections de la ligne est à moi ; inst2 encadre l'Apnée
        $inst2 = $this->login('inst2@x.fr');
        [, $users] = $this->call('GET', '/api/instructors', null, $this->ref);
        $id2 = array_values(array_filter($users, fn ($u) => $u['name'] === 'inst2@x.fr'))[0]['id'];
        $this->call('POST', "/api/sections/{$this->sec['Apnée']}/instructors", ['userId' => $id2], $this->ref);
        $l1 = $this->planningOf('2027-02-08', $inst2)[0]['lanes'][0];
        $this->assertSame([false, true], array_column($l1['sections'], 'mine'));

        // règle mensuelle : le 1er lundi, L3 partagée N2 + Apnée
        $this->override(['kind' => 'monthly', 'weekday' => 1, 'nth' => 1, 'allocations' => [['laneId' => $this->lanes[2], 'sectionIds' => [$this->sec['N2'], $this->sec['Apnée']]]]]);
        $this->assertSame(['N1+Apnée', 'N1', 'N2+Apnée'], $this->lanesOf('2027-02-01'));

        // modification ponctuelle : L2 partagée N1 + N2 ce lundi-là
        $url = "/api/slots/{$this->slot}/dates/2027-02-15";
        $this->call('PUT', $url, ['allocations' => [['laneId' => $this->lanes[1], 'sectionIds' => [$this->sec['N2'], $this->sec['N1']]]]], $this->ref);
        $this->assertSame(['N1+Apnée', 'N1+N2', 'libre'], $this->lanesOf('2027-02-15'));
        // L1 remise « Apnée + N1 » (même partage, autre ordre) : identique au plan, rien n'est enregistré pour elle
        $this->call('PUT', $url, ['allocations' => [['laneId' => $this->lanes[0], 'sectionIds' => [$this->sec['Apnée'], $this->sec['N1']]]]], $this->ref);
        [, $slots] = $this->call('GET', '/api/slots', null, $this->ref);
        $punctual = array_values(array_filter($slots[0]['overrides'], fn ($o) => $o['kind'] === 'date'))[0];
        $this->assertSame([$this->lanes[1]], array_values(array_unique(array_column($punctual['allocations'], 'laneId'))));
        // retirer une des deux sections de L1 ce jour-là
        $this->call('PUT', $url, ['allocations' => [['laneId' => $this->lanes[0], 'sectionIds' => [$this->sec['Apnée']]]]], $this->ref);
        $this->assertSame(['Apnée', 'N1+N2', 'libre'], $this->lanesOf('2027-02-15'));
        $this->assertSame(['N1+Apnée', 'N1', 'libre'], $this->lanesOf('2027-02-22'), 'les autres lundis gardent le plan habituel');
    }

    public function testRenameSection(): void
    {
        $id = $this->sec['N1'];
        [$code, $s] = $this->call('PUT', "/api/sections/$id", ['name' => '  Niveau 1 – lundi ', 'targetLevel' => 'N1', 'season' => '2026-2027'], $this->inst);
        $this->assertSame([200, 'Niveau 1 – lundi', 'N1', '2026-2027'], [$code, $s['name'], $s['targetLevel'], $s['season']]);
        $this->assertSame(400, $this->call('PUT', "/api/sections/$id", ['name' => '   '], $this->inst)[0], 'nom obligatoire');
        $this->assertSame(400, $this->call('PUT', "/api/sections/$id", ['name' => str_repeat('x', 121)], $this->inst)[0]);
        [, $s] = $this->call('PUT', "/api/sections/$id", ['season' => ''], $this->inst);
        $this->assertNull($s['season']);
        // le nouveau nom apparaît dans le planning
        $this->assertSame(['Niveau 1 – lundi', 'Niveau 1 – lundi', 'N2'], $this->lanesOf('2027-02-08'));
    }
}
