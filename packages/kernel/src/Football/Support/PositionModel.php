<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Support;

use Flair\Kernel\Core\Ruleset\PositionBalance;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Components\Position;

/**
 * Ce qu'un joueur vaut a chaque poste (docs/12-modele-du-monde.md §5,
 * docs/14- §1). Fonctions pures et statiques : aucun etat, aucun RNG, aucune
 * lecture du monde - c'est une formule, pas un systeme, meme forme que
 * `Football\Support\WageModel`.
 *
 * Quatre consommateurs reels et immediats, donc aucune abstraction par
 * anticipation : `Football\PlayerDevelopmentSystem` (les plafonds),
 * `Football\MatchSystem` (le onze et les notes d'equipe),
 * `Football\Support\WageModel` (la qualite d'un joueur) et
 * `Football\Generation\PlayerFactory` (le profil de depart).
 *
 * ## Le principe : deux causalites, deux moments
 *
 * > A la naissance, le poste fait les competences. Ensuite, les competences
 * > font le poste.
 *
 * - **A la generation**, la causalite va forcement du poste vers les
 *   competences, et c'est arithmetique et non esthetique : seize tirages
 *   independants se concentrent autour de leur moyenne et ne produisent jamais
 *   de specialiste. Il faut un archetype pour imposer la correlation.
 * - **Ensuite**, ce qu'un joueur *est* se lit dans ce qu'il sait faire.
 *   `bestPosition()` **derive** le poste joue des competences du moment ;
 *   aucune etiquette n'est stockee, elle deriverait de la realite sur vingt
 *   saisons. Meme principe que la perception (docs/12- §4).
 *
 * ## La matrice de contribution
 *
 * Precedent retenu : le modele de contribution de **Hattrick**, qui mappe
 * (poste, competence) vers un poids avec competence principale, secondaire et
 * tertiaire - et ou un gardien profite aussi de ses qualites defensives, un
 * defenseur de sa relance. **Ce n'est pas une partition etanche** : les postes
 * se recouvrent, c'est ce qui rend la polyvalence possible sans la stocker.
 * Croise avec les attributs-cles par poste de la litterature Football Manager.
 *
 * Les poids de chaque poste **somment a 1**, ce qui garde la note sur
 * l'echelle absolue 1-100 des competences (docs/12- §5, dont la semantique est
 * non negociable) et donne l'invariant central du modele : *un joueur
 * pleinement developpe note exactement son `ceiling` au poste de son
 * archetype*. C'est ce qui permet a la loi de talent de continuer a vouloir
 * dire quelque chose apres ce lot.
 *
 * Cette matrice n'est pas dans le `Ruleset` : elle **definit** ce qu'est un
 * poste, elle ne le calibre pas. Meme coupure que le moteur de match, dont la
 * formule de Dixon-Coles est du code et dont seuls les parametres vivent dans
 * `MatchBalance`. Voir `PositionBalance` pour ce qui reste reglable.
 *
 * ## Les trois attributs dormants
 *
 * `stamina`, `leadership` et `discipline` n'apparaissent dans aucun poste.
 * C'est **volontaire et documente** : la fatigue et les cartons relevent du
 * moteur L1 (docs/14- §1), rien ne peut les consommer avant. Ils gardent leur
 * plafond plein (cf. `PositionBalance::$offProfileCeilingRatio`) pour que le
 * monde ne soit pas atone sur ces axes le jour ou L1 arrivera.
 */
