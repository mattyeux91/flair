<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Components;

/**
 * Le role de scout, porte par une entite `Person` employee par un club
 * (`Employment`). C'est la **presence de ce composant** qui fait d'une personne
 * un scout : aucun sous-type, aucun enum de role (docs/12-modele-du-monde.md
 * §1) - un retraite qui deviendrait scout garderait son `Person` et gagnerait
 * ce composant.
 *
 * `judgement` vit sur la meme echelle absolue 1-100 que les competences des
 * joueurs et le `ceiling` de `PlayerPotentials` (docs/12- §5) : ~50 = staff
 * median, ~85 = un des meilleurs recruteurs du monde. Il ne dit pas de combien
 * le scout se trompe - c'est `Ruleset\PerceptionBalance` qui traduit un
 * jugement en erreur, pour que la meme personne se trompe plus ou moins selon
 * la calibration du monde.
 *
 * **C'est la personne qui percoit, jamais le club** (docs/12- §4) : c'est
 * l'`EntityId` de cette entite qui sert d'`observerId` dans la derivation du
 * bruit, ce qui rend la perception attachee a quelqu'un plutot qu'a une
 * institution - et deux clubs qui echangeraient leurs scouts echangeraient
 * leurs erreurs.
 *
 * Seme au genesis, jamais ecrit par un systeme : ni embauche, ni progression du
 * staff, ni licenciement en Phase 2 (gouvernance de club, docs/14- §7).
 */
final readonly class Scout
{
    public function __construct(public int $judgement)
    {
    }
}
