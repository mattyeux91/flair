<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Generation;

use Flair\Kernel\Core\Ruleset\PositionBalance;
use Flair\Kernel\Core\Ruleset\YouthIntakeBalance;
use Flair\Kernel\Core\Support\Rng;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerPotentials;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Components\Position;
use Flair\Kernel\Football\Support\AttributeCeilings;
use Flair\Kernel\Football\Support\PositionModel;

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
 * ## Le poste fait les competences - a la naissance, et seulement la
 *
 * Un joueur recoit un **archetype** (`Football\Components\Position`) tire selon
 * `PositionBalance`, et ses competences de depart en decoulent : elles partent
 * d'une fraction du plafond **de chaque attribut**
 * (`Football\Support\PositionModel::ceilings()`), pas d'une valeur unique par
 * categorie. Un gardien demarre donc fort en `reflexes` et faible en
 * `finishing`.
 *
 * Cette causalite est obligatoire **a la generation**, et elle est
 * arithmetique et non esthetique : seize tirages independants se concentrent
 * autour de leur moyenne et ne produisent jamais de specialiste. Il faut un
 * archetype pour imposer la correlation. Ensuite la causalite s'inverse - le
 * poste **joue** se derive des competences du moment
 * (`PositionModel::bestPosition()`) et n'est jamais stocke.
 *
 * L'archetype ne fait pas que dessiner le depart : il fixe surtout les
 * **plafonds**, donc le profil tient toute la carriere. Sans ca,
 * `PlayerDevelopmentSystem` le dissoudrait en une dizaine d'annees simulees.
 */
final class PlayerFactory
{
    /**
     * Un joueur neuf pret a integrer un effectif professionnel : potentiel
     * tire selon la loi de talent, competences a une fraction du `ceiling`
     * (`YouthIntakeBalance::$startingSkillRatio`) - loin du potentiel, c'est
     * PlayerDevelopmentSystem qui l'y amenera.
     */
    public function drawRookie(
        Rng $rng,
        string $name,
        SimDate $birthDate,
        YouthIntakeBalance $balance,
        PositionBalance $positions,
        ?Position $archetype = null,
    ): PlayerBlueprint {
        $potentials = $this->drawPotentials($rng, $balance, $positions, $archetype);
        $ceilings = $potentials->ceilings;

        return new PlayerBlueprint(
            person: new Person($name, $birthDate),
            potentials: $potentials,
            physical: $this->drawPhysical($rng, $ceilings, $balance),
            technical: $this->drawTechnical($rng, $ceilings, $balance),
            mental: $this->drawMental($rng, $ceilings, $balance),
        );
    }

    /**
     * La loi de talent proprement dite, exposee seule parce que la
     * population initiale en a besoin sans le reste : un joueur de 28 ans
     * genere au demarrage du monde partage la meme distribution de
     * potentiel qu'une recrue, mais surement pas ses competences de debutant
     * (il a deja vecu dix ans de progression). C'est l'appelant qui decide
     * du niveau de competence correspondant a l'age qu'il simule.
     *
     * Rend le couple complet **niveau + forme** : le `ceiling` seul ne suffit
     * plus a decrire un potentiel depuis que l'archetype existe, et un
     * appelant qui n'obtiendrait que le niveau produirait des joueurs sans
     * profil.
     *
     * `$archetype` permet a l'appelant de l'imposer plutot que de le tirer.
     * C'est ce dont la generation du monde initial a besoin : un tirage
     * independant par joueur laisse, par pur hasard, des clubs entiers sans
     * gardien - avec une trentaine de joueurs par club et une part de gardiens
     * a 10 %, environ un club sur dix-huit. Un monde ne doit pas **naitre**
     * infirme ; les promotions annuelles, elles, tirent bien au hasard.
     */
    public function drawPotentials(
        Rng $rng,
        YouthIntakeBalance $balance,
        PositionBalance $positions,
        ?Position $archetype = null,
    ): PlayerPotentials {
        $ceiling = $this->drawTalent($rng, $balance);
        $shape = $archetype ?? $this->drawArchetype($rng, $positions);

        return new PlayerPotentials(
            ceiling: $ceiling,
            archetype: $shape,
            ceilings: PositionModel::ceilings($ceiling, $shape, spread: $this->drawSpread($rng, $shape, $positions), balance: $positions),
            physicalPeakAge: $this->uniformInt($rng, $balance->physicalPeakAgeMin, $balance->physicalPeakAgeMax),
            technicalPeakAge: $this->uniformInt($rng, $balance->technicalPeakAgeMin, $balance->technicalPeakAgeMax),
            mentalPeakAge: $this->uniformInt($rng, $balance->mentalPeakAgeMin, $balance->mentalPeakAgeMax),
            growthRate: $this->uniform($rng, $balance->growthRateMin, $balance->growthRateMax),
            fragility: $this->uniform($rng, $balance->fragilityMin, $balance->fragilityMax),
        );
    }

