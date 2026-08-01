<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Flair\Harness\Comparison\PairedSeedComparison;
use Flair\Harness\Comparison\RulesetOverride;
use Flair\Harness\Metrics\Sampler;
use Flair\Harness\Population\PopulationFactory;
use Flair\Harness\Report\JsonSerializer;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Ruleset\Ruleset;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    /** @var array<string, mixed> $input */
    $input = json_decode((string) file_get_contents('php://input'), true) ?? [];

    $players = max(1, min(5000, (int) ($input['players'] ?? 200)));
    $years = max(1, min(100, (int) ($input['years'] ?? 30)));
    $seed = (int) ($input['seed'] ?? 42);

    $baseline = new Ruleset('harness');
    $compareField = trim((string) ($input['compareField'] ?? ''));

    if ($compareField !== '' && isset($input['compareValue']) && is_numeric($input['compareValue'])) {
        $modified = RulesetOverride::agingField($baseline, $compareField, (float) $input['compareValue']);
        $results = new PairedSeedComparison()->compare($players, $years, $seed, $baseline, $modified);

        echo json_encode([
            'baseline' => JsonSerializer::toArray($results['baseline']),
            'modified' => JsonSerializer::toArray($results['modified']),
        ]);
        exit;
    }

    $world = new WorldState();
    $playerIds = new PopulationFactory()->populate($world, $players, $seed);
    $result = new Sampler()->run($world, $playerIds, $years, $seed, $baseline);

    echo json_encode(['baseline' => JsonSerializer::toArray($result)]);
    exit;
}

$agingFields = RulesetOverride::AGING_FIELDS;
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Flair — Harness de calibration (vieillissement)</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <header>
        <h1>Harness de calibration — vieillissement</h1>
        <p>Simule une population synthetique de joueurs et agrege des distributions (courbe de competence par age, ages de retraite) pour juger empiriquement de l'effet des parametres de vieillissement (<code>RetirementBalance</code>/<code>PlayerDevelopmentBalance</code>).</p>
    </header>

    <form id="run-form">
        <fieldset>
            <legend>Population</legend>
            <label>Joueurs <input type="number" name="players" value="200" min="1" max="5000"></label>
            <label>Annees simulees <input type="number" name="years" value="30" min="1" max="100"></label>
            <label>Graine <input type="number" name="seed" value="42"></label>
        </fieldset>

        <fieldset>
            <legend>Comparaison a graines appariees (optionnel)</legend>
            <label>Champ de vieillissement a modifier
                <select name="compareField">
                    <option value="">— aucune comparaison —</option>
                    <?php foreach ($agingFields as $field): ?>
                        <option value="<?= htmlspecialchars($field) ?>"><?= htmlspecialchars($field) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Nouvelle valeur <input type="number" step="0.01" name="compareValue"></label>
        </fieldset>

        <button type="submit">Simuler</button>
        <span id="status"></span>
    </form>

    <fieldset id="filters" hidden>
        <legend>Filtres</legend>
        <label><input type="checkbox" name="category" value="physical" checked> Physique</label>
        <label><input type="checkbox" name="category" value="technical" checked> Technique</label>
        <label><input type="checkbox" name="category" value="mental" checked> Mental</label>
        <label><input type="checkbox" id="toggle-band" checked> Bande p10-p90</label>
        <label><input type="checkbox" id="toggle-chained" checked> Courbe corrigee (methode delta)</label>
    </fieldset>

    <section id="charts"></section>

    <script src="app.js"></script>
</body>
</html>
