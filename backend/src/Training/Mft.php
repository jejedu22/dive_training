<?php
namespace App\Training;

/**
 * Référentiel des cursus plongeur, d'après les manuels de formation technique (MFT) de la FFESSM :
 * N1 (PE20, déc. 2025), N2 (PA20 + PE40, mai 2026), N3 (PA40 + PE60 + PA60, déc. 2025).
 *
 * Seuls les intitulés sont repris (les manuels sont protégés) : se référer au MFT pour les contenus et modalités.
 * Les clés (N2.planifier.topologie…) sont stables : les évaluations y font référence. Pour une nouvelle version
 * du MFT, ajouter/renommer des éléments sans réutiliser une clé pour un autre sens.
 *
 * Par compétence :
 *  - quals   : qualifications qu'elle compte (la qualification est délivrable quand elles sont toutes validées) ;
 *  - final   : à valider spécifiquement et en fin de formation ;
 *  - theory  : connaissances théoriques (évaluables oralement, en situation ou par écrit : pas de contrainte de lieu) ;
 *  - natural : validation en milieu naturel uniquement (en plus de la règle du cursus).
 */
final class Mft
{
    public const LEVELS = ['E1', 'E2', 'E3', 'E4'];
    /** Prérequis de l'initiateur utilisés par les règles (stage initial, examen) : non modifiables. */
    public const INIT_STAGE = 'Stage initial réalisé (14 h, encadré par un E4)';
    public const INIT_RIFA = 'RIFA Plongée';
    public const PLACES = ['pool' => 'Piscine', 'pit' => 'Fosse', 'natural' => 'Milieu naturel'];
    public const STATUSES = ['ECA' => 'En cours d\'acquisition', 'A' => 'Acquis', 'V' => 'Validé'];

    /** @var null|\Closure(): array<string, array> chargement des cursus enregistrés (voir Referential) */
    private static ?\Closure $loader = null;
    private static ?array $cache = null;

    /** Branché à chaque requête par Referential : les cursus modifiés par les administrateurs remplacent les valeurs par défaut. */
    public static function setLoader(?\Closure $loader): void
    {
        self::$loader = $loader;
        self::$cache = null;
    }

    /** Cursus en vigueur (modifiés en base, sinon valeurs par défaut du MFT). @return array<string, array> */
    public static function curricula(): array
    {
        return self::$cache ??= (self::$loader ? (self::$loader)() : self::defaults());
    }

