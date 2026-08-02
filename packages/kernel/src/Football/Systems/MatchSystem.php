<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Systems;

use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Pipeline\System;
use Flair\Kernel\Core\Pipeline\SystemContext;
use Flair\Kernel\Football\Components\MatchResult;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Components\SquadMembership;
use Flair\Kernel\Football\Events\FixtureKickoff;
use Flair\Kernel\Football\Events\MatchPlayed;
use Flair\Kernel\Football\Generation\PoissonMatchEngine;

/**
 * Joue les matchs du jour (docs/15- §4) : purement reactif, aucune logique
 * periodique - un match n'existe que parce qu'un `FixtureKickoff` arrive a
 * echeance (docs/13- §3).
 *
 * ## Force de club : agregat de l'effectif, pas de `Position`
 *
 * Decision deja arretee avant ce lot (`CLAUDE.md`) : pas de selection d'onze
 * ni de `Position`, la force d'un club est un agregat de tout son effectif.
 * Pour donner malgre tout un attaque/defense distincts a Dixon-Coles
 * (docs/14- §1), le rating attaque moyenne les competences a connotation
 * offensive (`finishing`/`passing`/`technique`/`pace`) et le rating defense
 * celles a connotation defensive (`defending`/`positioning`/`strength`/
 * `reflexes`) - un split par categorie de competence plutot que par poste,
 * seule approximation disponible avec les composants existants. A revisiter
 * le jour ou `Position` existe.
 *
 * Un club sans le moindre joueur (effectif vide) recoit un rating neutre
 * (50.0, le milieu de l'echelle 1-100) plutot que d'etre exclu - meme
 * defaut neutre que `Facilities` absente dans `TrainingSystem`/
 * `YouthIntakeSystem`.
 *
 * ## `MatchResult` en plus de `MatchPlayed`
 *
 * Ecrit `MatchResult` sur l'entite fixture (canal 1 - `Football\CompetitionSystem`,
 * declare juste apres dans le pipeline, doit alimenter le classement le
 * jour meme, docs/13- §2) **et** emet le Fait `MatchPlayed` (canal 2 - tout
 * consommateur qui n'a pas besoin d'une resolution le jour meme, comme un
 * futur digest narratif, docs/14- §9).
 */
final class MatchSystem implements System
{
    private const NEUTRAL_RATING = 50.0;

    public function __construct(
        private readonly PoissonMatchEngine $engine = new PoissonMatchEngine(),
    ) {
    }

    public function id(): string
    {
        return 'match';
    }

    /** @return list<class-string> */
    public function reads(): array
    {
        return [
            SquadMembership::class,
            PlayerPhysicalSkills::class,
            PlayerTechnicalSkills::class,
        ];
    }

    /** @return list<class-string> */
    public function writes(): array
    {
        return [
            MatchResult::class,
        ];
    }

    /** @return list<class-string> */
    public function removes(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function creates(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function subscribesTo(): array
    {
        return [
            FixtureKickoff::class,
        ];
    }

    public function handle(DomainEvent $event, SystemContext $ctx): void
    {
        if (!$event instanceof FixtureKickoff) {
            return;
        }

        [$attackHome, $defenseHome] = $this->ratings($ctx, $event->homeClubId);
        [$attackAway, $defenseAway] = $this->ratings($ctx, $event->awayClubId);

        $rng = $ctx->rng($event->fixtureId);
        $score = $this->engine->play($rng, $attackHome, $defenseHome, $attackAway, $defenseAway, $ctx->ruleset()->balance->match);

        $ctx->components(MatchResult::class)->set($event->fixtureId, new MatchResult(
            $event->competitionId,
            $event->homeClubId,
            $event->awayClubId,
            $event->matchday,
            $score->homeGoals,
            $score->awayGoals,
        ));

        $ctx->emit(new MatchPlayed(
            $event->fixtureId,
            $event->competitionId,
            $event->homeClubId,
            $event->awayClubId,
            $score->homeGoals,
            $score->awayGoals,
        ), entityId: $event->fixtureId);
    }

    public function update(SystemContext $ctx): void
    {
    }

    /** @return array{0: float, 1: float} [attackRating, defenseRating] */
    private function ratings(SystemContext $ctx, int $clubId): array
    {
        $attackSum = 0.0;
        $defenseSum = 0.0;
        $count = 0;

        foreach ($ctx->components(SquadMembership::class)->entities() as $playerId) {
            $membership = $ctx->components(SquadMembership::class)->get($playerId);

            if ($membership === null || $membership->clubId !== $clubId) {
                continue;
            }

            $physical = $ctx->components(PlayerPhysicalSkills::class)->get($playerId);
            $technical = $ctx->components(PlayerTechnicalSkills::class)->get($playerId);

            if ($physical === null || $technical === null) {
                continue;
            }

            $attackSum += ($technical->finishing + $technical->passing + $technical->technique + $physical->pace) / 4.0;
            $defenseSum += ($technical->defending + $technical->positioning + $physical->strength + $physical->reflexes) / 4.0;
            $count++;
        }

        if ($count === 0) {
            return [self::NEUTRAL_RATING, self::NEUTRAL_RATING];
        }

        return [$attackSum / $count, $defenseSum / $count];
    }
}
