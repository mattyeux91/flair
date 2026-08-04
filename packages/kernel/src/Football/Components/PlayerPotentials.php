<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Components;

use Flair\Kernel\Football\Support\AttributeCeilings;

/**
 * Le potentiel d'un joueur : une trajectoire, pas un plafond dur
 * (docs/14-algorithmes.md §2). `ceiling` est une asymptote souple - la
 * progression en approche sans jamais la heurter net.
 *
 * `growthRate`/`fragility` restent partages par les trois categories de
 * competences (`PlayerPhysicalSkills`/`PlayerTechnicalSkills`/
 * `PlayerMentalSkills`) - aucun systeme n'a besoin de les distinguer par
 * categorie a ce jour. L'age de pic, en revanche, est individuel **et**
 * distinct par categorie (`*PeakAge`) : c'est un fait de football etabli
 * que le physique culmine avant le mental, a niveau de talent egal, et
 * `PlayerDevelopmentSystem` en a un usage reel (pente de declin post-pic
 * differente par categorie, `Ruleset\AgingBalance`). Simplification
 * restante, assumee : un seul `growthRate`/`fragility` pour les trois - a
 * corriger si un systeme en a besoin (cf. docblock de
 * PlayerDevelopmentSystem).
 *
 * ## Le couple `ceiling` + `archetype` : combien, et de quelle forme
 *
 * `ceiling` est le **niveau de talent**, tire par la loi de talent
 * (`Football\Generation\PlayerFactory`) ; c'est de lui que depend la
 * stationnarite de la pyramide des ages (critere de sortie Phase 0,
 * docs/15- §4). `archetype` en est la **forme** : c'est lui qui fait qu'un
 * gardien a un plafond de finition bas, et definitivement.
 * `Football\Support\PositionModel::ceilings()` derive du couple le plafond de
 * chacun des seize attributs.
 *
 * **`archetype` n'est pas "le poste du joueur".** C'est un gabarit de
 * developpement fixe a la naissance, comme une morphologie. Le poste
 * effectivement joue n'est stocke nulle part : `PositionModel::bestPosition()`
 * le derive des competences du moment, donc il suit le joueur au lieu de
 * deriver de lui sur vingt saisons (meme principe que la perception,
 * docs/12- §4).
 *
 * Sans cette forme, les profils se dissolvent : `PlayerDevelopmentSystem`
 * progresse proportionnellement a l'ecart au plafond, donc a plafond unique il
 * ramene tous les attributs au meme niveau - l'attribut le plus bas etant
 * celui qui monte le plus vite. Mesure avant ce lot : ecart-type des seize
 * attributs **a l'interieur d'un meme joueur**, a l'age du pic, 4,0 points en
 * mediane.
 */
final readonly class PlayerPotentials
{
    public function __construct(
        public int $ceiling,
        public Position $archetype,
        public AttributeCeilings $ceilings,
        public int $physicalPeakAge,
        public int $technicalPeakAge,
        public int $mentalPeakAge,
        public float $growthRate,
        public float $fragility,
    ) {
    }
}