    /** Valeurs par défaut, d'après les manuels FFESSM. @return array<string, array> */
    public static function defaults(): array
    {
        return [
            'N1' => [
                'type' => 'diver', 'code' => 'N1', 'name' => 'Niveau 1', 'subtitle' => 'Plongeur encadré à 20 m (PE20)', 'version' => 'MFT décembre 2025',
                'qualifications' => ['N1' => 'Niveau 1 (PE20)'],
                // Enseignement et validation de 0 à 6 m par un E1 minimum ; délivrance : président du club ou E3 minimum
                'validateLevel' => 'E1', 'issueLevel' => 'E3', 'presidentMayIssue' => true, 'naturalOnly' => false, 'delayMonths' => 15,
                'prerequisites' => ['Licence FFESSM en cours de validité', 'Certificat d\'absence de contre-indication', 'Baptême réalisé'],
                'notes' => ['Validation possible en piscine : 4 plongées en milieu naturel à réaliser dans les 12 mois suivant la certification.'],
                'competencies' => [
                    self::c('N1.equiper', 'S\'équiper et se déséquiper', ['N1'], ['greage' => 'Gréage et dégréage', 'capelage' => 'Capelage et décapelage', 'materiel' => 'Choix de son matériel personnel']),
                    self::c('N1.eau', 'Se mettre à l\'eau et en sortir', ['N1'], ['saut' => 'Saut droit', 'bascule' => 'Bascule arrière', 'plage' => 'Départ plage', 'sortie' => 'Sortir de l\'eau']),
                    self::c('N1.immerger', 'Évoluer dans l\'eau – S\'immerger', ['N1'], ['canard' => 'Canard', 'phoque' => 'Phoque']),
                    self::c('N1.propulser', 'Évoluer dans l\'eau – Se propulser', ['N1'], ['ventral' => 'Palmage ventral en surface', 'dorsal' => 'Palmage dorsal', 'sustentation' => 'Palmage de sustentation', 'immersion' => 'Palmage en immersion', 'capele' => 'Nage en capelé']),
                    self::c('N1.ventiler', 'Évoluer dans l\'eau – Se ventiler', ['N1'], ['immersion' => 'Ventilation en immersion', 'tuba' => 'Ventilation sur tuba et vidage du tuba', 'masque' => 'Vidage du masque', 'embout' => 'Lâcher et reprise d\'embout']),
                    self::c('N1.equilibrer', 'Évoluer dans l\'eau – S\'équilibrer', ['N1'], ['gilet' => 'Gestion du gilet de stabilisation', 'poumon' => 'Poumon ballast']),
                    self::c('N1.communiquer', 'Communiquer', ['N1'], ['signes' => 'Exécution des signes conventionnels']),
                    self::c('N1.securite', 'Évoluer en sécurité', ['N1'], ['procedures' => 'Application des procédures mises en œuvre par le GP', 'relais' => 'Intervention en relais']),
                    self::c('N1.milieu', 'Respecter le milieu et l\'environnement', ['N1'], ['aisance' => 'Aisance aquatique'], natural: true),
                    self::c('N1.surface', 'Retourner en surface', ['N1'], ['vitesse' => 'Maîtrise de la vitesse de remontée', 'palier' => 'Tenue d\'un palier', 'horizon' => 'Tour d\'horizon', 'gonflage' => 'Gonflage du gilet en surface', 'rec' => 'Remontée en expiration contrôlée']),
                    self::c('N1.theorie', 'Connaissances théoriques', ['N1'], ['physique' => 'Notions de physique', 'accidents' => 'Accidents', 'desaturation' => 'Procédures de désaturation', 'froid' => 'Froid et dangers du milieu', 'reglementation' => 'Réglementation', 'milieu' => 'Milieu et environnement'], theory: true),
                ],
            ],
            'N2' => [
                'type' => 'diver', 'code' => 'N2', 'name' => 'Niveau 2', 'subtitle' => 'Plongeur autonome à 20 m (PA20) · Plongeur encadré à 40 m (PE40)', 'version' => 'MFT mai 2026',
                'qualifications' => ['PA20' => 'PA20 – Autonome à 20 m', 'PE40' => 'PE40 – Encadré à 40 m', 'N2' => 'Niveau 2'],
                // Enseignement et validation de 0 à 20 m par un E2 minimum, en milieu naturel ; délivrance : E3 minimum
                'validateLevel' => 'E2', 'issueLevel' => 'E3', 'presidentMayIssue' => false, 'naturalOnly' => true, 'delayMonths' => 15,
                'prerequisites' => ['Licence FFESSM en cours de validité', 'Certificat d\'absence de contre-indication', 'Niveau 1 (ou équivalent)', '4 plongées attestées en milieu naturel'],
                'notes' => ['Compétences obtenues en milieu naturel, à l\'exclusion des piscines et fosses.', 'Le titulaire du PA20 ou du PE40 obtient le N2 en acquérant l\'autre qualification.'],
                'competencies' => [
                    self::c('N2.equiper', 'S\'équiper et se mettre à l\'eau', ['PA20', 'PE40', 'N2'], ['greage' => 'Gréage et dégréage', 'capelage' => 'Capelage et décapelage', 'eau' => 'Saut droit et bascule arrière – Remontée à l\'échelle'], group: 'Communes'),
                    self::c('N2.immerger', 'S\'immerger, se propulser, se ventiler', ['PA20', 'PE40', 'N2'], ['canard' => 'Canard et phoque', 'palmage' => 'Palmages', 'rec' => 'Remontée en expiration contrôlée (REC)', 'descente' => 'Descente et remontée'], group: 'Communes'),
                    self::c('N2.milieu', 'Respecter le milieu et l\'environnement', ['PA20', 'PE40', 'N2'], ['aisance' => 'Aisance aquatique'], group: 'Communes'),
                    self::c('N2.materiel', 'Être attentif au matériel de ses équipiers', ['PA20', 'N2'], ['sien' => 'Mise en œuvre de son propre matériel', 'equipiers' => 'Connaissance du matériel des équipiers'], group: 'Spécifiques PA20'),
                    self::c('N2.autonomie', 'Évoluer en autonomie', ['PA20', 'N2'], ['palanquee' => 'Sécurité de la palanquée'], group: 'Spécifiques PA20'),
                    self::c('N2.planifier', 'Planifier la plongée en fonction des consignes du DP', ['PA20', 'N2'], ['directives' => 'Compréhension des directives du DP', 'topologie' => 'Compréhension de la topologie du site, orientation', 'profil' => 'Détermination du profil de la plongée et des procédures en immersion'], group: 'Spécifiques PA20'),
                    self::c('N2.assistance', 'Intervenir et porter assistance à un plongeur en difficulté', ['PA20', 'N2'], ['incident' => 'Observation, compréhension et réaction face à un incident'], group: 'Spécifiques PA20', final: true),
                    self::c('N2.theoriePA20', 'Connaissances théoriques PA20', ['PA20', 'N2'], ['activite' => 'Théorie de l\'activité', 'accidents' => 'Accidents', 'desaturation' => 'Procédures de désaturation', 'materiel' => 'Matériel', 'reglementation' => 'Réglementation', 'milieu' => 'Milieu et environnement'], group: 'Spécifiques PA20', theory: true),
                    self::c('N2.ventiler', 'Ventiler et s\'équilibrer', ['PE40', 'N2'], ['ventilation' => 'Ventilation en surface et en immersion', 'masque' => 'Vidage du masque', 'stabilisation' => 'Stabilisation'], group: 'Spécifiques PE40'),
                    self::c('N2.communiquer', 'Communiquer avec le GP', ['PE40', 'N2'], ['signes' => 'Connaissance de tous les signes et codes'], group: 'Spécifiques PE40'),
                    self::c('N2.surface', 'Retourner en surface', ['PE40', 'N2'], ['desaturation' => 'Gestion de la désaturation', 'isolee' => 'Gestion d\'une remontée isolée'], group: 'Spécifiques PE40'),
                    self::c('N2.relai', 'Intervenir en relai sur un équipier en difficulté', ['PE40', 'N2'], ['relai' => 'Intervention en relai'], group: 'Spécifiques PE40', final: true),
                    self::c('N2.theoriePE40', 'Connaissances théoriques PE40', ['PE40', 'N2'], ['activite' => 'Théorie de l\'activité', 'accidents' => 'Accidents', 'desaturation' => 'Procédures de désaturation', 'reglementation' => 'Réglementation', 'milieu' => 'Milieu et environnement'], group: 'Spécifiques PE40', theory: true),
                ],
            ],
            'N3' => [
                'type' => 'diver', 'code' => 'N3', 'name' => 'Niveau 3', 'subtitle' => 'Plongeur autonome à 40 m (PA40) · Encadré à 60 m (PE60) · Autonome à 60 m (PA60)', 'version' => 'MFT décembre 2025',
                'qualifications' => ['PA40' => 'PA40 – Autonome à 40 m', 'PE60' => 'PE60 – Encadré à 60 m', 'N3' => 'Niveau 3 (PA60)'],
                // Enseignement et validation de 0 à 40 m par un E3 minimum, en milieu naturel ; délivrance : E3 minimum
                'validateLevel' => 'E3', 'issueLevel' => 'E3', 'presidentMayIssue' => false, 'naturalOnly' => true, 'delayMonths' => 15,
                'prerequisites' => ['Licence FFESSM en cours de validité', 'Certificat d\'absence de contre-indication', 'Niveau 2 ou PA40 (ou équivalent)', 'RIFA Plongée (au moment de la certification)', 'PE60 : PE40 et 20 plongées en milieu naturel dont 5 entre 35 et 40 m'],
                'notes' => ['Compétences obtenues en milieu naturel, à l\'exclusion des piscines et fosses.', 'De 40 à 60 m : plongées sous la responsabilité d\'un E4.'],
                'competencies' => [
                    self::c('N3.planifier', 'Planifier la plongée', ['PA40', 'N3'], ['directives' => 'Prise en compte des directives du DP', 'topologie' => 'Compréhension de la topologie du site, orientation', 'profil' => 'Détermination du profil de la plongée et des procédures en immersion'], group: 'Spécifiques PA40'),
                    self::c('N3.autonomie40', 'Évoluer en autonomie (0–40 m)', ['PA40'], ['orientation' => 'Orientation', 'evolution' => 'Évolution subaquatique', 'desaturation' => 'Désaturation'], group: 'Spécifiques PA40'),
                    self::c('N3.assistance', 'Intervenir et porter assistance à un plongeur en difficulté', ['PA40', 'N3'], ['incident' => 'Observation, compréhension et réaction face à un incident'], group: 'Spécifiques PA40', final: true),
                    self::c('N3.profondeur', 'S\'adapter à la profondeur', ['PE60', 'N3'], ['stabilisation' => 'Stabilisation', 'techniques' => 'Mise en œuvre de l\'ensemble des autres techniques'], group: 'Spécifiques PE60'),
                    self::c('N3.organiser', 'Organiser la plongée', ['N3'], ['site' => 'Choix du site', 'conditions' => 'Organisation des conditions de la plongée', 'securisation' => 'Sécurisation de l\'activité'], group: 'Complémentaires N3'),
                    self::c('N3.autonomie60', 'Évoluer en autonomie (0–60 m)', ['N3'], ['orientation' => 'Orientation', 'evolution' => 'Évolution subaquatique', 'desaturation' => 'Désaturation'], group: 'Complémentaires N3'),
                    self::c('N3.milieu', 'Respecter le milieu et l\'environnement', ['PA40', 'PE60', 'N3'], ['aisance' => 'Aisance aquatique'], group: 'Compétence commune'),
                    self::c('N3.theorie', 'Connaissances théoriques PA40 – N3', ['PA40', 'N3'], ['activite' => 'Théorie de l\'activité', 'accidents' => 'Accidents', 'desaturation' => 'Procédures de désaturation', 'reglementation' => 'Réglementation', 'milieu' => 'Milieu et environnement'], group: 'Connaissances théoriques', theory: true),
                ],
            ],
            'INIT' => self::initiator(),
        ];
    }

