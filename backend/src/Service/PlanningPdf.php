<?php
namespace App\Service;

use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\Response;

/** Export PDF du planning (A4 paysage) : une ligne par date de créneau, une colonne par ligne d'eau. */
final class PlanningPdf
{
    public function __construct(private SlotService $slots) {}

    /** $from : début de la période, pour nommer le fichier (planning-2026-12-01.pdf). */
    public function response(array $planning, string $title, ?\DateTimeImmutable $from = null): Response
    {
        $filename = 'planning-' . ($from ?? new \DateTimeImmutable())->setTimezone($this->slots->timezone())->format('Y-m-d') . '.pdf';
        return new Response($this->render($planning, $title), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }

    public function render(array $planning, string $title): string
    {
        $options = (new Options())->set('defaultFont', 'DejaVu Sans')->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($planning, $title), 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        return $dompdf->output();
    }

    public function html(array $planning, string $title): string
    {
        $e = fn (?string $s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $tz = $this->slots->timezone();
        $fmt = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $tz, null, 'EEE d MMM');
        $time = fn (string $iso) => (new \DateTimeImmutable($iso))->setTimezone($tz)->format('H:i');
        $occs = $planning['occurrences'];

        // colonnes : toutes les lignes ouvertes sur la période, par bassin
        $pools = [];
        foreach ($occs as $o) foreach ($o['lanes'] as $l) $pools[$l['poolId']] ??= ['name' => $l['pool'], 'lanes' => []];
        foreach ($occs as $o) foreach ($o['lanes'] as $l) $pools[$l['poolId']]['lanes'][$l['laneId']] = $l['lane'];
        $laneCount = array_sum(array_map(fn ($p) => count($p['lanes']), $pools));

        $sections = [];
        $head1 = '<th class="when" rowspan="2">Créneau</th>';
        $head2 = '';
        foreach ($pools as $p) {
            $head1 .= sprintf('<th class="pool" colspan="%d">%s</th>', count($p['lanes']), $e($p['name']));
            foreach ($p['lanes'] as $name) $head2 .= sprintf('<th>%s</th>', $e($name));
        }

        $rows = '';
        foreach ($occs as $o) {
            $start = new \DateTimeImmutable($o['start']);
            $exc = $o['exceptions'] ? '<div class="exc">* ' . $e(implode(', ', array_map(fn ($x) => $x['label'] ?? 'modifié', $o['exceptions']))) . '</div>' : '';
            $rows .= sprintf('<tr><td class="when"><b>%s</b><br><span class="t">%s–%s</span>%s</td>', $e(ucfirst($fmt->format($start))), $time($o['start']), $time($o['end']), $exc);
            if ($o['cancelled']) {
                $rows .= sprintf('<td class="cancel" colspan="%d">Créneau annulé</td></tr>', $laneCount);
                continue;
            }
            $byLane = [];
            foreach ($o['lanes'] as $l) $byLane[$l['laneId']] = $l;
            foreach ($pools as $p) {
                foreach (array_keys($p['lanes']) as $laneId) {
                    $l = $byLane[$laneId] ?? null;
                    if (!$l) { $rows .= '<td class="closed"></td>'; continue; }
                    $mark = $l['exception'] ? '<span class="mark">*</span>' : '';
                    if (!$l['sections']) { $rows .= '<td class="free">libre' . $mark . '</td>'; continue; }
                    foreach ($l['sections'] as $s) $sections[$s['id']] = $s;
                    $cell = fn (array $s) => sprintf('background:%s;color:%s', $e($s['color']), self::textOn($s['color']));
                    if (count($l['sections']) === 1) {
                        // une section : toute la case à sa couleur
                        $rows .= sprintf('<td class="sec" style="%s">%s%s</td>', $cell($l['sections'][0]), $e($l['sections'][0]['name']), $mark);
                    } else {
                        // ligne partagée : la case prend la couleur de la dernière section, un bandeau en haut pour les autres
                        // (pas de tableau imbriqué ni de hauteur en % : Dompdf les gère mal)
                        $last = array_pop($l['sections']);
                        $top = implode('', array_map(fn ($s) => sprintf('<div class="top" style="%s">%s</div>', $cell($s), $e($s['name'])), $l['sections']));
                        $rows .= sprintf('<td class="shared" style="%s">%s<div class="bot">%s%s</div></td>', $cell($last), $top, $e($last['name']), $mark);
                    }
                }
            }
            $rows .= '</tr>';
        }
        if (!$occs) $rows = sprintf('<tr><td colspan="%d" class="none">Aucun créneau du club sur cette période.</td></tr>', $laneCount + 1);

        usort($sections, fn ($a, $b) => strcmp($a['name'], $b['name']));
        $legend = implode(' ', array_map(fn ($s) => sprintf('<span class="chip" style="background:%s;color:%s">%s</span>', $e($s['color']), self::textOn($s['color']), $e($s['name'])), $sections));
        $generated = (new \DateTimeImmutable('now', $tz))->format('d/m/Y à H:i');

        return <<<HTML
<!doctype html><html lang="fr"><head><meta charset="utf-8"><style>
@page { margin: 10mm 10mm }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5pt; color: #16212c }
h1 { font-size: 15pt; margin: 0 0 2mm; color: #0b6aa8 }
.sub { color: #5d6a78; margin: 0 0 4mm }
table { width: 100%; border-collapse: collapse; table-layout: fixed }
thead { display: table-header-group }
tr { page-break-inside: avoid }
th, td { border: 0.6pt solid #cfd7df; text-align: center; vertical-align: middle; padding: 0 }
th { background: #f2f5f8; color: #5d6a78; font-weight: bold; padding: 1.5mm 1mm; font-size: 7.5pt }
th.pool { background: #e7f1f9; color: #08568a }
td.when, th.when { width: 26mm; text-align: left; padding: .6mm 2mm; line-height: 1.15 }
td.when .t { color: #5d6a78; font-size: 7.5pt }
td.when .exc { color: #8a6100; font-size: 6.5pt; margin-top: .5mm }
td.sec { height: 7mm; font-weight: bold; font-size: 7.5pt; padding: 0 .8mm }
td.shared { height: 7mm; padding: 0; vertical-align: top; font-weight: bold; font-size: 7pt }
td.shared .top { padding: .6mm .5mm; border-bottom: 0.8pt solid #fff }
td.shared .bot { padding: .6mm .5mm }
td.free { color: #9aa6b2; height: 7mm }
td.closed { background: #eceff3 }
td.cancel { color: #c13030; font-weight: bold; background: #fdeeee }
td.none { color: #5d6a78; padding: 6mm }
.mark { font-weight: bold; font-size: 7pt; margin-left: .8mm }
.legend { margin-top: 4mm; font-size: 7.5pt; color: #5d6a78 }
.chip { padding: .6mm 2mm; border-radius: 2mm; font-weight: bold; margin-right: 1mm }
</style></head><body>
<h1>{$e($title)}</h1>
<p class="sub">Généré le {$generated}</p>
<table><thead><tr>{$head1}</tr><tr>{$head2}</tr></thead><tbody>{$rows}</tbody></table>
<p class="legend">{$legend} &nbsp; libre : ligne non attribuée · gris : ligne fermée sur ce créneau · * : modifié ce jour-là</p>
</body></html>
HTML;
    }

    /** Texte lisible (blanc ou presque noir) sur une couleur de fond. */
    public static function textOn(string $hex): string
    {
        $n = hexdec(ltrim($hex, '#'));
        $lin = fn (int $v) => ($c = $v / 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        $l = 0.2126 * $lin(($n >> 16) & 255) + 0.7152 * $lin(($n >> 8) & 255) + 0.0722 * $lin($n & 255);
        return $l > 0.4 ? '#16212c' : '#ffffff';
    }
}
