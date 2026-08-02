<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Flair\Harness\Comparison\PairedSeedComparison;
use Flair\Harness\Comparison\RulesetOverride;
use Flair\Harness\Metrics\Sampler;
use Flair\Harness\Population\PopulationFactory;
use Flair\Harness\Population\PopulationSpec;
use Flair\Harness\Report\JsonSerializer;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Ruleset\Ruleset;

$baseline = new Ruleset('harness');

/**
 * Bornes de taille du POST web, distinctes de celles du CLI
 * (`bin/aggregate.php`, sans plafond) : le kernel est un simulateur PHP pur,
 * sans JIT ni cache d'octets particulier, et une requete HTTP synchrone doit
 * rester dans un budget humainement raisonnable.
 *
 * `MAX_CLUBS` a ete resserre de 64 a 32 en ajoutant la simulation de match
 * (Football\CalendarSystem/MatchSystem/CompetitionSystem) au pipeline du
 * Sampler : le cout d'un match scanne tout l'effectif d'un club pour
 * calculer ses ratings (MatchSystem::ratings(), non optimise, hors
 * perimetre de ce lot cote kernel), et ce cout croit avec le **carre** du
 * nombre de clubs (le nombre de matchs par saison est proportionnel a
 * clubCount²). Mesure empiriquement : 1200 joueurs/35 ans sans override,
 * qui tournait en ~100s avant ce lot, tournait a 64 clubs en ~227s
 * (~454s pour une comparaison a graines appariees, qui execute deux fois la
 * simulation) - largement au-dela de MAX_EXECUTION_TIME_SECONDS. A 32 clubs,
 * une comparaison complete tourne en ~202s, sous le plafond avec une marge
 * comparable a celle d'avant ce lot. Au-dela de ces bornes, utiliser le CLI,
 * qui n'a pas cette contrainte de requete/reponse.
 */
const MAX_PLAYERS = 1200;
const MAX_YEARS = 35;
const MAX_CLUBS = 32;

/**
 * Filet de securite, pas la strategie principale : les bornes ci-dessus sont
 * ce qui doit empecher un depassement en pratique. Ce plafond couvre la
 * marge mesuree (~200s pire cas) plus une marge pour une machine plus lente
 * ou une charge concurrente - pas un exercice de precision.
 */
const MAX_EXECUTION_TIME_SECONDS = 300;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    set_time_limit(MAX_EXECUTION_TIME_SECONDS);

    /** @var array<string, mixed> $input */
    $input = json_decode((string) file_get_contents('php://input'), true) ?? [];

    $spec = new PopulationSpec(
        playerCount: max(1, min(MAX_PLAYERS, (int) ($input['players'] ?? 200))),
        years: max(1, min(MAX_YEARS, (int) ($input['years'] ?? 30))),
        seed: (int) ($input['seed'] ?? 42),
        clubCount: max(0, min(MAX_CLUBS, (int) ($input['clubs'] ?? 18))),
        facilitiesQuality: (float) ($input['facilitiesQuality'] ?? 1.0),
    );

    /** @var array<string, mixed> $rawOverrides */
    $rawOverrides = \is_array($input['overrides'] ?? null) ? $input['overrides'] : [];
    $overrides = [];
    foreach ($rawOverrides as $field => $value) {
        if (\is_string($field) && \in_array($field, RulesetOverride::ALL_FIELDS, strict: true) && is_numeric($value)) {
            $overrides[$field] = (float) $value;
        }
    }

    try {
        if ($overrides !== []) {
            $modified = RulesetOverride::withFields($baseline, $overrides);
            $results = new PairedSeedComparison()->compare($spec, $baseline, $modified);

            echo json_encode([
                'baseline' => JsonSerializer::toArray($results['baseline']),
                'modified' => JsonSerializer::toArray($results['modified']),
            ]);
            exit;
        }

        $world = new WorldState();
        $playerIds = new PopulationFactory()->populate($world, $spec);
        $result = new Sampler()->run($world, $playerIds, $spec->years, $spec->seed, $baseline);

        echo json_encode(['baseline' => JsonSerializer::toArray($result)]);
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }

    exit;
}

