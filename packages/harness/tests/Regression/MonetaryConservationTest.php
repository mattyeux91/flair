<?php

declare(strict_types=1);

namespace Flair\Harness\Tests\Regression;

use Flair\Harness\Population\PopulationFactory;
use Flair\Harness\Population\PopulationSpec;
use Flair\Harness\Simulation\StepRunner;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Football\Components\Finances;
use Flair\Kernel\Football\Singletons\MonetaryMass;
use PHPUnit\Framework\TestCase;

/**
 * Mecanise le critere de sortie Phase 2 (docs/15-roadmap.md §4) : "invariant
 * de conservation monetaire vert sur 20 saisons" - Σ injections − Σ puits =
 * Δ masse monetaire totale (docs/14-algorithmes.md §6), a la centime pres.
 *
 * Assertion stricte (`assertSame` sur des entiers, pas
 * `assertEqualsWithDelta`) : possible uniquement parce que `Finances`/
 * `Contract`/`MonetaryMass` sont en centimes entiers, jamais en float - avec
 * des flottants, cette egalite exacte ne serait pas verifiable a coup sur
 * apres des milliers d'operations d'addition/soustraction sur 20 saisons.
 *
 * `MonetaryMass` n'est pas recalcule independamment ici (cf. docblock
 * Football\FinanceSystem) : le test compare la variation reelle de
 * `Finances` au bookkeeping du singleton, qui est un sous-produit direct de
 * la meme boucle - une divergence entre les deux signale un systeme qui cree
 * ou detruit de l'argent sans le comptabiliser.
 */
final class MonetaryConservationTest extends TestCase
{
    public function testMonetaryMassIsConservedOverTwentySeasons(): void
    {
        $spec = new PopulationSpec(playerCount: 500, years: 20, seed: 42, clubCount: 18, startingBalanceCents: 10_000_000);
        $world = new WorldState();
        (new PopulationFactory())->populate($world, $spec);

        $initialTotal = $this->sumFinances($world);

        $runner = new StepRunner($world, new Ruleset('ci'), $spec->seed);
        $runner->advance($spec->years * 365);

        $finalTotal = $this->sumFinances($world);
        $mass = $world->singleton(MonetaryMass::class) ?? new MonetaryMass();

        self::assertSame(
            $finalTotal - $initialTotal,
            $mass->totalInjectionsCents - $mass->totalSinksCents,
        );
    }

    private function sumFinances(WorldState $world): int
    {
        $total = 0;
        foreach ($world->components(Finances::class)->entities() as $clubId) {
            $finances = $world->components(Finances::class)->get($clubId);

            if ($finances === null) {
                continue;
            }

            $total += $finances->balanceCents;
        }

        return $total;
    }
}
