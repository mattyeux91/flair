<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Components;

/**
 * Qualite des installations d'un club (docs/14- §2 : `h(entrainement)`),
 * portee par l'entite club. Exprimee directement sur l'echelle du
 * multiplicateur final `[0.5, 2.0]` (docs/14- §3) - pas d'echelle
 * intermediaire a mapper, meme choix que `PlayerPotentials::$fragility`
 * deja utilise directement sans indirection. `1.0` = installations
 * moyennes, `0.5` = mediocres, `2.0` = excellentes.
 *
 * Seme au genesis (`Harness\Population\ClubFactory`, `bin/demo.php`), puis
 * fait evoluer par `Football\FacilitiesSystem`, son unique writer : chaque
 * saison la qualite se degrade, et l'investissement d'un club la releve.
 * C'est cette conversion argent -> qualite qui referme la boucle
 * "resultats -> argent -> meilleurs joueurs -> resultats" de docs/14- §7.
 *
 * ## Deux lecteurs, deux effets - dont un facile a oublier
 *
 * `Football\TrainingSystem` en tire le multiplicateur de progression, et
 * `Football\YouthIntakeSystem` en module la **taille des promotions**
 * (`baseIntakePerClub * quality`). Investir n'ameliore donc pas seulement
 * l'entrainement : ca augmente aussi le nombre de jeunes qui percent. Un
 * club riche produit plus de joueurs *et* de meilleurs joueurs. Consequence
 * a garder en tete a chaque calibrage : la qualite **moyenne** du monde
 * pilote directement la stationnarite de la population, critere de sortie
 * de la Phase 0 (docs/15- §4).
 *
 * ## Pourquoi les bornes sont ici et pas dans le `Ruleset`
 *
 * `FacilitiesSystem` doit clamper ce qu'il ecrit, et `Football\FinanceSystem`
 * doit savoir qu'un club deja au plafond n'a plus rien a acheter - sinon son
 * investissement brule de l'argent sans contrepartie. Passer par un levier de
 * `Ruleset` ferait dependre un systeme des leviers d'un autre, ce que
 * `Core\Ruleset\Balance` interdit explicitement. Un invariant du composant,
 * porte par le composant, n'est pas un levier d'equilibrage : les deux
 * systemes peuvent s'y referer sans se coupler l'un a l'autre.
 *
 * Ces bornes sont distinctes de celles de `TrainingSystem`, qui clampe le
 * **produit** `trainingRate x quality` sur la meme echelle - meme intervalle,
 * grandeur differente, a ne pas fusionner.
 */
final readonly class Facilities
{
    public const float MIN_QUALITY = 0.5;
    public const float MAX_QUALITY = 2.0;

    public function __construct(
        public float $quality,
    ) {
    }
}