    /**
     * La repartition du talent entre les attributs du profil : un facteur par
     * attribut, tire uniformement dans `[1 - profileSpread, 1 + profileSpread]`
     * puis **normalise** pour que la contrainte de budget tienne.
     *
     * C'est la normalisation qui en fait un arbitrage plutot qu'un cadeau :
     * sans elle, un joueur chanceux serait meilleur partout a la fois. Avec
     * elle, un plafond de passe au-dessus de la moyenne se paie sur le tacle,
     * et deux milieux de meme potentiel cessent d'etre le meme joueur.
     *
     * Les attributs hors profil ne sont pas tires : ils ne comptent dans aucune
     * note, donc les disperser n'ajouterait que du bruit invisible.
     *
     * @return array<string, float>
     */
    private function drawSpread(Rng $rng, Position $archetype, PositionBalance $positions): array
    {
        $raw = [];

        foreach (PositionModel::weights($archetype) as $attribute => $_weight) {
            $raw[$attribute] = $this->uniform($rng, 1.0 - $positions->profileSpread, 1.0 + $positions->profileSpread);
        }

        return PositionModel::normalizeSpread($archetype, $raw);
    }

    /**
     * L'archetype, tire selon les parts de `PositionBalance` par inversion de
     * la fonction de repartition - un seul tirage, borne, comme le choix de
     * score de `PoissonMatchEngine`.
     *
     * Le dernier poste absorbe le reliquat plutot que de faire confiance aux
     * parts pour sommer exactement a 1 : un `Ruleset` mal rempli produit alors
     * une population legerement deformee, jamais une exception au milieu d'un
     * run de mille saisons - meme choix defensif que le clamp de `meritShare`
     * dans `Football\FinanceSystem`.
     */
    private function drawArchetype(Rng $rng, PositionBalance $positions): Position
    {
        $target = $this->unitInterval($rng);
        $cumulative = 0.0;

        foreach (Position::cases() as $position) {
            $cumulative += PositionModel::generationShare($position, $positions);

            if ($target < $cumulative) {
                return $position;
            }
        }

        return Position::Attacker;
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

    private function drawPhysical(Rng $rng, AttributeCeilings $ceilings, YouthIntakeBalance $balance): PlayerPhysicalSkills
    {
        return new PlayerPhysicalSkills(
            pace: $this->startingValue($rng, $ceilings->pace, $balance),
            stamina: $this->startingValue($rng, $ceilings->stamina, $balance),
            strength: $this->startingValue($rng, $ceilings->strength, $balance),
            reflexes: $this->startingValue($rng, $ceilings->reflexes, $balance),
        );
    }

    private function drawTechnical(Rng $rng, AttributeCeilings $ceilings, YouthIntakeBalance $balance): PlayerTechnicalSkills
    {
        return new PlayerTechnicalSkills(
            technique: $this->startingValue($rng, $ceilings->technique, $balance),
            passing: $this->startingValue($rng, $ceilings->passing, $balance),
            finishing: $this->startingValue($rng, $ceilings->finishing, $balance),
            defending: $this->startingValue($rng, $ceilings->defending, $balance),
            positioning: $this->startingValue($rng, $ceilings->positioning, $balance),
            handling: $this->startingValue($rng, $ceilings->handling, $balance),
            distribution: $this->startingValue($rng, $ceilings->distribution, $balance),
        );
    }

    private function drawMental(Rng $rng, AttributeCeilings $ceilings, YouthIntakeBalance $balance): PlayerMentalSkills
    {
        return new PlayerMentalSkills(
            vision: $this->startingValue($rng, $ceilings->vision, $balance),
            composure: $this->startingValue($rng, $ceilings->composure, $balance),
            leadership: $this->startingValue($rng, $ceilings->leadership, $balance),
            discipline: $this->startingValue($rng, $ceilings->discipline, $balance),
            command: $this->startingValue($rng, $ceilings->command, $balance),
        );
    }

    /**
     * Le niveau de depart d'**un** attribut : une fraction de son propre
     * plafond (`startingSkillRatio`), ecartee par un bruit borne, en restant
     * dans l'echelle 1-100 des competences (docs/12- §5).
     *
     * Un tirage par attribut, et non plus un par categorie partage entre tous
     * ses attributs. C'est le plafond par attribut qui porte le profil - un
     * gardien demarre bas en `finishing` parce que son plafond de finition est
     * bas, pas parce qu'on l'aurait bruite vers le bas.
     */
    private function startingValue(Rng $rng, int $ceiling, YouthIntakeBalance $balance): int
    {
        $baseline = (int) round($ceiling * $balance->startingSkillRatio);
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
