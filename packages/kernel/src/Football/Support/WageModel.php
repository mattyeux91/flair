<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Support;

use Flair\Kernel\Core\Ruleset\ContractBalance;
use Flair\Kernel\Core\Support\Rng;
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
 * `Worldgen\WorldFactory` (le monde doit demarrer a la meme
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
     *
     * ## L'indice d'inflation (docs/17- point 5)
     *
     * `$inflationIndex` est le niveau de prix courant du monde
     * (`Football\Singletons\MarketInflation::$index`), `1.0` a sa creation. Il
     * multiplie le resultat **en dernier**, hors du clamp, exactement comme
     * dans `MarketValueModel` : docs/14- §5 en fait « un changement d'unite
     * monetaire [qui] s'applique uniformement a tout le marche », pas un
     * modificateur de situation. Un salaire est un prix - l'exempter creerait
     * une distorsion du prix relatif salaire/indemnite, l'inverse de ce que le
     * doc demande.
     *
     * **Requis, sans valeur par defaut.** Un defaut a `1.0` ferait passer un
     * appelant qui a oublie l'indice pour un appelant correct, et l'erreur ne
     * se verrait que des dizaines de saisons plus tard, en termes reels. Le
     * genesis du harness passe `1.0` explicitement : un monde demarre au pair.
     */
    public static function perWeekCents(int $quality, ContractBalance $contract, float $inflationIndex): int
    {
        if ($contract->referenceQuality <= 0) {
            return max(0, (int) round($contract->baseWagePerWeekCents * $inflationIndex));
        }

        $multiplier = max(
            $contract->wageMultiplierMin,
            min($contract->wageMultiplierMax, $quality / $contract->referenceQuality),
        );

        return max(0, (int) round($contract->baseWagePerWeekCents * $multiplier * $inflationIndex));
    }

    /**
     * La duree, en annees entieres, du contrat signe aujourd'hui. Tiree par
     * joueur pour **etaler les echeances** : a duree fixe, une cohorte signee
     * la meme annee reviendrait sur le marche en bloc et l'effectif d'un club
     * oscillerait au lieu de tourner (cf. `ContractBalance::$minDurationYears`).
     *
     * Ici plutot que dans un systeme parce que deux consommateurs reels
     * existent - `Football\ContractSystem` au renouvellement annuel et
     * `Football\TransferSystem` a la conclusion d'un transfert - et jamais
     * avant qu'ils existent. Ici plutot que dans une classe a une fonction
     * parce que `ContractBalance` dit lui-meme que le contrat decide
     * « combien coute un joueur **et combien de temps** » : c'est le meme
     * sujet.
     *
     * L'appelant fournit le flux, comme `PerceptionModel` recoit son entier de
     * bruit : la fonction reste pure et testable a la main. Un seul tirage,
     * pour que deux appelants qui derivent le meme flux obtiennent le meme
     * resultat.
     */
    public static function contractDurationYears(Rng $rng, ContractBalance $contract): int
    {
        $shortest = max(1, $contract->minDurationYears);
        $longest = max($shortest, $contract->maxDurationYears);

        return $shortest + (int) ($rng->nextUint32() % ($longest - $shortest + 1));
    }
}
