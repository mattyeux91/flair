<?php

declare(strict_types=1);

namespace Flair\Kernel\Football;

use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Pipeline\System;
use Flair\Kernel\Core\Pipeline\SystemContext;
use Flair\Kernel\Core\Ruleset\AgingBalance;
use Flair\Kernel\Core\Support\Rng;
use Flair\Kernel\Core\Support\SimDate;

/**
 * Le vieillissement (docs/15-roadmap.md §4, docs/14-algorithmes.md §2) :
 * purement periodique, aucun evenement ecoute. Pour chaque entite qui porte
 * Person+PlayerSkills+Potential, chaque tick :
 *
 * 1. Passe un age d'eligibilite (`Ruleset\AgingBalance::$retirementEligibleAge`),
 *    une probabilite de retraite croissante avec l'age et la fragilite est tiree. Si elle
 *    tombe : PlayerSkills/Potential sont retires (12- §1 - un archetype se
 *    change en retirant des composants, pas en detruisant l'entite), et un
 *    Fait PlayerRetired est emis (irreversible, 16- §2).
 * 2. Sinon, les 12 attributs progressent/declinent independamment via la
 *    meme formule qualitative (14- §2) : `base = f(ecart au plafond) ×
 *    g(age)`, delta multiplie par `Ruleset::$balance->developmentRate`, plus
 *    un bruit borne.
 *
 * Simplifications assumees, a corriger quand un systeme en aura besoin :
 * - `Potential::$ceiling` est partage par les 12 attributs (pas un plafond
 *   par attribut) ;
 * - le "bruit" de 14- §2 est remplace par un arrondi stochastique : un taux
 *   annuel (`growthRate × ecart × g(age)`) est converti en probabilite
 *   quotidienne d'un pas de ±1, tiree une fois par attribut et par tick.
 *   Necessaire pour eviter qu'un taux journalier fractionnaire (largement
 *   < 1 point/jour) ne s'arrondisse toujours a zero - et ca donne une
 *   progression irreguliere par a-coups plutot qu'une interpolation lisse,
 *   plus proche en esprit de la "queue epaisse" documentee que du bruit
 *   additif ;
 * - `f`/`g` sont un premier jet qualitatif (memes contraintes de forme que
 *   14- §2), a calibrer via le harness d'equilibrage (Phase 1) - leurs
 *   coefficients vivent dans `Ruleset\AgingBalance`, jamais en dur ici ;
 * - aucun modificateur d'entrainement/temps de jeu/moral (le `modif` de
 *   `Δskill = base × modif + bruit`) : le systeme entrainement n'existe pas
 *   encore. Rien ici ne l'anticipe autrement qu'en laissant `writes()` a ce
 *   seul systeme - entrainement devra composer differemment, pas modifier
 *   AgingSystem ;
 * - `growthPrimeAgeThreshold` (age d'entree en phase de progression
 *   maximale) est uniforme pour tous les joueurs d'un monde, alors que
 *   `Potential::$peakAge` (age de sortie du pic) est individuel. Pas de
 *   variance individuelle de type "eclosion precoce/tardive" - une
 *   simplification, pas une verite de conception. Le jour ou elle compte,
 *   ce serait un champ individuel dans `Potential` (ex. `primeAgeOffset`),
 *   jamais un parametre de `Ruleset` - la meme distinction verite
 *   cachee/composant vs levier de monde/Ruleset qui separe deja `peakAge`
 *   de `growthPrimeAgeThreshold`.
 */
final class AgingSystem implements System
{
    private const MIN_SKILL = 1;
    private const MAX_SKILL = 99;

    public function id(): string
    {
        return 'aging';
    }

    /** @return list<class-string> */
    public function reads(): array
    {
        return [Person::class, PlayerSkills::class, Potential::class];
    }