final class PositionModel
{
    /**
     * La note d'un joueur a un poste donne, sur l'echelle 1-100.
     *
     * C'est cette fonction, et elle seule, qui penalise un joueur hors de son
     * profil : un attaquant aligne dans les buts est note sur son `handling`
     * et ses `reflexes`, qui sont mauvais. **Aucun facteur d'affinite
     * supplementaire n'est applique** - ce serait un double comptage, et c'est
     * pourquoi aucun composant `PositionAffinity` n'existe.
     */
    public static function ratingAt(
        Position $position,
        PlayerPhysicalSkills $physical,
        PlayerTechnicalSkills $technical,
        PlayerMentalSkills $mental,
    ): float {
        $skills = self::attributes($physical, $technical, $mental);
        $rating = 0.0;

        foreach (self::weights($position) as $attribute => $weight) {
            $rating += $weight * $skills[$attribute];
        }

        return $rating;
    }

    /**
     * La matrice de contribution elle-meme : combien chaque attribut compte a
     * ce poste. Les poids somment a 1 ; un attribut absent de la table pese
     * zero, et "hors profil" ne veut rien dire d'autre que ca.
     *
     * **Source de verite unique.** La note d'un joueur et la forme de son
     * potentiel (`Football\Generation\PlayerFactory`) se derivent toutes deux
     * d'ici. Encoder le profil d'un poste a deux endroits - une fois dans une
     * formule, une fois dans un choix plein/rabaisse - laisserait les deux
     * diverger en silence et casserait l'invariant "un joueur pleinement
     * developpe note son ceiling a son poste" sans qu'aucun test ne bouge.
     *
     * @return non-empty-array<string, float>
     */
    public static function weights(Position $position): array
    {
        return match ($position) {
            Position::Goalkeeper => [
                'reflexes' => 0.30,
                'handling' => 0.20,
                'positioning' => 0.15,
                'command' => 0.15,
                'distribution' => 0.10,
                'composure' => 0.10,
            ],
            Position::Defender => [
                'defending' => 0.30,
                'positioning' => 0.20,
                'strength' => 0.20,
                'pace' => 0.15,
                'composure' => 0.15,
            ],
            Position::Midfielder => [
                'passing' => 0.25,
                'defending' => 0.20,
                'vision' => 0.20,
                'technique' => 0.20,
                'positioning' => 0.15,
            ],
            Position::Attacker => [
                'finishing' => 0.30,
                'pace' => 0.20,
                'composure' => 0.15,
                'technique' => 0.15,
                'positioning' => 0.10,
                'strength' => 0.10,
            ],
        };
    }

    /**
     * Les seize attributs a plat, sous les memes cles que `weights()` - la
     * coupure en trois composants suit le comportement de vieillissement
     * (docs/12- §5), qui ne concerne ni la note ni les plafonds.
     *
     * @return array<string, int>
     */
    public static function attributes(
        PlayerPhysicalSkills $physical,
        PlayerTechnicalSkills $technical,
        PlayerMentalSkills $mental,
    ): array {
        return [
            'pace' => $physical->pace,
            'stamina' => $physical->stamina,
            'strength' => $physical->strength,
            'reflexes' => $physical->reflexes,
            'technique' => $technical->technique,
            'passing' => $technical->passing,
            'finishing' => $technical->finishing,
            'defending' => $technical->defending,
            'positioning' => $technical->positioning,
            'handling' => $technical->handling,
            'distribution' => $technical->distribution,
            'vision' => $mental->vision,
            'composure' => $mental->composure,
            'leadership' => $mental->leadership,
            'discipline' => $mental->discipline,
            'command' => $mental->command,
        ];
    }

    /**
     * Les attributs qu'aucun poste ne consomme - dormants jusqu'au moteur L1
     * (docs/14- §1 : fatigue et cartons). Ils gardent un plafond plein : les
     * rabaisser les rendrait mauvais chez tout le monde, et le monde serait
     * atone sur ces axes le jour ou un systeme les lira.
     *
     * @return list<string>
     */
    public static function dormantAttributes(): array
    {
        return ['stamina', 'leadership', 'discipline'];
    }

