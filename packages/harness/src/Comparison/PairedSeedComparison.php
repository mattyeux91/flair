<?php

declare(strict_types=1);

namespace Flair\Harness\Comparison;

use Flair\Harness\Metrics\AggregateResult;
use Flair\Harness\Metrics\Sampler;
use Flair\Harness\Population\PopulationFactory;
use Flair\Harness\Population\PopulationSpec;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Ruleset\Ruleset;

/**
 * Rejoue le meme jeu de graines de population (meme seed -> memes joueurs,
 * memes tirages de croissance/declin) avec deux Ruleset differents, pour
 * isoler l'effet d'un changement de parametre du bruit stochastique
 * (docs/13- §4.0, docs/15- §4 Phase 1 - le mode de comparaison qui rend un
 * critere de sortie atteignable sans 5 a 20x plus de runs).
 */
final class PairedSeedComparison
{
    public function __construct(
        private readonly PopulationFactory $populationFactory = new PopulationFactory(),
        private readonly Sampler $sampler = new Sampler(),
    ) {
    }

    /** @return array{baseline: AggregateResult, modified: AggregateResult} */
    public function compare(PopulationSpec $spec, Ruleset $baseline, Ruleset $modified): array
    {
        return [
            'baseline' => $this->runOnce($spec, $baseline),
            'modified' => $this->runOnce($spec, $modified),
        ];
    }

    private function runOnce(PopulationSpec $spec, Ruleset $ruleset): AggregateResult
    {
        $world = new WorldState();
        $playerIds = $this->populationFactory->populate($world, $spec);

        return $this->sampler->run($world, $playerIds, $spec->years, $spec->seed, $ruleset);
    }
}
