<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Flair\Harness\Comparison\PairedSeedComparison;
use Flair\Harness\Comparison\RulesetOverride;
use Flair\Harness\Metrics\Sampler;
use Flair\Harness\Population\PopulationSpec;
use Flair\Harness\Report\JsonSerializer;
use Flair\Harness\Web\CalibrationFields;
use Flair\Harness\Web\Input;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Worldgen\WorldFactory;

$baseline = new Ruleset('harness');

/**
 * Bornes de taille du POST web, distinctes de celles du CLI
 * (`bin/aggregate.php`, sans plafond) : le kernel est un simulateur PHP pur,
 * sans JIT ni cache d'octets particulier, et une requete HTTP synchrone doit
 * rester dans un budget humainement raisonnable.
 *
 * `MAX_CLUBS` a ete resserre de 64 a 32 en ajoutant la simulation de match au
 * pipeline du Sampler : le cout d'un match scanne tout l'effectif d'un club
 * pour calculer ses ratings, et ce cout croit avec le **carre** du nombre de
 * clubs (le nombre de matchs par saison est proportionnel a clubCount²).
 * Mesure empiriquement : 1200 joueurs/35 ans sans override tournait a 64 clubs
 * en ~227s (~454s pour une comparaison a graines appariees, qui execute deux
 * fois la simulation) - largement au-dela de MAX_EXECUTION_TIME_SECONDS. A 32
 * clubs, une comparaison complete tourne en ~202s. Au-dela de ces bornes,
 * utiliser le CLI, qui n'a pas cette contrainte de requete/reponse.
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

if (Input::method() === 'POST') {
    header('Content-Type: application/json');
    set_time_limit(MAX_EXECUTION_TIME_SECONDS);

    $input = Input::jsonBody();

    $spec = new PopulationSpec(
        playerCount: Input::clamped(Input::int($input, 'players', 200), 1, MAX_PLAYERS),
        years: Input::clamped(Input::int($input, 'years', 30), 1, MAX_YEARS),
        seed: Input::int($input, 'seed', 42),
        clubCount: Input::clamped(Input::int($input, 'clubs', 18), 0, MAX_CLUBS),
        facilitiesQuality: Input::float($input, 'facilitiesQuality', 1.0),
    );

    $overrides = [];
    foreach (Input::map($input, 'overrides') as $field => $value) {
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

        // ⚠️ `$spec->world()`, pas `$spec` : la genese a quitte le harness pour
        // `packages/worldgen` et `WorldFactory` attend un `WorldSpec`. Ce site
        // est reste casse tout un lot faute d'etre analyse ou execute par quoi
        // que ce soit - d'ou `public/` sous PHPStan desormais.
        $world = new WorldState();
        $playerIds = new WorldFactory()->populate($world, $spec->world());
        $result = new Sampler()->run($world, $playerIds, $spec->years, $spec->seed, $baseline);

        echo json_encode(['baseline' => JsonSerializer::toArray($result)]);
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }

    exit;
}

$groupedFields = CalibrationFields::grouped($baseline);
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
            <?php foreach (CalibrationFields::groupLabels() as $groupLabel): ?>
                <details<?= isset(CalibrationFields::OPEN_BY_DEFAULT[$groupLabel]) ? ' open' : '' ?>>
                    <summary><?= htmlspecialchars($groupLabel) ?></summary>
                    <?php foreach ($groupedFields[$groupLabel] ?? [] as $meta): ?>
                        <label><?= htmlspecialchars($meta->label) ?>
                            <input type="number" step="<?= htmlspecialchars($meta->step) ?>" name="override[<?= htmlspecialchars($meta->field) ?>]" value="<?= htmlspecialchars((string) $meta->default) ?>" data-default="<?= htmlspecialchars((string) $meta->default) ?>"<?= $meta->boundsAttribute() ?>>
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