    /**
     * Formation Initiateur (E1), d'après le livret pédagogique Initiateur FFESSM (février 2020).
     * Stage en situation encadré par un E4 ou un E3 titulaire du TSI : trois modules évalués par séances.
     * Modules 1 et 2 : au moins 5 séances complètes dont 2 validées, sur des thèmes et des niveaux de plongeurs différents.
     * Une séance est complète quand toutes ses phases sont réalisées, validée quand toutes sont acquises.
     */
    private static function initiator(): array
    {
        return [
            'type' => 'teaching', 'code' => 'INIT', 'name' => 'Initiateur', 'subtitle' => 'Initiateur de club (E1) – stage en situation', 'version' => 'Livret pédagogique février 2020',
            'qualifications' => ['INIT' => 'Initiateur (examen réussi)'],
            'validateLevel' => 'E3', 'issueLevel' => 'E3', 'presidentMayIssue' => false, 'naturalOnly' => false, 'delayMonths' => 36,
            'tutor' => 'E4, ou E3 titulaire de l\'attestation TSI',
            'prerequisites' => ['Licence FFESSM en cours de validité', 'Stage initial réalisé (14 h, encadré par un E4)', 'RIFA Plongée', '12 plongées après le PA20 (titulaires du PA20 seulement)'],
            'notes' => [
                'Livret pédagogique valable 3 ans à partir du stage initial.',
                'Stage en situation encadré par un E4 ou un E3 titulaire du TSI.',
                'Examen organisé par une CTR (ou par délégation), en présence d\'un délégué CTR E4.',
            ],
            'levelsTaught' => ['N1', 'PE20', 'PA20', 'PE40', 'N2', 'PA40', 'PE60', 'N3', 'Baptême', 'Autre'],
            'competencies' => [],
            'modules' => [
                [
                    'key' => 'm1', 'title' => 'Enseignement pratique entre 0 et 6 mètres et théorie associée',
                    'minSessions' => 5, 'minValidated' => 2, 'distinct' => true, 'exemptable' => 'Dispense possible si le module correspondant du MF1 (enseignement pratique entre 0 et 6 m) a été validé en stage en situation MF1.',
                    'phases' => [
                        'Concevoir' => ['objectifs' => 'Définir les objectifs de formation', 'cursus' => 'Positionner le thème dans le cursus', 'justifier' => 'Justifier la séance', 'strategie' => 'Définir une stratégie d\'enseignement'],
                        'Réaliser' => ['accueillir' => 'Accueillir les plongeurs', 'animer' => 'Animer une séance et communiquer', 'mettre' => 'Mettre en œuvre une stratégie de formation'],
                        'Évaluer' => ['evaluer' => 'Réaliser une évaluation adaptée (initiale, finale, formative…)'],
                    ],
                    'requirements' => [],
                ],
                [
                    'key' => 'm2', 'title' => 'Organiser et sécuriser l\'activité',
                    'minSessions' => 5, 'minValidated' => 2, 'distinct' => true, 'exemptable' => null,
                    'phases' => [
                        'Organisation' => ['accueillir' => 'Accueillir les plongeurs', 'deroulement' => 'Organiser le déroulement d\'une séance pratique', 'securiser' => 'Sécuriser et surveiller', 'reagir' => 'Réagir en cas d\'accident'],
                    ],
                    'requirements' => ['mannequin' => 'Épreuve du mannequin de l\'IPD réalisée dans sa totalité', 'intervention' => 'Intervention réussie sur un plongeur en difficulté, avec scaphandre'],
                ],
                [
                    'key' => 'm3', 'title' => 'Organiser un cursus de formation',
                    'minSessions' => 1, 'minValidated' => 1, 'distinct' => false, 'exemptable' => null,
                    'help' => 'Validé dès lors que l\'ensemble des items est acquis sur l\'ensemble des cursus couverts par les prérogatives de l\'initiateur.',
                    'phases' => [
                        'Organiser' => ['nature' => 'Identifier la nature du cursus à organiser', 'planifier' => 'Planifier', 'logistique' => 'Définir une logistique matérielle', 'humains' => 'Définir les moyens humains'],
                    ],
                    'requirements' => [],
                ],
            ],
        ];
    }