    /**
     * Le poste ou ce joueur note le mieux - **le poste joue, derive, jamais
     * stocke**.
     *
     * Egalites departagees par l'ordre de declaration de `Position` (le
     * gardien d'abord) : un ordre total est obligatoire (docs/12- §2), et
     * celui-la est le seul disponible ici. En pratique deux notes flottantes
     * ne s'egalent que sur des competences identiques.
     */
    public static function bestPosition(
        PlayerPhysicalSkills $physical,
        PlayerTechnicalSkills $technical,
        PlayerMentalSkills $mental,
    ): Position {
        $best = Position::Goalkeeper;
        $bestRating = self::ratingAt($best, $physical, $technical, $mental);

        foreach (Position::cases() as $position) {
            $rating = self::ratingAt($position, $physical, $technical, $mental);

            if ($rating > $bestRating) {
                $best = $position;
                $bestRating = $rating;
            }
        }

        return $best;
    }

    /**
     * Ce que ce poste apporte a la note d'**attaque** de son equipe, et a
     * celle de **defense** (docs/14- §1 : Dixon-Coles attend les deux).
     *
     * Un gardien pese 0.30 en defense et rien en attaque : il compte enfin -
     * il ne comptait pas du tout jusqu'a ce lot - sans etre toute la defense.
     * Un attaquant est l'exact miroir. Defenseurs et milieux debordent des
     * deux cotes, ce qui evite qu'une equipe se resume a ses deux pointes.
     *
     * @return array{0: float, 1: float} [poids attaque, poids defense]
     */
    public static function sectorWeights(Position $position): array
    {
        return match ($position) {
            Position::Goalkeeper => [0.00, 0.30],
            Position::Defender => [0.10, 0.45],
            Position::Midfielder => [0.35, 0.25],
            Position::Attacker => [0.55, 0.00],
        };
    }

    /**
     * Le plafond de chacun des seize attributs, a partir d'une **repartition**
     * deja tiree (`$spread`, un facteur par attribut du profil).
     *
     * ## Un potentiel global qui plafonne une composition
     *
     * `ceiling` ne plafonne **pas** chaque competence separement : il plafonne
     * la note du joueur a son poste, et le joueur repartit son talent sous
     * cette contrainte.
     *
     * ```
     * Σ  poids(poste, attribut) × plafond(attribut)  =  ceiling
     * ```
     *
     * C'est ce qui fait exister "excellent passeur, mauvais tacleur". La
     * version precedente mettait tous les attributs du profil exactement a
     * `ceiling` : deux milieux de meme potentiel etaient alors litteralement le
     * meme joueur - mesure, ecart-type intra-profil de 1,5 point. Un monde ou
     * connaitre le poste et le niveau suffit a tout reconstituer n'a rien a
     * faire scouter, et c'est l'asymetrie d'information qui porte le jeu
     * d'agent (docs/12- §4).
     *
     * L'invariant central ne bouge pas - un joueur pleinement developpe note
     * toujours exactement son `ceiling` a son poste - il gagne seulement la
     * liberte de redistribuer a l'interieur.
     *
     * ## Hors profil, et dormants
     *
     * Les attributs de poids nul n'entrent pas dans le budget : ils sont
     * rabaisses a `offProfileCeilingRatio` (ou laisses pleins s'ils sont
     * dormants, cf. `dormantAttributes()`). C'est le mecanisme qui fait tenir
     * un profil dans le temps : `Football\PlayerDevelopmentSystem` progresse
     * proportionnellement a l'ecart au plafond, donc sans plafonds distincts il
     * ramene tout attribut au meme niveau, et d'autant plus vite qu'il en est
     * loin.
     *
     * Plancher a 1, plafond a 100 : l'echelle des competences est [1, 100]
     * (docs/12- §5). Le clamp haut peut mordre sur un tres gros potentiel dote
     * d'une repartition tres pointue, auquel cas le budget est legerement
     * sous-consomme - une asymptote souple, comme le veut le docblock de
     * `PlayerPotentials`, pas une egalite comptable.
     *
     * @param array<string, float> $spread facteur par attribut, deja normalise pour que la contrainte de budget tienne
     */
    public static function ceilings(
        int $ceiling,
        Position $archetype,
        array $spread,
        PositionBalance $balance,
    ): AttributeCeilings {
        $weights = self::weights($archetype);
        $dormant = self::dormantAttributes();
        $values = [];

        foreach (self::attributeNames() as $attribute) {
            $base = match (true) {
                isset($weights[$attribute]) => $ceiling * ($spread[$attribute] ?? 1.0),
                \in_array($attribute, $dormant, true) => (float) $ceiling,
                default => $ceiling * $balance->offProfileCeilingRatio,
            };

            $values[$attribute] = max(1, min(100, (int) round($base)));
        }

        return new AttributeCeilings(
            pace: $values['pace'],
            stamina: $values['stamina'],
            strength: $values['strength'],
            reflexes: $values['reflexes'],
            technique: $values['technique'],
            passing: $values['passing'],
            finishing: $values['finishing'],
            defending: $values['defending'],
            positioning: $values['positioning'],
            handling: $values['handling'],
            distribution: $values['distribution'],
            vision: $values['vision'],
            composure: $values['composure'],
            leadership: $values['leadership'],
            discipline: $values['discipline'],
            command: $values['command'],
        );
    }

