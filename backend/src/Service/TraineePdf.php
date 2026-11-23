<?php
namespace App\Service;

use App\Training\Mft;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fiche de suivi et d'évaluation d'un stagiaire en PDF (A4 portrait), sur le modèle des fiches proposées
 * par les manuels FFESSM : compétences et éléments, dernière évaluation, validation (date et moniteur).
 * Initiateur : modules, séances notées A / ECA / NT, exigences pratiques et dossier.
 * Entrée : TrainingService::publicFiche().
 */
final class TraineePdf
{
    private const STATUS = ['NT' => 'NT', 'ECA' => 'ECA', 'A' => 'A', 'V' => 'Validé'];
    private const PLACES = ['pool' => 'Piscine', 'pit' => 'Fosse', 'natural' => 'Milieu naturel'];

    public function response(array $fiche): Response
    {
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $fiche['lastName'] . '-' . $fiche['firstName'])));
        return new Response($this->render($fiche), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="livret-%s.pdf"', trim($slug, '-')),
        ]);
    }

    public function render(array $fiche): string
    {
        $dompdf = new Dompdf((new Options())->set('defaultFont', 'DejaVu Sans')->set('isRemoteEnabled', false));
        $dompdf->loadHtml($this->html($fiche), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return $dompdf->output();
    }

    public function html(array $fiche): string
    {
        $e = fn (?string $s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $date = fn (?string $d) => $d ? (new \DateTimeImmutable($d))->format('d/m/Y') : '';
        $who = $e($fiche['lastName'] . ' ' . $fiche['firstName']);
        $meta = array_filter([
            $fiche['licence'] ? 'Licence ' . $e($fiche['licence']) : null,
            $fiche['sections'] ? 'Section : ' . $e(implode(', ', array_column($fiche['sections'], 'name'))) : null,
        ]);

        $pages = [];
        foreach ($fiche['enrolments'] as $en) {
            $cur = Mft::curriculum($en['curriculum']);
            $h = sprintf('<h1>Fiche de suivi et d\'évaluation — %s</h1><p class="sub">%s · %s</p>', $e($cur['name']), $e($cur['subtitle']), $e($en['version']));
            $h .= sprintf('<table class="id"><tr><td><b>NOM Prénom :</b> %s</td><td>%s</td></tr><tr><td><b>Début de formation :</b> %s%s</td><td><b>Statut :</b> %s</td></tr></table>',
                $who, implode(' · ', $meta), $date($en['startedAt']),
                $en['deadline'] ? sprintf(' · <b>%s :</b> %s', ($cur['type'] ?? '') === 'teaching' ? 'livret valable jusqu\'au' : 'échéance (15 mois)', $date($en['deadline'])) : '',
                ['active' => 'en cours', 'done' => 'délivré', 'abandoned' => 'abandonné'][$en['status']] . sprintf(' — %d/%d %s validé(e)s', $en['progress']['validated'], $en['progress']['total'], ($cur['type'] ?? '') === 'teaching' ? 'modules' : 'compétences'));
            $h .= ($cur['type'] ?? 'diver') === 'teaching' ? $this->teaching($en, $cur, $e, $date) : $this->diver($en, $cur, $e, $date);

            // prérequis / dossier
            $h .= '<h2>' . (($cur['type'] ?? '') === 'teaching' ? 'Dossier' : 'Prérequis') . '</h2><p class="checks">';
            if (!empty($en['dossier']['stageInitial'])) $h .= sprintf('Stage initial le %s%s<br>', $date($en['dossier']['stageInitial']['date']), $en['dossier']['stageInitial']['ctr'] ? ' (' . $e($en['dossier']['stageInitial']['ctr']) . ')' : '');
            foreach ($en['prerequisites'] as $p) {
                $sig = $p['done'] && $p['date'] ? ' <span class="muted small">— ' . $date($p['date']) . ($p['by'] ? ', ' . $e($p['by']) : '') . ($p['note'] ? ' · ' . $e($p['note']) : '') . '</span>' : '';
                $h .= sprintf('<span class="box">%s</span> %s%s<br>', $p['done'] ? '&#10003;' : '&nbsp;', $e($p['label']), $sig);
            }
            $h .= '</p>';

            // validation / délivrance
            $h .= '<h2>' . (($cur['type'] ?? '') === 'teaching' ? 'Examen' : 'Validation') . '</h2><table class="grid"><tr><th>Qualification</th><th style="width:22%">Validé le</th><th style="width:34%">Nom du moniteur</th></tr>';
            foreach ($en['qualifications'] as $q) {
                $h .= sprintf('<tr><td>%s</td><td>%s</td><td>%s</td></tr>', $e($q['label']), $q['issued'] ? $date($q['issued']['date']) : '', $q['issued'] ? $e($q['issued']['by']) : '');
            }
            $h .= '</table>';
            $pages[] = $h;
        }
        if (!$pages) $pages[] = sprintf('<h1>Fiche de suivi — %s</h1><p>Aucun cursus.</p>', $who);

        $foot = sprintf('Édité le %s · Référentiel : intitulés des manuels de formation FFESSM — se référer aux manuels officiels pour les contenus et modalités.', (new \DateTimeImmutable())->format('d/m/Y'));
        return '<!doctype html><html><head><meta charset="utf-8"><style>
            @page { margin: 14mm 12mm 16mm }
            body { font-family: "DejaVu Sans", sans-serif; font-size: 8.6pt; color: #1b2733 }
            h1 { font-size: 14pt; color: #0b4f7c; margin: 0 }
            h2 { font-size: 10pt; color: #0b4f7c; margin: 10px 0 4px; border-bottom: 1px solid #0b4f7c; padding-bottom: 2px }
            .sub { margin: 2px 0 6px; color: #5b6b7b }
            table { width: 100%; border-collapse: collapse }
            .id td { padding: 3px 4px; border: 1px solid #c9d3dc; background: #f3f6f9 }
            .grid th { background: #0b4f7c; color: #fff; text-align: left; padding: 3px 4px; font-size: 8pt }
            .grid td { border: 1px solid #c9d3dc; padding: 2.5px 4px; vertical-align: top }
            .comp td { background: #e4edf5; font-weight: bold }
            .group td { background: #fff; color: #0b4f7c; font-weight: bold; border: 0; padding-top: 6px }
            .st { font-weight: bold; text-align: center; width: 9% }
            td.st.V { background: #1d8f4e; color: #fff } td.st.A { background: #dceaf7; color: #0b6aa8 } td.st.ECA { background: #fff1cf; color: #8a5a00 } td.st.NT { color: #8494a3 }
            .muted { color: #6b7a89 } .small { font-size: 7.6pt }
            .checks { line-height: 1.6 } .box { display: inline-block; width: 10px; height: 10px; border: 1px solid #555; text-align: center; line-height: 10px; font-size: 8pt }
            .page { page-break-after: always } .page:last-child { page-break-after: auto }
            .foot { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 6.8pt; color: #8494a3; text-align: center }
            .items td.st { width: auto; font-size: 7pt; padding: 2px 1px }
        </style></head><body><div class="foot">' . $e($foot) . '</div><div class="page">' . implode('</div><div class="page">', $pages) . '</div></body></html>';
    }

    private function diver(array $en, array $cur, \Closure $e, \Closure $date): string
    {
        $comp = array_column($en['competencies'], 'status', 'key');
        $h = '<h2>Compétences</h2><table class="grid"><tr><th style="width:52%">Compétence / élément</th><th class="st">Statut</th><th>Dernière évaluation</th></tr>';
        $group = null;
        foreach ($cur['competencies'] as $c) {
            if ($c['group'] && $c['group'] !== $group) $h .= sprintf('<tr class="group"><td colspan="3">%s</td></tr>', $e($group = $c['group']));
            $tags = array_filter([$c['final'] ? 'fin de formation' : null, $c['theory'] ? 'connaissances' : null]);
            $h .= sprintf('<tr class="comp"><td>%s%s</td><td class="st %s">%s</td><td></td></tr>', $e($c['title']), $tags ? ' <span class="muted small">(' . implode(', ', $tags) . ')</span>' : '', $comp[$c['key']], self::STATUS[$comp[$c['key']]]);
            foreach ($c['skills'] as $k => $label) {
                $s = $en['skills'][$k] ?? ['status' => 'NT', 'last' => null];
                $last = $s['last'] ? sprintf('%s · %s%s · %s', $date($s['last']['date']), self::PLACES[$s['last']['place']], $s['last']['depth'] !== null ? ' ' . $s['last']['depth'] . ' m' : '', $e($s['last']['instructor'])) : '';
                $h .= sprintf('<tr><td>&nbsp;&nbsp;%s</td><td class="st %s">%s</td><td class="small">%s</td></tr>', $e($label), $s['status'], self::STATUS[$s['status']], $last);
            }
        }
        $h .= '</table>';
        // historique (commentaires des encadrants)
        $withComments = array_filter($en['evaluations'], fn ($ev) => $ev['comment']);
        if ($withComments) {
            $h .= '<h2>Observations des encadrants</h2><table class="grid"><tr><th style="width:12%">Date</th><th style="width:32%">Élément</th><th class="st">Statut</th><th>Observation</th></tr>';
            foreach (array_slice($withComments, 0, 40) as $ev) {
                $c = Mft::competencyOf($cur, $ev['skill']);
                $h .= sprintf('<tr><td>%s</td><td class="small">%s</td><td class="st %s">%s</td><td class="small">%s <span class="muted">— %s</span></td></tr>', $date($ev['date']), $e($c['skills'][$ev['skill']] ?? $ev['skill']), $ev['status'], self::STATUS[$ev['status']], $e($ev['comment']), $e($ev['instructor']));
            }
            $h .= '</table>';
        }
        return $h;
    }

    private function teaching(array $en, array $cur, \Closure $e, \Closure $date): string
    {
        $h = '';
        foreach ($cur['modules'] as $def) {
            $st = array_values(array_filter($en['modules'], fn ($m) => $m['key'] === $def['key']))[0];
            $items = Mft::moduleItems($def);
            $status = $st['validated'] ? 'validé le ' . $date($st['validated']['date']) . ' par ' . $e($st['validated']['by']) : ($st['exempted'] ? 'dispensé le ' . $date($st['exempted']['date']) . ' (module MF1)' : 'en cours');
            $h .= sprintf('<h2>%s</h2><p class="small">Module %s · %d séance(s) complète(s), %d validée(s)%s</p>', $e($def['title']), $status, $st['counts']['complete'], $st['counts']['validated'],
                $def['minSessions'] > 1 ? sprintf(' — attendu : %d complètes dont %d validées, thèmes et niveaux différents', $def['minSessions'], $def['minValidated']) : '');
            if ($def['requirements']) {
                $h .= '<p class="checks small">';
                foreach ($def['requirements'] as $k => $label) {
                    $r = ((array) $st['requirements'])[$k] ?? null;
                    $h .= sprintf('<span class="box">%s</span> %s%s<br>', $r ? '&#10003;' : '&nbsp;', $e($label), $r ? ' — ' . $date($r['date']) . ' (' . $e($r['by']) . ')' : '');
                }
                $h .= '</p>';
            }
            // séances : une ligne par séance, une colonne par item (numérotés, légende dessous)
            $h .= '<table class="grid items"><tr><th style="width:11%">Date</th><th style="width:18%">Formateur</th><th style="width:8%">Niveau</th><th>Thème</th>';
            $n = 1;
            foreach ($items as $k => $_) $h .= '<th style="width:4.5%;text-align:center">' . $n++ . '</th>';
            $h .= '<th style="width:9%">Séance</th></tr>';
            foreach (array_reverse($st['sessions']) as $s) {
                $h .= sprintf('<tr><td>%s</td><td class="small">%s</td><td>%s</td><td class="small">%s</td>', $date($s['date']), $e($s['instructor']), $e($s['level']), $e($s['theme']));
                $vals = (array) $s['items'];
                foreach ($items as $k => $_) { $v = $vals[$k] ?? 'NT'; $h .= sprintf('<td class="st %s">%s</td>', $v === 'A' ? 'V' : $v, $v); }
                $h .= sprintf('<td class="small">%s</td></tr>', $s['validated'] ? 'validée' : ($s['complete'] ? 'complète' : 'incomplète'));
            }
            if (!$st['sessions']) $h .= sprintf('<tr><td colspan="%d" class="muted">Aucune séance.</td></tr>', count($items) + 5);
            $h .= '</table><p class="small muted">';
            $n = 1;
            foreach ($def['phases'] as $phase => $list) {
                $h .= '<b>' . $e($phase) . '</b> : ';
                $parts = [];
                foreach ($list as $label) $parts[] = $n++ . '. ' . $e($label);
                $h .= implode(' · ', $parts) . '<br>';
            }
            $h .= '</p>';
        }
        return $h;
    }
}
