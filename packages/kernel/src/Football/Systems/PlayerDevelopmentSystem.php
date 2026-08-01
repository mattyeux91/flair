<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Systems;

use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Pipeline\System;
use Flair\Kernel\Core\Pipeline\SystemContext;
use Flair\Kernel\Core\Ruleset\PlayerDevelopmentBalance;
use Flair\Kernel\Core\Support\Rng;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerPotentials;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Components\TrainingEffect;

/**
 * La progression et le declin des competences (docs/15-roadmap.md §4,
 * docs/14-algorithmes.md §2) : purement periodique, aucun evenement
 * ecoute. Pour chaque entite qui porte Person+PlayerPotentials (+ ses
 * trois composants de competences - `PlayerPhysicalSkills`/
 * `PlayerTechnicalSkills`/`PlayerMentalSkills`, tous portes par tout
 * joueur, gardien ou non, 12- §5), chaque tick, chaque attribut des trois
 * categories progresse/decline independamment via la meme formule
 * qualitative (14- §2) : `base = f(ecart au plafond) × g(age)`, delta
 * multiplie par `Ruleset::$balance->developmentRate`, plus un bruit
 * borne. Chaque categorie a **son propre pic**
 * (`PlayerPotentials::$physicalPeakAge`/`$technicalPeakAge`/
 * `$mentalPeakAge`, individuels) et **sa propre pente de declin post-pic**
 * (`PlayerDevelopmentBalance::$physicalDeclineMultiplier` et consorts, globaux) - le
 * physique culmine et decline avant le mental, a talent egal.
 *
 * **Seul writer** (`set()`) des trois composants de competences - la
 * retraite (retrait d'archetype via `remove()`, irreversible) est une
 * responsabilite distincte, portee par `RetirementSystem`. Les deux
 * systemes ne se disputent jamais le meme composant : `writes()` couvre
 * les mutations de valeur, `removes()` les retraits, verifie
 * mecaniquement par `Football\PipelineInvariantsTest`.
 *
 * **`TrainingEffect`** : ecrit par `TrainingSystem` (qualite d'environnement
 * d'entrainement, `docs/14-` §2 - voir son docblock et celui de
 * `TrainingEffect` pour le detail), lu ici avec un defaut neutre (1.0)
 * quand absent (joueur sans club) - ouvert a l'extension sans modification
 * (OCP), aucun changement necessaire dans cette classe pour l'accueillir.
 * Le modificateur est deja borne `[0.5, 2.0]` par son producteur (docs/14-
 * §3 - un environnement ne doit jamais pouvoir annuler ni decupler le
 * potentiel) ; cette classe ne le re-clamp pas. Post-pic (`ageFactor < 0`),
 * le modificateur est applique par sa **reciproque** : un environnement de
 * qualite doit ralentir le declin, pas l'accelerer - `1/x` est une
 * bijection de `[0.5, 2.0]` sur lui-meme (`1/0.5 = 2.0`, `1/2.0 = 0.5`),
 * donc le resultat reste borne sans re-clamp.
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
 *   coefficients vivent dans `Ruleset\PlayerDevelopmentBalance`, jamais en dur ici ;
 * - `growthPrimeAgeThreshold` (age d'entree en phase de progression
 *   maximale) est uniforme pour tous les joueurs et toutes les categories,
 *   alors que l'age de pic (sortie de cette phase) est individuel et
 *   distinct par categorie. Pas de variance individuelle de type "eclosion
 *   precoce/tardive" sur l'entree en formation - une simplification, pas
 *   une verite de conception.
 */
final class PlayerDevelopmentSystem implements System
{
    private const MIN_SKILL = 1;
    private const MAX_SKILL = 99;