/**
 * Metadonnees d'affichage du panneau de calibration : un tuple explicite
 * par champ de Balance (libelle FR, pas du <input>, valeur par defaut lue
 * directement sur $baseline). Pas de boucle avec acces dynamique
 * ($obj->$field) sur un nom de champ variable - chaque ligne nomme son
 * champ en clair, meme esprit d'enumeration explicite que RulesetOverride.
 *
 * `min`/`max` (optionnels) : seulement renseignes pour les champs qui
 * servent de borne de boucle dans le kernel (`talentSkew`,
 * `baseIntakePerClub` - cf. `RulesetOverride::FIELD_BOUNDS`), pour empecher
 * une saisie hors bornes de declencher le timeout serveur (30s) directement
 * depuis le formulaire, meme esprit que les bornes deja sur les champs
 * Population plus haut.
 *
 * @var list<array{field: string, group: string, label: string, step: string, default: int|float, min?: int|float, max?: int|float}>
 */
$fieldMeta = [
    ['field' => 'retirementEligibleAge', 'group' => 'Retraite', 'label' => "Âge d'éligibilité (années)", 'step' => '0.5', 'default' => $baseline->balance->retirement->retirementEligibleAge],
    ['field' => 'retirementAgeWeight', 'group' => 'Retraite', 'label' => "Poids de l'âge dans la probabilité", 'step' => '0.01', 'default' => $baseline->balance->retirement->retirementAgeWeight],
    ['field' => 'retirementFragilityWeight', 'group' => 'Retraite', 'label' => 'Poids de la fragilité', 'step' => '0.01', 'default' => $baseline->balance->retirement->retirementFragilityWeight],

    ['field' => 'growthPrimeAgeThreshold', 'group' => 'Développement', 'label' => "Seuil d'âge de progression max (années)", 'step' => '0.5', 'default' => $baseline->balance->playerDevelopment->growthPrimeAgeThreshold],
    ['field' => 'growthPlateauFactor', 'group' => 'Développement', 'label' => 'Facteur de plateau', 'step' => '0.01', 'default' => $baseline->balance->playerDevelopment->growthPlateauFactor],
    ['field' => 'declineRatePerYear', 'group' => 'Développement', 'label' => 'Pente de déclin post-pic', 'step' => '0.01', 'default' => $baseline->balance->playerDevelopment->declineRatePerYear],
    ['field' => 'physicalDeclineMultiplier', 'group' => 'Développement', 'label' => 'Multiplicateur déclin physique', 'step' => '0.1', 'default' => $baseline->balance->playerDevelopment->physicalDeclineMultiplier],
    ['field' => 'technicalDeclineMultiplier', 'group' => 'Développement', 'label' => 'Multiplicateur déclin technique', 'step' => '0.1', 'default' => $baseline->balance->playerDevelopment->technicalDeclineMultiplier],
    ['field' => 'mentalDeclineMultiplier', 'group' => 'Développement', 'label' => 'Multiplicateur déclin mental', 'step' => '0.1', 'default' => $baseline->balance->playerDevelopment->mentalDeclineMultiplier],

    ['field' => 'intakeDayOfYear', 'group' => 'Formation des jeunes', 'label' => 'Jour de promotion (tick % 365)', 'step' => '1', 'default' => $baseline->balance->youthIntake->intakeDayOfYear],
    ['field' => 'intakeAgeYears', 'group' => 'Formation des jeunes', 'label' => "Âge d'entrée pro (années)", 'step' => '0.5', 'default' => $baseline->balance->youthIntake->intakeAgeYears],
    ['field' => 'baseIntakePerClub', 'group' => 'Formation des jeunes', 'label' => 'Promotions moyennes par club/saison', 'step' => '0.1', 'default' => $baseline->balance->youthIntake->baseIntakePerClub, 'min' => 0, 'max' => 20],
    ['field' => 'ceilingMin', 'group' => 'Formation des jeunes', 'label' => 'Potentiel min (ceiling)', 'step' => '1', 'default' => $baseline->balance->youthIntake->ceilingMin],
    ['field' => 'ceilingMax', 'group' => 'Formation des jeunes', 'label' => 'Potentiel max (ceiling)', 'step' => '1', 'default' => $baseline->balance->youthIntake->ceilingMax],
    ['field' => 'talentSkew', 'group' => 'Formation des jeunes', 'label' => 'Asymétrie de la loi de talent (k)', 'step' => '1', 'default' => $baseline->balance->youthIntake->talentSkew, 'min' => 1, 'max' => 50],
    ['field' => 'startingSkillRatio', 'group' => 'Formation des jeunes', 'label' => 'Ratio de compétence de départ', 'step' => '0.01', 'default' => $baseline->balance->youthIntake->startingSkillRatio],
    ['field' => 'startingSkillJitter', 'group' => 'Formation des jeunes', 'label' => 'Bruit de compétence de départ', 'step' => '1', 'default' => $baseline->balance->youthIntake->startingSkillJitter],
    ['field' => 'physicalPeakAgeMin', 'group' => 'Formation des jeunes', 'label' => 'Âge de pic physique min', 'step' => '1', 'default' => $baseline->balance->youthIntake->physicalPeakAgeMin],
    ['field' => 'physicalPeakAgeMax', 'group' => 'Formation des jeunes', 'label' => 'Âge de pic physique max', 'step' => '1', 'default' => $baseline->balance->youthIntake->physicalPeakAgeMax],
    ['field' => 'technicalPeakAgeMin', 'group' => 'Formation des jeunes', 'label' => 'Âge de pic technique min', 'step' => '1', 'default' => $baseline->balance->youthIntake->technicalPeakAgeMin],
    ['field' => 'technicalPeakAgeMax', 'group' => 'Formation des jeunes', 'label' => 'Âge de pic technique max', 'step' => '1', 'default' => $baseline->balance->youthIntake->technicalPeakAgeMax],
    ['field' => 'mentalPeakAgeMin', 'group' => 'Formation des jeunes', 'label' => 'Âge de pic mental min', 'step' => '1', 'default' => $baseline->balance->youthIntake->mentalPeakAgeMin],
    ['field' => 'mentalPeakAgeMax', 'group' => 'Formation des jeunes', 'label' => 'Âge de pic mental max', 'step' => '1', 'default' => $baseline->balance->youthIntake->mentalPeakAgeMax],
    ['field' => 'growthRateMin', 'group' => 'Formation des jeunes', 'label' => 'Vitesse de progression min', 'step' => '0.01', 'default' => $baseline->balance->youthIntake->growthRateMin],
    ['field' => 'growthRateMax', 'group' => 'Formation des jeunes', 'label' => 'Vitesse de progression max', 'step' => '0.01', 'default' => $baseline->balance->youthIntake->growthRateMax],
    ['field' => 'fragilityMin', 'group' => 'Formation des jeunes', 'label' => 'Fragilité min', 'step' => '0.01', 'default' => $baseline->balance->youthIntake->fragilityMin],
    ['field' => 'fragilityMax', 'group' => 'Formation des jeunes', 'label' => 'Fragilité max', 'step' => '0.01', 'default' => $baseline->balance->youthIntake->fragilityMax],

    ['field' => 'developmentRate', 'group' => 'Global', 'label' => 'Multiplicateur global de progression/déclin', 'step' => '0.05', 'default' => $baseline->balance->developmentRate],
    ['field' => 'trainingRate', 'group' => 'Global', 'label' => "Multiplicateur global d'entraînement", 'step' => '0.05', 'default' => $baseline->balance->trainingRate],

    ['field' => 'seasonStartDayOfYear', 'group' => 'Calendrier', 'label' => "Jour de génération de la saison (tick % 365)", 'step' => '1', 'default' => $baseline->balance->calendar->seasonStartDayOfYear],
    ['field' => 'firstMatchdayOffsetDays', 'group' => 'Calendrier', 'label' => 'Délai avant le coup d\'envoi (jours)', 'step' => '1', 'default' => $baseline->balance->calendar->firstMatchdayOffsetDays],
    ['field' => 'matchdayIntervalDays', 'group' => 'Calendrier', 'label' => 'Espacement entre journées (jours)', 'step' => '1', 'default' => $baseline->balance->calendar->matchdayIntervalDays],

    ['field' => 'homeAdvantage', 'group' => 'Match', 'label' => "Avantage du terrain (exposant)", 'step' => '0.05', 'default' => $baseline->balance->match->homeAdvantage],
    ['field' => 'strengthScale', 'group' => 'Match', 'label' => "Échelle de force (diviseur de l'écart de rating)", 'step' => '1', 'default' => $baseline->balance->match->strengthScale],
    ['field' => 'lowScoreCorrelation', 'group' => 'Match', 'label' => 'Corrélation Dixon-Coles (ρ, scores faibles)', 'step' => '0.01', 'default' => $baseline->balance->match->lowScoreCorrelation],
    ['field' => 'maxSimulatedGoals', 'group' => 'Match', 'label' => 'Plafond de buts simulés par équipe', 'step' => '1', 'default' => $baseline->balance->match->maxSimulatedGoals],

    ['field' => 'pointsForWin', 'group' => 'Classement', 'label' => 'Points pour une victoire', 'step' => '1', 'default' => $baseline->balance->competition->pointsForWin],
    ['field' => 'pointsForDraw', 'group' => 'Classement', 'label' => 'Points pour un match nul', 'step' => '1', 'default' => $baseline->balance->competition->pointsForDraw],
];

