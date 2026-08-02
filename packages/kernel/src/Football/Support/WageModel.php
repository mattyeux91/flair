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
 * `Football\MatchSystem::ratings()` n'est **pas** refactore pour passer par
 * ici : il produit un couple attaque/defense a partir d'un sous-ensemble
 * pondere de competences, ce qui est un besoin different d'une qualite
 * globale. Les fusionner obligerait l'un des deux a porter une notion dont il
 * n'a pas besoin.
 *
 * ## La forme
 *
 * ```
 * qualite = moyenne(moyenne(physique), moyenne(technique), moyenne(mental))
 * salaire = base x clamp(qualite / reference, min, max)
 * ```
 *
 * Un produit **borne**, jamais une composition libre de facteurs (docs/14-
 * §3 : le piege du multiplicatif a N facteurs). Le clamp n'est pas un
 * garde-fou defensif, c'est le modele : il fixe l'ecart maximal de salaire
 * entre le pire et le meilleur joueur du monde, donc l'amplitude de
 * l'inegalite economique que le monde peut produire.
 *
 * Les trois blocs de competences pesent le meme tiers. Une ponderation plus
 * fine (un gardien n'a pas besoin de `finishing`) demande `PositionAffinity`,
 * qui n'existe pas - la moyenne plate est la seule ponderation honnete tant
 * qu'aucun poste n'existe, meme raisonnement que
 * `Football\MatchSystem` sur la force d'un club.
 */
final class WageModel
{
    /**
     * La qualite globale d'un joueur sur l'echelle [1, 100] des competences.
     *
     * Un bloc absent compte pour zero plutot que d'etre saute : un joueur
     * ampute d'un tiers de ses competences n'est pas un joueur (c'est un
     * retraite, ou une entite en cours de construction), et lui rendre la
     * moyenne des deux blocs restants le ferait passer pour normal. Les
     * appelants ne doivent pas arriver ici avec un bloc manquant - ils
     * verifient d'abord, cf. `Football\ContractSystem`.
     */
    public static function quality(
        ?PlayerPhysicalSkills $physical,
        ?PlayerTechnicalSkills $technical,
        ?PlayerMentalSkills $mental,
    ): int {
        $physicalAverage = $physical === null ? 0.0 : (
            $physical->pace + $physical->stamina + $physical->strength + $physical->reflexes
        ) / 4.0;

        $technicalAverage = $technical === null ? 0.0 : (
            $technical->technique + $technical->passing + $technical->finishing + $technical->defending
            + $technical->positioning + $technical->handling + $technical->distribution
        ) / 7.0;

        $mentalAverage = $mental === null ? 0.0 : (
            $mental->vision + $mental->composure + $mental->leadership + $mental->discipline
            + $mental->command
        ) / 5.0;

        return (int) round(($physicalAverage + $technicalAverage + $mentalAverage) / 3.0);
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