    public function id(): string
    {
        return 'player-development';
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
            TrainingEffect::class,
        ];
    }

    /** @return list<class-string> */
    public function writes(): array
    {
        return [
            PlayerPhysicalSkills::class,
            PlayerTechnicalSkills::class,
            PlayerMentalSkills::class,
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
        return [];
    }

    public function handle(DomainEvent $event, SystemContext $ctx): void
    {
    }

    public function update(SystemContext $ctx): void
    {
        $now = new SimDate($ctx->tick);
        $development = $ctx->ruleset()->balance->playerDevelopment;
        $developmentRate = $ctx->ruleset()->balance->developmentRate;

        foreach ($ctx->components(PlayerPotentials::class)->entities() as $entityId) {
            $person = $ctx->components(Person::class)->get($entityId);
            $potential = $ctx->components(PlayerPotentials::class)->get($entityId);

            if ($person === null || $potential === null) {
                continue;
            }

            $ageYears = $now->yearsSince($person->birthDate);
            $rng = $ctx->rng($entityId);

            $trainingEffect = $ctx->components(TrainingEffect::class)->get($entityId);
            $quality = $trainingEffect === null ? 1.0 : $trainingEffect->quality;

            $physicalAgeFactor = $this->ageFactor($ageYears, $potential->physicalPeakAge, $development);
            $technicalAgeFactor = $this->ageFactor($ageYears, $potential->technicalPeakAge, $development);
            $mentalAgeFactor = $this->ageFactor($ageYears, $potential->mentalPeakAge, $development);

            $physical = $ctx->components(PlayerPhysicalSkills::class)->get($entityId);
            if ($physical !== null) {
                $ctx->components(PlayerPhysicalSkills::class)->set($entityId, new PlayerPhysicalSkills(
                    pace: $this->nextValue($physical->pace, $potential, $physicalAgeFactor, $developmentRate, $development->physicalDeclineMultiplier, $quality, $rng),
                    stamina: $this->nextValue($physical->stamina, $potential, $physicalAgeFactor, $developmentRate, $development->physicalDeclineMultiplier, $quality, $rng),
                    strength: $this->nextValue($physical->strength, $potential, $physicalAgeFactor, $developmentRate, $development->physicalDeclineMultiplier, $quality, $rng),
                    reflexes: $this->nextValue($physical->reflexes, $potential, $physicalAgeFactor, $developmentRate, $development->physicalDeclineMultiplier, $quality, $rng),
                ));
            }

            $technical = $ctx->components(PlayerTechnicalSkills::class)->get($entityId);
            if ($technical !== null) {
                $ctx->components(PlayerTechnicalSkills::class)->set($entityId, new PlayerTechnicalSkills(
                    technique: $this->nextValue($technical->technique, $potential, $technicalAgeFactor, $developmentRate, $development->technicalDeclineMultiplier, $quality, $rng),
                    passing: $this->nextValue($technical->passing, $potential, $technicalAgeFactor, $developmentRate, $development->technicalDeclineMultiplier, $quality, $rng),
                    finishing: $this->nextValue($technical->finishing, $potential, $technicalAgeFactor, $developmentRate, $development->technicalDeclineMultiplier, $quality, $rng),
                    defending: $this->nextValue($technical->defending, $potential, $technicalAgeFactor, $developmentRate, $development->technicalDeclineMultiplier, $quality, $rng),
                    positioning: $this->nextValue($technical->positioning, $potential, $technicalAgeFactor, $developmentRate, $development->technicalDeclineMultiplier, $quality, $rng),
                    handling: $this->nextValue($technical->handling, $potential, $technicalAgeFactor, $developmentRate, $development->technicalDeclineMultiplier, $quality, $rng),
                    distribution: $this->nextValue($technical->distribution, $potential, $technicalAgeFactor, $developmentRate, $development->technicalDeclineMultiplier, $quality, $rng),
                ));
            }

            $mental = $ctx->components(PlayerMentalSkills::class)->get($entityId);
            if ($mental !== null) {
                $ctx->components(PlayerMentalSkills::class)->set($entityId, new PlayerMentalSkills(
                    vision: $this->nextValue($mental->vision, $potential, $mentalAgeFactor, $developmentRate, $development->mentalDeclineMultiplier, $quality, $rng),
                    composure: $this->nextValue($mental->composure, $potential, $mentalAgeFactor, $developmentRate, $development->mentalDeclineMultiplier, $quality, $rng),
                    leadership: $this->nextValue($mental->leadership, $potential, $mentalAgeFactor, $developmentRate, $development->mentalDeclineMultiplier, $quality, $rng),
                    discipline: $this->nextValue($mental->discipline, $potential, $mentalAgeFactor, $developmentRate, $development->mentalDeclineMultiplier, $quality, $rng),
                    command: $this->nextValue($mental->command, $potential, $mentalAgeFactor, $developmentRate, $development->mentalDeclineMultiplier, $quality, $rng),
                ));
            }
        }
    }

    /**
     * g(age) : forte avant `growthPrimeAgeThreshold`, plate jusqu'au pic de
     * la categorie, negative apres (docs/14- §2). Premier jet qualitatif, a
     * calibrer en Phase 1 via `Ruleset\PlayerDevelopmentBalance`.
     */
    private function ageFactor(float $ageYears, int $peakAge, PlayerDevelopmentBalance $development): float
    {
        if ($ageYears < $development->growthPrimeAgeThreshold) {
            return 1.0;
        }

        if ($ageYears < $peakAge) {
            return $development->growthPlateauFactor;
        }

        return -$development->declineRatePerYear * ($ageYears - $peakAge);
    }

    private function nextValue(
        int $current,
        PlayerPotentials $potential,
        float $ageFactor,
        float $developmentRate,
        float $declineMultiplier,
        float $quality,
        Rng $rng,
    ): int {
        $gap = $potential->ceiling - $current;
        $effectiveModifier = $ageFactor >= 0.0 ? $quality : 1.0 / $quality;
        $annualDelta = $developmentRate * $effectiveModifier * ($ageFactor >= 0.0
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
