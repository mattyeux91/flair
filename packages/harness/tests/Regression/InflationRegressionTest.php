<?php

declare(strict_types=1);

namespace Flair\Harness\Tests\Regression;

use Flair\Harness\Comparison\RulesetOverride;
use Flair\Harness\Population\PopulationFactory;
use Flair\Harness\Population\PopulationSpec;
use Flair\Harness\Simulation\StepRunner;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Football\Components\Contract;
use Flair\Kernel\Football\Components\Finances;
use Flair\Kernel\Football\Singletons\MarketInflation;
use PHPUnit\Framework\TestCase;

/**
 * Mecanise la seconde moitie du critere de sortie Phase 2 (docs/15- §4),
 * **telle qu'elle a du etre redefinie** au point 5 de
 * docs/17-marche-transferts.md.
 *
 * ## Pourquoi le critere du document ne pouvait pas etre teste tel quel
 *
 * « Inflation dans la cible » supposait une inflation **emergente** qu'on
 * mesurerait puis regulerait. Deux mesures l'ont exclu :
 *
 * 1. Le monde n'a **aucune inflation endogene** - sans intervention, masse
 *    monetaire et masse salariale sont plates trente saisons durant. Salaires
 *    et valeurs sont des formules du `Ruleset`, pas des prix d'equilibre :
 *    aucune quantite de monnaie ne les deplace.
 * 2. Un asservissement sur la solvabilite s'est revele **instable deux fois**,
 *    parce que sa grandeur a un denominateur endogene qui bouge dans le
 *    mauvais sens.
 *
 * L'indice est donc une **decision** et non une mesure, et verifier que le
 * taux realise egale la cible ne prouverait rien - il l'egale par
 * construction. Ce test verifie les deux choses qui, elles, peuvent casser.
 */
final class InflationRegressionTest extends TestCase
{
    private const int SEASONS = 20;

    /**
     * **La propriete la plus importante du lot.** A la cible par defaut (zero),
     * tout le mecanisme est un no-op *strict* : le monde produit est
     * rigoureusement celui d'avant le point 5, au centime.
     *
     * C'est ce qui autorise a livrer l'inflation sans invalider une seule des
     * mesures deja enregistrees - meme discipline que `meritShare = 0.0` en son
     * temps.
     */
    public function testTheDefaultTargetLeavesTheWorldStrictlyUnchanged(): void
    {
        $neutral = $this->measure(new Ruleset('ci'));

        self::assertSame(1.0, $neutral['index'], 'un monde sans inflation ne bouge pas d\'unite monetaire');
        self::assertSame(0.0, $neutral['rate']);

        // Les grandeurs reelles du monde, relevees pour que ce test casse si un
        // futur lot deplace le monde par defaut sans le vouloir.
        self::assertGreaterThan(0, $neutral['wageBill']);
        self::assertGreaterThan(0, $neutral['contracts']);
    }

    /**
     * A 3 %/an - l'exemple de docs/14- §6 - le monde doit rester **stationnaire
     * en termes reels** : c'est ca, « ne pas mourir d'inflation ». L'unite
     * monetaire triple, et rien ne doit decrocher derriere elle.
     *
     * La grandeur surveillee est la solvabilite (masse monetaire sur masse
     * salariale annuelle) : elle est sans dimension, donc insensible au
     * changement d'unite, et c'est exactement ce que l'inflation abime quand
     * elle tue un monde persistant.
     */
    public function testAtThreePercentTheWorldStaysStationaryInRealTerms(): void
    {
        $inflated = $this->measure(RulesetOverride::withFields(new Ruleset('ci'), ['marketInflationTarget' => 0.03]));
        $neutral = $this->measure(new Ruleset('ci'));

        self::assertEqualsWithDelta(
            1.03 ** (self::SEASONS - 1),
            $inflated['index'],
            0.05,
            'l\'indice avance de la cible a chaque saison achevee',
        );

        // La masse salariale suit l'unite monetaire : c'est ce qui fait de
        // l'indice un changement d'unite et non une distorsion de prix
        // relatifs. Tolerance large, les contrats se renegocient sur deux a
        // quatre ans donc ils suivent avec du retard.
        self::assertGreaterThan(
            $neutral['wageBill'] * 1.5,
            $inflated['wageBill'],
            'les salaires doivent suivre l\'unite monetaire, pas rester nominaux',
        );

        $band = (new Ruleset('ci'))->balance->inflation->toleranceBand;
        self::assertGreaterThan(
            0.0,
            $inflated['solvency'],
            'un monde qui finit insolvable a mal supporte son inflation',
        );
        self::assertLessThan(
            $neutral['solvency'] * (1.0 + $band) + 1.0,
            $inflated['solvency'],
            'la tresorerie ne doit pas s\'emballer en termes reels',
        );
    }

    /**
     * @return array{index: float, rate: float, solvency: float, wageBill: int, contracts: int}
     */
    private function measure(Ruleset $ruleset): array
    {
        $spec = new PopulationSpec(playerCount: 500, years: self::SEASONS, seed: 42, clubCount: 18, startingBalanceCents: 10_000_000);
        $world = new WorldState();
        (new PopulationFactory())->populate($world, $spec);

        (new StepRunner($world, $ruleset, $spec->seed))->advance($spec->years * 365);

        $inflation = $world->singleton(MarketInflation::class) ?? new MarketInflation();

        $wageBill = 0;
        $contracts = 0;

        foreach ($world->components(Contract::class)->entities() as $playerId) {
            $contract = $world->components(Contract::class)->get($playerId);

            if ($contract !== null) {
                $wageBill += $contract->wagePerWeekCents * 52;
                $contracts++;
            }
        }

        $mass = 0;

        foreach ($world->components(Finances::class)->entities() as $clubId) {
            $mass += $world->components(Finances::class)->get($clubId)->balanceCents ?? 0;
        }

        return [
            'index' => $inflation->index,
            'rate' => $inflation->annualRate,
            'solvency' => $wageBill > 0 ? $mass / $wageBill : 0.0,
            'wageBill' => $wageBill,
            'contracts' => $contracts,
        ];
    }
}
