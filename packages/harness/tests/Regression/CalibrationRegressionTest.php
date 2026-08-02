<?php

declare(strict_types=1);

namespace Flair\Harness\Tests\Regression;

use Flair\Harness\Metrics\Sampler;
use Flair\Harness\Population\PopulationFactory;
use Flair\Harness\Population\PopulationSpec;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Ruleset\Ruleset;
use PHPUnit\Framework\TestCase;

/**
 * Garde-fou de non-regression pour le critere de sortie Phase 0 (docs/15- §4,
 * mesure empirique du 2026-08-02 dans docs/15-roadmap.md) : si un futur
 * systeme casse la stationnarite de la population ou la plausibilite de la
 * repartition des scores, ce test doit le detecter avant que ce soit
 * decouvert a la main (cf. l'audit manuel qui a precede ce test).
 *
 * Assertions numeriques directes sur Sampler::run() plutot qu'un parsing de
 * la sortie texte de bin/aggregate.php - plus robuste a un changement de
 * mise en forme du rapport, qui n'a rien a voir avec une regression de
 * calibrage. Bornes volontairement larges (±8 points de pourcentage, 250-400
 * joueurs) : ce test doit attraper une vraie regression, pas le bruit normal
 * entre deux seeds - deja observe entre seed=42 et seed=7 dans le meme
 * intervalle lors de la mesure manuelle.
 *
 * 25 ans (pas 40) pour rester sous ~35s en CI : l'empirique montre une
 * stabilisation de la population des l'annee ~13, donc 25 ans garde une
 * marge confortable sans payer le cout complet d'un run de calibrage manuel.
 */
final class CalibrationRegressionTest extends TestCase
{
    public function testPopulationAndMatchDistributionStayInPlausibleBounds(): void
    {
        $spec = new PopulationSpec(playerCount: 500, years: 25, seed: 42, clubCount: 18);
        $world = new WorldState();
        $playerIds = (new PopulationFactory())->populate($world, $spec);
        $result = (new Sampler())->run($world, $playerIds, $spec->years, $spec->seed, new Ruleset('ci'));

        $finalPopulation = $result->populationByYear[$spec->years];
        self::assertGreaterThanOrEqual(250, $finalPopulation);
        self::assertLessThanOrEqual(400, $finalPopulation);

        $distribution = $result->matchResultDistribution;
        $total = $distribution['homeWin'] + $distribution['draw'] + $distribution['awayWin'];
        self::assertGreaterThan(0, $total);
        self::assertEqualsWithDelta(0.42, $distribution['homeWin'] / $total, 0.08);
        self::assertEqualsWithDelta(0.29, $distribution['draw'] / $total, 0.08);
        self::assertEqualsWithDelta(0.29, $distribution['awayWin'] / $total, 0.08);
    }
}