$groupedFields = [];
foreach ($fieldMeta as $meta) {
    $groupedFields[$meta['group']][] = $meta;
}

/** Groupes deplies par defaut : ceux deja calibrables avant ce lot. Les deux autres (nouveaux, plus nombreux) restent replies. */
$openByDefault = ['Retraite' => true, 'Développement' => true];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Flair — Harness de calibration</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <header>
        <h1>Harness de calibration — vieillissement, démographie &amp; matchs</h1>
        <p>Simule une population synthétique de joueurs (répartie sur des clubs synthétiques regroupés dans une compétition) et agrège des distributions (courbe de compétence par âge, âges de retraite, effectif actif par année, pyramide des âges, buts par match, répartition des résultats, classement) pour juger empiriquement de l'effet des paramètres de <code>Ruleset::$balance</code>.</p>
    </header>

    <form id="run-form">
        <fieldset>
            <legend>Population</legend>
            <label>Joueurs <input type="number" name="players" value="200" min="1" max="<?= MAX_PLAYERS ?>"></label>
            <label>Années simulées <input type="number" name="years" value="30" min="1" max="<?= MAX_YEARS ?>"></label>
            <label>Graine <input type="number" name="seed" value="42"></label>
            <label>Clubs synthétiques <input type="number" name="clubs" value="18" min="0" max="<?= MAX_CLUBS ?>"></label>
            <label>Qualité moyenne des installations <input type="number" step="0.1" name="facilitiesQuality" value="1.0" min="0.1" max="3"></label>
        </fieldset>

        <fieldset>
            <legend>Calibration (optionnel — laisser un champ à sa valeur par défaut pour ne pas le faire varier)</legend>
            <?php foreach (array_keys(RulesetOverride::GROUPS) as $groupLabel): ?>
                <details<?= ($openByDefault[$groupLabel] ?? false) ? ' open' : '' ?>>
                    <summary><?= htmlspecialchars($groupLabel) ?></summary>
                    <?php foreach ($groupedFields[$groupLabel] ?? [] as $meta): ?>
                        <?php
                            $bounds = '';
                            if (isset($meta['min'])) {
                                $bounds .= ' min="' . htmlspecialchars((string) $meta['min']) . '"';
                            }
                            if (isset($meta['max'])) {
                                $bounds .= ' max="' . htmlspecialchars((string) $meta['max']) . '"';
                            }
                        ?>
                        <label><?= htmlspecialchars($meta['label']) ?>
                            <input type="number" step="<?= htmlspecialchars($meta['step']) ?>" name="override[<?= htmlspecialchars($meta['field']) ?>]" value="<?= htmlspecialchars((string) $meta['default']) ?>" data-default="<?= htmlspecialchars((string) $meta['default']) ?>"<?= $bounds ?>>
                        </label>
                    <?php endforeach; ?>
                </details>
            <?php endforeach; ?>
        </fieldset>

        <button type="submit">Simuler</button>
        <button type="reset">Réinitialiser</button>
        <span id="status"></span>
    </form>

    <fieldset id="filters" hidden>
        <legend>Filtres</legend>
        <label><input type="checkbox" name="category" value="physical" checked> Physique</label>
        <label><input type="checkbox" name="category" value="technical" checked> Technique</label>
        <label><input type="checkbox" name="category" value="mental" checked> Mental</label>
        <label><input type="checkbox" id="toggle-band" checked> Bande p10-p90</label>
        <span class="season-nav">
            <label for="season-select">Saison</label>
            <button type="button" id="season-prev" aria-label="Saison précédente" disabled>‹</button>
            <select id="season-select"></select>
            <button type="button" id="season-next" aria-label="Saison suivante" disabled>›</button>
        </span>
    </fieldset>

    <section id="charts"></section>

    <script src="app.js"></script>
</body>
</html>
