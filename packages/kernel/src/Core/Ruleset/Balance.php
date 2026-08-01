<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Ruleset;

/**
 * Les leviers d'equilibrage du monde (docs/12-modele-du-monde.md §6, la cle
 * JSON "balance" : trainingRate, injuryBaseHazard, marketInflationTarget...).
 *
 * `developmentRate` est le premier - multiplicateur global sur la
 * progression naturelle (vieillissement, docs/14- §2), lu par
 * Football\AgingSystem. `aging` regroupe les leviers plus fins du meme
 * systeme (age de retraite, forme de g(age)...) - une classe dediee plutot
 * que des scalaires ici, pour ne pas melanger les sous-domaines a mesure
 * que d'autres systemes (blessures, marche...) rejoindront `Balance`.
 */
final readonly class Balance
{
    public function __construct(
        /** Multiplicateur global sur `annualDelta` dans AgingSystem::nextValue - accelere/ralentit la progression et le declin des attributs sans changer leur forme (g(age), plafond...). */
        public float $developmentRate = 1.0,
        public AgingBalance $aging = new AgingBalance(),
    ) {
    }
}