    public static function module(array $curriculum, string $key): ?array
    {
        foreach ($curriculum['modules'] ?? [] as $m) if ($m['key'] === $key) return $m;
        return null;
    }

    /** Items d'un module : clé => libellé. */
    public static function moduleItems(array $module): array
    {
        return array_merge(...array_values($module['phases']));
    }

    public static function curriculum(string $code): ?array { return self::curricula()[$code] ?? null; }

    /** Compétence contenant l'élément $skill (clé complète « N1.ventiler.masque »). */
    public static function competencyOf(array $curriculum, string $skill): ?array
    {
        foreach ($curriculum['competencies'] as $c) if (isset($c['skills'][$skill])) return $c;
        return null;
    }

    public static function levelRank(?string $level): int
    {
        $i = array_search($level, self::LEVELS, true);
        return $i === false ? 0 : $i + 1;
    }

    private static function c(string $key, string $title, array $quals, array $skills, ?string $group = null, bool $final = false, bool $theory = false, bool $natural = false): array
    {
        $full = [];
        foreach ($skills as $k => $label) $full["$key.$k"] = $label;
        return ['key' => $key, 'title' => $title, 'group' => $group, 'quals' => $quals, 'skills' => $full, 'final' => $final, 'theory' => $theory, 'natural' => $natural];
    }
}
