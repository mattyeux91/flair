<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Generation;

use Flair\Kernel\Core\Ruleset\YouthIntakeBalance;
use Flair\Kernel\Core\Support\Rng;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerPotentials;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;

/**
 * La loi de talent du monde, en un seul endroit (docs/12- §7).
 *
 * ## Pourquoi une classe partagee, et pas un tirage par appelant
 *
 * Deux producteurs de joueurs coexistent : `YouthIntakeSystem` (les
 * promotions, chaque saison) et la population initiale (harness
 * aujourd'hui, `worldgen` demain). **S'ils ne tirent pas le talent selon la
 * meme loi, la pyramide des ages ne peut pas etre stationnaire** : le monde
 * converge mecaniquement vers la distribution de l'intake, quelle qu'elle
 * soit, et le critere de sortie de la Phase 0 (docs/15- §4) devient
 * ininterpretable. La duplication ne serait donc pas seulement du code en
 * double, elle serait une divergence silencieuse du modele.
 *
 * Pure : aucun acces au monde, aucun effet de bord, aucune fonction
 * transcendante (cf. `YouthIntakeBalance::$talentSkew` pour le detail de ce
 * dernier point, qui contraint la forme de la loi de talent). Rend un
 * `PlayerBlueprint` que l'appelant ecrit lui-meme.
 *
 * Simplification assumee, heritee du premier jet du harness : a l'interieur
 * d'une categorie, tous les attributs demarrent a la meme valeur (un
 * `pace` et un `strength` egaux au depart). Les attributs ne divergent
 * qu'ensuite, sous les tirages independants de PlayerDevelopmentSystem. A
 * corriger le jour ou les postes existeront - c'est le poste qui justifie
 * qu'un joueur demarre fort en `finishing` et faible en `defending`, et il
 * n'existe pas encore.
 */
final class PlayerFactory
{
    /**
     * Un joueur neuf pret a integrer un effectif professionnel : potentiel
     * tire selon la loi de talent, competences a une fraction du `ceiling`
     * (`YouthIntakeBalance::$startingSkillRatio`) - loin du potentiel, c'est
     * PlayerDevelopmentSystem qui l'y amenera.
     */
    public function drawRookie(Rng $rng, string $name, SimDate $birthDate, YouthIntakeBalance $balance): PlayerBlueprint
    {
        $potentials = $this->drawPotentials($rng, $balance);
        $baseline = (int) round($potentials->ceiling * $balance->startingSkillRatio);

        return new PlayerBlueprint(
            person: new Person($name, $birthDate),
            potentials: $potentials,
            physical: $this->drawPhysical($rng, $baseline, $balance),
            technical: $this->drawTechnical($rng, $baseline, $balance),
            mental: $this->drawMental($rng, $baseline, $balance),
        );
    }

    /**
     * La loi de talent proprement dite, exposee seule parce que la
     * population initiale en a besoin sans le reste : un joueur de 28 ans
     * genere au demarrage du monde partage la meme distribution de
     * potentiel qu'une recrue, mais surement pas ses competences de debutant
     * (il a deja vecu dix ans de progression). C'est l'appelant qui decide
     * du niveau de competence correspondant a l'age qu'il simule.
     */
    public function drawPotentials(Rng $rng, YouthIntakeBalance $balance): PlayerPotentials
    {
        return new PlayerPotentials(
            ceiling: $this->drawTalent($rng, $balance),
            physicalPeakAge: $this->uniformInt($rng, $balance->physicalPeakAgeMin, $balance->physicalPeakAgeMax),
            technicalPeakAge: $this->uniformInt($rng, $balance->technicalPeakAgeMin, $balance->technicalPeakAgeMax),
            mentalPeakAge: $this->uniformInt($rng, $balance->mentalPeakAgeMin, $balance->mentalPeakAgeMax),
            growthRate: $this->uniform($rng, $balance->growthRateMin, $balance->growthRateMax),
            fragility: $this->uniform($rng, $balance->fragilityMin, $balance->fragilityMax),
        );
    }

    /**
     * `min(U_1..U_k)`, soit une Beta(1, k) : beaucoup de joueurs proches de
     * `ceilingMin`, une longue queue de rares talents vers `ceilingMax`.
     * Substitut arithmetique de la log-normale demandee par docs/12- §7 -
     * voir `YouthIntakeBalance::$talentSkew` pour la justification complete.
     */
    private function drawTalent(Rng $rng, YouthIntakeBalance $balance): int
    {
        $lowest = 1.0;
        for ($i = 0; $i < max(1, $balance->talentSkew); $i++) {
            $lowest = min($lowest, $this->unitInterval($rng));
        }

        return (int) round($balance->ceilingMin + $lowest * ($balance->ceilingMax - $balance->ceilingMin));
    }

    private function drawPhysical(Rng $rng, int $baseline, YouthIntakeBalance $balance): PlayerPhysicalSkills
    {
        $value = $this->jitter($rng, $baseline, $balance);

        return new PlayerPhysicalSkills(
            pace: $value,
            stamina: $value,
            strength: $value,
            reflexes: $value,
        );
    }

    private function drawTechnical(Rng $rng, int $baseline, YouthIntakeBalance $balance): PlayerTechnicalSkills
    {
        $value = $this->jitter($rng, $baseline, $balance);

        return new PlayerTechnicalSkills(
            technique: $value,
            passing: $value,
            finishing: $value,
            defending: $value,
            positioning: $value,
            handling: $value,
            distribution: $value,
        );
    }

    private function drawMental(Rng $rng, int $baseline, YouthIntakeBalance $balance): PlayerMentalSkills
    {
        $value = $this->jitter($rng, $baseline, $balance);

        return new PlayerMentalSkills(
            vision: $value,
            composure: $value,
            leadership: $value,
            discipline: $value,
            command: $value,
        );
    }

    /** Ecarte le niveau de depart d'une categorie autour du niveau de reference, en restant dans l'echelle 1-100 des competences. */
    private function jitter(Rng $rng, int $baseline, YouthIntakeBalance $balance): int
    {
        $offset = $this->uniformInt($rng, -$balance->startingSkillJitter, $balance->startingSkillJitter);

        return max(1, min(100, $baseline + $offset));
    }

    private function unitInterval(Rng $rng): float
    {
        return $rng->nextUint32() / 0xFFFFFFFF;
    }

    private function uniform(Rng $rng, float $min, float $max): float
    {
        return $min + $this->unitInterval($rng) * ($max - $min);
    }

    private function uniformInt(Rng $rng, int $min, int $max): int
    {
        return (int) round($this->uniform($rng, (float) $min, (float) $max));
    }
}
