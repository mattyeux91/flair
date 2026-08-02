<?php

declare(strict_types=1);

namespace Flair\Harness\Simulation;

use Flair\Kernel\Core\Pipeline\Pipeline;
use Flair\Kernel\Football\Systems\CalendarSystem;
use Flair\Kernel\Football\Systems\CompetitionSystem;
use Flair\Kernel\Football\Systems\MatchSystem;
use Flair\Kernel\Football\Systems\PlayerDevelopmentSystem;
use Flair\Kernel\Football\Systems\RetirementSystem;
use Flair\Kernel\Football\Systems\TrainingSystem;
use Flair\Kernel\Football\Systems\YouthIntakeSystem;

/**
 * Seule source de verite pour l'ordre des 7 systemes du pipeline football
 * (docs/CLAUDE.md : YouthIntakeSystem en tete, TrainingSystem/RetirementSystem
 * avant PlayerDevelopmentSystem, MatchSystem avant CompetitionSystem). Extrait
 * de Metrics\Sampler pour que Sampler et Simulation\StepRunner ne dupliquent
 * jamais cette liste - une divergence entre les deux serait silencieuse.
 */
final class PipelineFactory
{
    public static function build(): Pipeline
    {
        return new Pipeline([
            new YouthIntakeSystem(),
            new TrainingSystem(),
            new RetirementSystem(),
            new PlayerDevelopmentSystem(),
            new CalendarSystem(),
            new MatchSystem(),
            new CompetitionSystem(),
        ]);
    }
}
