<?php

declare(strict_types=1);

namespace Flair\Harness\Simulation;

use Flair\Kernel\Core\Pipeline\Pipeline;
use Flair\Kernel\Football\Systems\CalendarSystem;
use Flair\Kernel\Football\Systems\CompetitionSystem;
use Flair\Kernel\Football\Systems\FinanceSystem;
use Flair\Kernel\Football\Systems\MatchSystem;
use Flair\Kernel\Football\Systems\PlayerDevelopmentSystem;
use Flair\Kernel\Football\Systems\RetirementSystem;
use Flair\Kernel\Football\Systems\TrainingSystem;
use Flair\Kernel\Football\Systems\YouthIntakeSystem;

/**
 * Seule source de verite pour l'ordre des 8 systemes du pipeline football
 * (docs/CLAUDE.md : YouthIntakeSystem en tete, TrainingSystem/RetirementSystem
 * avant PlayerDevelopmentSystem, MatchSystem avant CompetitionSystem).
 * FinanceSystem (Phase 2) vient juste apres RetirementSystem : il lit
 * `Contract`, que RetirementSystem retire sur retraite - un systeme ne peut
 * pas lire un composant qu'un systeme plus loin dans le pipeline retire
 * (`Football\PipelineInvariantsTest`). Extrait de Metrics\Sampler pour que
 * Sampler et Simulation\StepRunner ne dupliquent jamais cette liste - une
 * divergence entre les deux serait silencieuse.
 */
final class PipelineFactory
{
    public static function build(): Pipeline
    {
        return new Pipeline([
            new YouthIntakeSystem(),
            new TrainingSystem(),
            new RetirementSystem(),
            new FinanceSystem(),
            new PlayerDevelopmentSystem(),
            new CalendarSystem(),
            new MatchSystem(),
            new CompetitionSystem(),
        ]);
    }
}
