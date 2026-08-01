<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Systems;

use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Pipeline\System;
use Flair\Kernel\Core\Pipeline\SystemContext;
use Flair\Kernel\Core\Ruleset\AgingBalance;
use Flair\Kernel\Core\Support\Rng;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerPotentials;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Events\PlayerRetired;

/**
 * Le vieillissement (docs/15-roadmap.md §4, docs/14-algorithmes.md §2) :
 * purement periodique, aucun evenement ecoute. Pour chaque entite qui porte
 * Person+PlayerPotentials (+ ses trois composants de competences -
 * `PlayerPhysicalSkills`/`PlayerTechnicalSkills`/`PlayerMentalSkills`, tous
 * portes par tout joueur, gardien ou non, 12- §5), chaque tick :
 *
 * 1. Passe un age d'eligibilite (`Ruleset\AgingBalance::$retirementEligibleAge`),
 *    une probabilite de retraite croissante avec l'age et la fragilite est
 *    tiree. Si elle tombe : les trois composants de competences et
 *    PlayerPotentials sont retires (12- §1 - un archetype se change en
 *    retirant des composants, pas en detruisant l'entite), et un Fait
 *    PlayerRetired est emis (irreversible, 16- §2).
 * 2. Sinon, chaque attribut des trois categories progresse/decline
 *    independamment via la meme formule qualitative (14- §2) : `base =
 *    f(ecart au plafond) × g(age)`, delta multiplie par
 *    `Ruleset::$balance->developmentRate`, plus un bruit borne. Chaque
 *    categorie a **son propre pic** (`PlayerPotentials::$physicalPeakAge`/
 *    `$technicalPeakAge`/`$mentalPeakAge`, individuels) et **sa propre
 *    pente de declin post-pic** (`AgingBalance::$physicalDeclineMultiplier`
 *    et consorts, globaux) - le physique culmine et decline avant le
 *    mental, a talent egal.
 *
 * Les attributs de gardien (reflexes, captation, relance, autorite sur la
 * surface) ne forment **pas** une quatrieme categorie : ils sont repartis
 * dans les trois categories comportementales existantes selon leur nature
 * de vieillissement, pas leur domaine metier - `reflexes` est physique,
 * `handling`/`distribution` sont techniques, `command` est mental. Voir
 * les docblocks de `PlayerPhysicalSkills`/`PlayerTechnicalSkills`/
 * `PlayerMentalSkills`. Un joueur de champ appele a garder les buts joue
 * avec ces memes attributs (generalement bas) - pas d'archetype separe.
 *
 * Simplifications assumees, a corriger quand un systeme en aura besoin :
 * - `PlayerPotentials::$ceiling`/`$growthRate`/`$fragility` sont partages
 *   par les trois categories (seul l'age de pic est distinct par
 *   categorie, cf. ci-dessus) ;
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
 *   maximale) est uniforme pour tous les joueurs et toutes les categories,
 *   alors que l'age de pic (sortie de cette phase) est individuel et
 *   distinct par categorie. Pas de variance individuelle de type "eclosion
 *   precoce/tardive" sur l'entree en formation - une simplification, pas
 *   une verite de conception.
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
        return [
            Person::class,
            PlayerPotentials::class,
            PlayerPhysicalSkills::class,
            PlayerTechnicalSkills::class,
            PlayerMentalSkills::class,
        ];
    }

    /** @return list<class-string> */
    public function writes(): array
    {
        return [
            PlayerPotentials::class,
            PlayerPhysicalSkills::class,
            PlayerTechnicalSkills::class,
            PlayerMentalSkills::class,
        ];
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
        $developmentRate = $ctx->ruleset()->balance->developmentRate;

        foreach ($ctx->components(PlayerPotentials::class)->entities() as $entityId) {
            $person = $ctx->components(Person::class)->get($entityId);
            $potential = $ctx->components(PlayerPotentials::class)->get($entityId);

            if ($person === null || $potential === null) {
                continue;
            }

            $ageYears = $now->yearsSince($person->birthDate);
            $rng = $ctx->rng($entityId);

            if ($ageYears >= $aging->retirementEligibleAge && $this->retires($ageYears, $potential->fragility, $aging, $rng)) {
                $ctx->components(PlayerPotentials::class)->remove($entityId);
                $ctx->components(PlayerPhysicalSkills::class)->remove($entityId);
                $ctx->components(PlayerTechnicalSkills::class)->remove($entityId);
                $ctx->components(PlayerMentalSkills::class)->remove($entityId);
                $ctx->emit(new PlayerRetired($entityId, (int) $ageYears), entityId: $entityId);

                continue;
            }

            $physicalAgeFactor = $this->ageFactor($ageYears, $potential->physicalPeakAge, $aging);
            $technicalAgeFactor = $this->ageFactor($ageYears, $potential->technicalPeakAge, $aging);
            $mentalAgeFactor = $this->ageFactor($ageYears, $potential->mentalPeakAge, $aging);

            $physical = $ctx->components(PlayerPhysicalSkills::class)->get($entityId);
            if ($physical !== null) {
                $ctx->components(PlayerPhysicalSkills::class)->set($entityId, new PlayerPhysicalSkills(
                    pace: $this->nextValue($physical->pace, $potential, $physicalAgeFactor, $developmentRate, $aging->physicalDeclineMultiplier, $rng),
                    stamina: $this->nextValue($physical->stamina, $potential, $physicalAgeFactor, $developmentRate, $aging->physicalDeclineMultiplier, $rng),
                    strength: $this->nextValue($physical->strength, $potential, $physicalAgeFactor, $developmentRate, $aging->physicalDeclineMultiplier, $rng),
                    reflexes: $this->nextValue($physical->reflexes, $potential, $physicalAgeFactor, $developmentRate, $aging->physicalDeclineMultiplier, $rng),
                ));
            }

            $technical = $ctx->components(PlayerTechnicalSkills::class)->get($entityId);
            if ($technical !== null) {
                $ctx->components(PlayerTechnicalSkills::class)->set($entityId, new PlayerTechnicalSkills(
                    technique: $this->nextValue($technical->technique, $potential, $technicalAgeFactor, $developmentRate, $aging->technicalDeclineMultiplier, $rng),
                    passing: $this->nextValue($technical->passing, $potential, $technicalAgeFactor, $developmentRate, $aging->technicalDeclineMultiplier, $rng),
                    finishing: $this->nextValue($technical->finishing, $potential, $technicalAgeFactor, $developmentRate, $aging->technicalDeclineMultiplier, $rng),
                    defending: $this->nextValue($technical->defending, $potential, $technicalAgeFactor, $developmentRate, $aging->technicalDeclineMultiplier, $rng),
                    positioning: $this->nextValue($technical->positioning, $potential, $technicalAgeFactor, $developmentRate, $aging->technicalDeclineMultiplier, $rng),
                    handling: $this->nextValue($technical->handling, $potential, $technicalAgeFactor, $developmentRate, $aging->technicalDeclineMultiplier, $rng),
                    distribution: $this->nextValue($technical->distribution, $potential, $technicalAgeFactor, $developmentRate, $aging->technicalDeclineMultiplier, $rng),
                ));
            }

            $mental = $ctx->components(PlayerMentalSkills::class)->get($entityId);
            if ($mental !== null) {
                $ctx->components(PlayerMentalSkills::class)->set($entityId, new PlayerMentalSkills(
                    vision: $this->nextValue($mental->vision, $potential, $mentalAgeFactor, $developmentRate, $aging->mentalDeclineMultiplier, $rng),
                    composure: $this->nextValue($mental->composure, $potential, $mentalAgeFactor, $developmentRate, $aging->mentalDeclineMultiplier, $rng),
                    leadership: $this->nextValue($mental->leadership, $potential, $mentalAgeFactor, $developmentRate, $aging->mentalDeclineMultiplier, $rng),
                    discipline: $this->nextValue($mental->discipline, $potential, $mentalAgeFactor, $developmentRate, $aging->mentalDeclineMultiplier, $rng),
                    command: $this->nextValue($mental->command, $potential, $mentalAgeFactor, $developmentRate, $aging->mentalDeclineMultiplier, $rng),
                ));
            }
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

    /**
     * g(age) : forte avant `growthPrimeAgeThreshold`, plate jusqu'au pic de
     * la categorie, negative apres (docs/14- §2). Premier jet qualitatif, a
     * calibrer en Phase 1 via `Ruleset\AgingBalance`.
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
        PlayerPotentials $potential,
        float $ageFactor,
        float $developmentRate,
        float $declineMultiplier,
        Rng $rng,
    ): int {
        $gap = $potential->ceiling - $current;
        $annualDelta = $developmentRate * ($ageFactor >= 0.0
            ? $potential->growthRate * $gap * $ageFactor
            : $ageFactor * $potential->fragility * $declineMultiplier);

        $dailyChance = min(1.0, abs($annualDelta) / 365.0);
        $roll = $rng->nextUint32() % 10_000;

        if ($roll >= (int) ($dailyChance * 10_000)) {
            return $current;
        }

        $step = $annualDelta >= 0.0 ? 1 : -1;

        return max(self::MIN_SKILL, min(self::MAX_SKILL, $current + $step));
    }
}
