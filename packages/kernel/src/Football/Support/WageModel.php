<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Support;

use Flair\Kernel\Core\Ruleset\ContractBalance;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;

/**
 * Le prix d'un joueur, en salaire hebdomadaire (docs/14-algorithmes.md §3 et
 * §5). Fonctions pures et statiques : aucun etat, aucun RNG, aucune lecture
 * du monde - c'est une formule, pas un systeme.
 *
 * ## Pourquoi une classe partagee et pas une methode privee
 *
 * Deux consommateurs reels, jamais un seul : `Football\ContractSystem` (a
 * chaque renouvellement et chaque signature) et
 * `Harness\Population\PopulationFactory` (le monde doit demarrer a la meme
 * echelle de salaires que celle vers laquelle il convergera, sinon la masse
 * salariale derive pendant les quatre premieres annees et la ligne de base du
 * grand livre n'est comparable a rien). C'est le seul critere que le projet
 * s'applique pour generaliser - cf. le docblock de
 * `Football\Components\TrainingEffect`, qui refuse la generalisation tant
 * qu'un seul facteur existe.
 *
 * `Football\MatchSystem` ne passe **pas** par cette classe : il produit un
 * couple attaque/defense sur un onze compose, ce qui est un besoin different
 * d'une qualite marchande individuelle. Les deux partagent en revanche leur
 * socle, `Football\Support\PositionModel` - c'est la, et pas ici, que vit la
 * definition d'un poste.
 *
 * ## La forme
 *
 * ```
 * qualite = note du joueur a son meilleur poste     (PositionModel)
 * salaire = base x clamp(qualite / reference, min, max)
 * ```
 *
 * Un produit **borne**, jamais une composition libre de facteurs (docs/14-
 * §3 : le piege du multiplicatif a N facteurs). Le clamp n'est pas un
 * garde-fou defensif, c'est le modele : il fixe l'ecart maximal de salaire
 * entre le pire et le meilleur joueur du monde, donc l'amplitude de
 * l'inegalite economique que le monde peut produire.
 *
 * La qualite est **ponderee par poste** (`Football\Support\PositionModel`) :
 * un gardien n'est pas value sur sa finition. Elle l'a longtemps ete - la
 * moyenne plate des seize attributs etait la seule ponderation honnete tant
 * qu'aucun poste n'existait.
 */
final class WageModel
{
    /**
     * La qualite d'un joueur sur l'echelle [1, 100] des competences : **sa note
     * a son meilleur poste** (`Football\Support\PositionModel`).
     *
     * Ce n'est plus la moyenne plate des seize attributs, et c'est le point du
     * lot des postes qui touche l'economie : un club payait jusqu'ici un
     * attaquant pour ses qualites de relance au pied et un gardien pour sa
     * finition. Un joueur vaut ce qu'il vaut **la ou il joue**.
     *
     * "Meilleur poste" et non "poste de son archetype" : c'est la qualite
     * marchande, et personne n'achete un joueur pour la forme de son potentiel.
     * `PositionModel::bestPosition()` la derive des competences du moment, donc
     * un joueur dont le profil a devie est value sur ce qu'il sait faire
     * aujourd'hui.
     *
     * C'est aussi **le site ou la perception se branchera** (docs/12- §4) : le
     * lot suivant remplacera ces competences vraies par une estimation bruitee
     * par l'observateur, et rien d'autre ici n'aura a changer.
     *
     * Un bloc absent rend zero plutot qu'une note partielle : un joueur ampute
     * d'un tiers de ses competences n'est pas un joueur (c'est un retraite, ou
     * une entite en cours de construction), et lui rendre une note calculee sur
     * les blocs restants le ferait passer pour normal. Les appelants ne doivent
     * pas arriver ici avec un bloc manquant - ils verifient d'abord, cf.
     * `Football\ContractSystem::quality()`.
     */
    public static function quality(
        ?PlayerPhysicalSkills $physical,
        ?PlayerTechnicalSkills $technical,
        ?PlayerMentalSkills $mental,
    ): int {
        if ($physical === null || $technical === null || $mental === null) {
            return 0;
        }

        return (int) round(PositionModel::ratingAt(
            PositionModel::bestPosition($physical, $technical, $mental),
            $physical,
            $technical,
            $mental,
        ));
    }

    /**
     * Le salaire hebdomadaire, en centimes, d'un joueur de cette qualite.
     *
     * `referenceQuality <= 0` retomberait sur une division par zero : le
     * salaire de base est alors rendu tel quel plutot que de laisser un
     * `Ruleset` mal rempli faire exploser le noyau au milieu d'un run de
     * 1 000 saisons - meme choix defensif que le clamp de `meritShare` dans
     * `Football\FinanceSystem`.
     */
    public static function perWeekCents(int $quality, ContractBalance $contract): int
    {
        if ($contract->referenceQuality <= 0) {
            return $contract->baseWagePerWeekCents;
        }

        $multiplier = max(
            $contract->wageMultiplierMin,
            min($contract->wageMultiplierMax, $quality / $contract->referenceQuality),
        );

        return max(0, (int) round($contract->baseWagePerWeekCents * $multiplier));
    }
}