    /**
     * Normalise une repartition brute pour que la contrainte de budget tienne
     * exactement : `Σ poids × facteur = 1`.
     *
     * Le tirage brut vit dans `Football\Generation\PlayerFactory` (seul endroit
     * qui a un `Rng`) ; cette classe reste pure. La normalisation est ce qui
     * transforme des facteurs independants en **arbitrage** : gagner sur un
     * attribut du profil se paie sur les autres.
     *
     * @param array<string, float> $raw
     * @return array<string, float>
     */
    public static function normalizeSpread(Position $archetype, array $raw): array
    {
        $weights = self::weights($archetype);
        $weighted = 0.0;

        foreach ($weights as $attribute => $weight) {
            $weighted += $weight * ($raw[$attribute] ?? 1.0);
        }

        if ($weighted <= 0.0) {
            return array_map(static fn (): float => 1.0, $weights);
        }

        $spread = [];

        foreach ($weights as $attribute => $weight) {
            $spread[$attribute] = ($raw[$attribute] ?? 1.0) / $weighted;
        }

        return $spread;
    }

    /**
     * Les seize noms d'attributs, dans l'ordre des trois composants de
     * competences.
     *
     * @return list<string>
     */
    public static function attributeNames(): array
    {
        return [
            'pace', 'stamina', 'strength', 'reflexes',
            'technique', 'passing', 'finishing', 'defending', 'positioning', 'handling', 'distribution',
            'vision', 'composure', 'leadership', 'discipline', 'command',
        ];
    }

    /**
     * Le nombre de places de ce poste dans la formation
     * (`PositionBalance`) - lu par `Football\MatchSystem` pour composer le
     * onze et par `Football\ContractSystem` pour ses cibles d'effectif.
     */
    public static function slots(Position $position, PositionBalance $balance): int
    {
        return match ($position) {
            Position::Goalkeeper => $balance->goalkeeperSlots,
            Position::Defender => $balance->defenderSlots,
            Position::Midfielder => $balance->midfielderSlots,
            Position::Attacker => $balance->attackerSlots,
        };
    }

    /**
     * La part de la population generee qui recoit cet archetype
     * (`PositionBalance`), lue par les deux producteurs de joueurs.
     */
    public static function generationShare(Position $position, PositionBalance $balance): float
    {
        return match ($position) {
            Position::Goalkeeper => $balance->goalkeeperShare,
            Position::Defender => $balance->defenderShare,
            Position::Midfielder => $balance->midfielderShare,
            Position::Attacker => $balance->attackerShare,
        };
    }
}
