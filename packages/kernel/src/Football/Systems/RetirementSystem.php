<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Systems;

use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Pipeline\System;
use Flair\Kernel\Core\Pipeline\SystemContext;
use Flair\Kernel\Core\Ruleset\RetirementBalance;
use Flair\Kernel\Core\Support\Rng;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\Components\Contract;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerPotentials;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Events\PlayerRetired;

/**
 * La retraite (docs/15-roadmap.md §4, docs/14-algorithmes.md §2) : purement
 * periodique, aucun evenement ecoute. Pour chaque entite qui porte
 * Person+PlayerPotentials, chaque tick : au-dela d'un age d'eligibilite
 * (`Ruleset\RetirementBalance::$retirementEligibleAge`), une probabilite de
 * retraite croissante avec l'age et la fragilite est tiree. Si elle tombe :
 * les trois composants de competences
 * (`PlayerPhysicalSkills`/`PlayerTechnicalSkills`/`PlayerMentalSkills`) et
 * `PlayerPotentials` sont retires (12- §1 - un archetype se change en
 * retirant des composants, pas en detruisant l'entite), et un Fait
 * `PlayerRetired` est emis (irreversible, 16- §2). `Contract` est retire
 * avec eux (Phase 2) : un joueur retraite n'a plus d'obligation salariale,
 * meme si `SquadMembership` persiste (limite pre-existante, hors perimetre
 * de ce lot) - `Football\FinanceSystem` s'appuie sur cette absence pour
 * arreter de le payer, meme convention "skip via null-check" que
 * `TrainingSystem`.
 *
 * Seul systeme qui `remove()` ces cinq composants : distinct de
 * `PlayerDevelopmentSystem`, qui les `set()` mais ne les retire jamais.
 * Cette separation evite qu'un meme systeme cumule deux responsabilites
 * non liees (SRP) et rend l'invariant "un seul remover par composant"
 * verifiable mecaniquement (`Football\PipelineInvariantsTest`).
 */
final class RetirementSystem implements System
{
    public function id(): string
    {
        return 'retirement';
    }

    /** @return list<class-string> */
    public function reads(): array
    {
        return [
            Person::class,
            PlayerPotentials::class,
        ];
    }

    /** @return list<class-string> */
    public function writes(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function removes(): array
    {
        return [
            PlayerPotentials::class,
            PlayerPhysicalSkills::class,
            PlayerTechnicalSkills::class,
            PlayerMentalSkills::class,
            Contract::class,
        ];
    }

    /** @return list<class-string> */
    public function creates(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function subscribesTo(): array
    {
        return [];
    }

    public function handle(DomainEvent $event, SystemContext $ctx): void
    {
    }

    public function update(SystemContext $ctx): void
    {
        $now = new SimDate($ctx->tick);
        $retirement = $ctx->ruleset()->balance->retirement;

        foreach ($ctx->components(PlayerPotentials::class)->entities() as $entityId) {
            $person = $ctx->components(Person::class)->get($entityId);
            $potential = $ctx->components(PlayerPotentials::class)->get($entityId);

            if ($person === null || $potential === null) {
                continue;
            }

            $ageYears = $now->yearsSince($person->birthDate);
            $rng = $ctx->rng($entityId);

            if ($ageYears >= $retirement->retirementEligibleAge && $this->retires($ageYears, $potential->fragility, $retirement, $rng)) {
                $ctx->components(PlayerPotentials::class)->remove($entityId);
                $ctx->components(PlayerPhysicalSkills::class)->remove($entityId);
                $ctx->components(PlayerTechnicalSkills::class)->remove($entityId);
                $ctx->components(PlayerMentalSkills::class)->remove($entityId);
                $ctx->components(Contract::class)->remove($entityId);
                $ctx->emit(new PlayerRetired($entityId, (int) $ageYears), entityId: $entityId);
            }
        }
    }

    private function retires(float $ageYears, float $fragility, RetirementBalance $retirement, Rng $rng): bool
    {
        $yearsPastEligible = $ageYears - $retirement->retirementEligibleAge;
        $annualChance = min(1.0, $yearsPastEligible * $retirement->retirementAgeWeight + $fragility * $retirement->retirementFragilityWeight);
        $dailyChance = $annualChance / 365.0;
        $roll = $rng->nextUint32() % 10_000;

        return $roll < (int) ($dailyChance * 10_000);
    }
}