    /** @return list<class-string> */
    public function writes(): array
    {
        return [PlayerSkills::class, Potential::class];
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
        $aging = $ctx->ruleset()->balance->aging;

        foreach ($ctx->components(PlayerSkills::class)->entities() as $entityId) {
            $person = $ctx->components(Person::class)->get($entityId);
            $potential = $ctx->components(Potential::class)->get($entityId);

            if ($person === null || $potential === null) {
                continue;
            }

            $ageYears = $now->yearsSince($person->birthDate);
            $rng = $ctx->rng($entityId);

            if ($ageYears >= $aging->retirementEligibleAge && $this->retires($ageYears, $potential->fragility, $aging, $rng)) {
                $ctx->components(PlayerSkills::class)->remove($entityId);
                $ctx->components(Potential::class)->remove($entityId);
                $ctx->emit(new PlayerRetired($entityId, (int) $ageYears), entityId: $entityId);

                continue;
            }

            $skills = $ctx->components(PlayerSkills::class)->get($entityId);

            if ($skills === null) {
                continue;
            }

            $ctx->components(PlayerSkills::class)->set(
                $entityId,
                $this->grow($skills, $potential, $ageYears, $ctx->ruleset()->balance->developmentRate, $aging, $rng),
            );
        }
    }

    private function retires(float $ageYears, float $fragility, AgingBalance $aging, Rng $rng): bool
    {
        $yearsPastEligible = $ageYears - $aging->retirementEligibleAge;
        $annualChance = min(1.0, $yearsPastEligible * $aging->retirementAgeWeight + $fragility * $aging->retirementFragilityWeight);
        $dailyChance = $annualChance / 365.0;
        $roll = $rng->nextUint32() % 10_000;

        return $roll < (int) ($dailyChance * 10_000);
    }

    private function grow(
        PlayerSkills $skills,
        Potential $potential,
        float $ageYears,
        float $developmentRate,
        AgingBalance $aging,
        Rng $rng,
    ): PlayerSkills {
        $ageFactor = $this->ageFactor($ageYears, $potential->peakAge, $aging);

        return new PlayerSkills(
            technique: $this->nextValue($skills->technique, $potential, $ageFactor, $developmentRate, $aging, $rng),
            passing: $this->nextValue($skills->passing, $potential, $ageFactor, $developmentRate, $aging, $rng),
            finishing: $this->nextValue($skills->finishing, $potential, $ageFactor, $developmentRate, $aging, $rng),
            pace: $this->nextValue($skills->pace, $potential, $ageFactor, $developmentRate, $aging, $rng),
            stamina: $this->nextValue($skills->stamina, $potential, $ageFactor, $developmentRate, $aging, $rng),
            strength: $this->nextValue($skills->strength, $potential, $ageFactor, $developmentRate, $aging, $rng),
            defending: $this->nextValue($skills->defending, $potential, $ageFactor, $developmentRate, $aging, $rng),
            positioning: $this->nextValue($skills->positioning, $potential, $ageFactor, $developmentRate, $aging, $rng),
            vision: $this->nextValue($skills->vision, $potential, $ageFactor, $developmentRate, $aging, $rng),
            composure: $this->nextValue($skills->composure, $potential, $ageFactor, $developmentRate, $aging, $rng),
            leadership: $this->nextValue($skills->leadership, $potential, $ageFactor, $developmentRate, $aging, $rng),
            discipline: $this->nextValue($skills->discipline, $potential, $ageFactor, $developmentRate, $aging, $rng),
        );
    }

    /**
     * g(age) : forte avant `growthPrimeAgeThreshold`, plate jusqu'au pic,
     * negative apres (docs/14- §2). Premier jet qualitatif, a calibrer en
     * Phase 1 via `Ruleset\AgingBalance`.
     */
    private function ageFactor(float $ageYears, int $peakAge, AgingBalance $aging): float
    {
        if ($ageYears < $aging->growthPrimeAgeThreshold) {
            return 1.0;
        }

        if ($ageYears < $peakAge) {
            return $aging->growthPlateauFactor;
        }

        return -$aging->declineRatePerYear * ($ageYears - $peakAge);
    }

    private function nextValue(
        int $current,
        Potential $potential,
        float $ageFactor,
        float $developmentRate,
        AgingBalance $aging,
        Rng $rng,
    ): int {
        $gap = $potential->ceiling - $current;
        $annualDelta = $developmentRate * ($ageFactor >= 0.0
            ? $potential->growthRate * $gap * $ageFactor
            : $ageFactor * $potential->fragility * $aging->fragilityDeclineMultiplier);

        $dailyChance = min(1.0, abs($annualDelta) / 365.0);
        $roll = $rng->nextUint32() % 10_000;

        if ($roll >= (int) ($dailyChance * 10_000)) {
            return $current;
        }

        $step = $annualDelta >= 0.0 ? 1 : -1;

        return max(self::MIN_SKILL, min(self::MAX_SKILL, $current + $step));
    }
}
