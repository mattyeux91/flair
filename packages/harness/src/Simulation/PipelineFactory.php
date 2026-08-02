<?php

declare(strict_types=1);

namespace Flair\Harness\Simulation;

use Flair\Kernel\Core\Pipeline\Pipeline;
use Flair\Kernel\Football\Systems\CalendarSystem;
use Flair\Kernel\Football\Systems\CompetitionSystem;
use Flair\Kernel\Football\Systems\ContractSystem;
use Flair\Kernel\Football\Systems\FacilitiesSystem;
use Flair\Kernel\Football\Systems\FinanceSystem;
use Flair\Kernel\Football\Systems\MatchSystem;
use Flair\Kernel\Football\Systems\PlayerDevelopmentSystem;
use Flair\Kernel\Football\Systems\RetirementSystem;
use Flair\Kernel\Football\Systems\SquadSystem;
use Flair\Kernel\Football\Systems\TrainingSystem;
use Flair\Kernel\Football\Systems\YouthIntakeSystem;

/**
 * Seule source de verite pour l'ordre des systemes du pipeline football
 * (docs/CLAUDE.md : YouthIntakeSystem en tete, TrainingSystem/RetirementSystem
 * avant PlayerDevelopmentSystem, MatchSystem avant CompetitionSystem).
 * FinanceSystem (Phase 2) vient juste apres RetirementSystem : il lit
 * `Contract`, ecrit par SquadSystem - un systeme ne peut pas lire un composant
 * qu'un systeme plus loin dans le pipeline ecrit ou retire
 * (`Football\PipelineInvariantsTest`).
 *
 * SquadSystem et ContractSystem encadrent tout le reste, et ce n'est pas
 * negociable : ContractSystem doit lire les competences et `Finances`, donc
 * passer apres PlayerDevelopmentSystem et FinanceSystem ; SquadSystem doit
 * ecrire `SquadMembership` avant TrainingSystem et MatchSystem, qui le lisent.
 * D'ou la decision en queue de pipeline et son application en tete au tick
 * suivant (docs/13- §2, canal 2 - voir le docblock de
 * `Football\Events\ContractSigned`).
 *
 * Extrait de Metrics\Sampler pour que Sampler et Simulation\StepRunner ne
 * dupliquent jamais cette liste - une divergence entre les deux serait
 * silencieuse. Reste a synchroniser a la main avec
 * `Football\PipelineInvariantsTest`, seule duplication residuelle.
 */
final class PipelineFactory
{
    public static function build(): Pipeline
    {
        return new Pipeline([
            new FacilitiesSystem(),
            new YouthIntakeSystem(),
            new SquadSystem(),
            new TrainingSystem(),
            new RetirementSystem(),
            new FinanceSystem(),
            new PlayerDevelopmentSystem(),
            new CalendarSystem(),
            new MatchSystem(),
            new CompetitionSystem(),
            new ContractSystem(),
        ]);
    }
}
