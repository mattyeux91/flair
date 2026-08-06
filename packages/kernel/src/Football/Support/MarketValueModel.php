<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Support;

use Flair\Kernel\Core\Ruleset\MarketValueBalance;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\Components\PlayerPotentials;

/**
 * Ce qu'un joueur vaut a la revente (docs/14-algorithmes.md §5, forme bornee
 * de §3). Fonctions pures et statiques : aucun etat, aucun RNG, aucune
 * lecture du monde - meme forme que `Football\Support\WageModel`.
 *
 * ## Une contradiction du doc, tranchee
 *
 * `14-` §5 ecrit `modif = clamp( facteur_contrat x rarete_poste x
 * richesse_acheteur, 0.4, 2.5 )`, mais la prose qui suit dit l'inverse :
 * *"facteur_contrat est la seule exception admise a la borne basse [...] on
 * l'applique donc apres le clamp"*. Un joueur a six mois de la fin de contrat
 * doit pouvoir tomber sous 0.4x - donc `facteur_contrat` ne peut pas etre
 * dans le clamp. Cette classe suit la prose (l'intention explicite) : le
 * clamp ne porte que sur `rarete_poste x richesse_acheteur`,
 * `facteur_contrat` multiplie apres, sans plancher partage.
 *
 * ```
 * base         = f(qualite percue) x courbe_age(age, pic)
 * modifClampe  = clamp( rarete_poste x richesse_acheteur, 0.4, 2.5 )
 * valeur       = base x modifClampe x facteur_contrat x indice_inflation_global
 * ```
 *
 * ## Ce qui est calcule ici, ce qui est recu deja resolu
 *
 * `rarete_poste` et `richesse_acheteur` n'existent comme grandeurs calculees
 * nulle part encore : ils dependent du contexte de negociation du marche
 * (docs/17-marche-transferts.md, point 2), donc cette classe les recoit en
 * parametres deja resolus - neutres a `1.0` tant qu'aucun appelant reel
 * n'existe, exactement comme `WageModel`/`PerceptionModel` recoivent leurs
 * entrees deja resolues par l'appelant. `facteur_contrat`, lui, est calcule
 * pour de vrai des ce point : `Contract::$expiresOn` existe deja.
 *
 * ## Le pic d'age : une moyenne, pas une ponderation par poste
 *
 * `PlayerPotentials` porte trois pics (`physicalPeakAge`/`technicalPeakAge`/
 * `mentalPeakAge`). Premier essai, rejete : ponderer par la categorie
 * dominante du poste via `PositionModel::weights()`. Verifie a la main sur
 * les quatre postes - la table de poids de `PositionModel` range
 * `defending`/`passing`/`finishing`/`positioning`/`technique` sous
 * "technique", si bien que cette categorie domine sur les **quatre** postes
 * sans exception. La ponderation degenere en "toujours `technicalPeakAge`",
 * une complexite qui ne differencie jamais rien. Cette classe retient donc la
 * moyenne simple des trois pics : aucun systeme ne fait varier ces plages par
 * poste (`YouthIntakeBalance::*PeakAgeMin/Max` sont globales), une
 * ponderation par poste recupererait un signal que le generateur n'a jamais
 * produit.
 */
final class MarketValueModel
{
    public static function value(
        int $perceivedQuality,
        float $ageYears,
        PlayerPotentials $potentials,
        SimDate $now,
        SimDate $contractExpiresOn,
        float $positionScarcity,
        float $buyerWealthFactor,
        float $globalInflationIndex,
        MarketValueBalance $balance,
    ): int {
        $base = self::baseValue($perceivedQuality, $balance)
            * self::ageCurve($ageYears, self::peakAge($potentials), $balance);

        $modif = max($balance->modifierMin, min($balance->modifierMax, $positionScarcity * $buyerWealthFactor));

        $value = $base * $modif
            * self::contractFactor($now, $contractExpiresOn, $balance)
            * $globalInflationIndex;

        return max(0, (int) round($value));
    }

    /**
     * `referenceQuality <= 0` retomberait sur une division par zero : la
     * valeur de base est alors rendue telle quelle, meme choix defensif que
     * `WageModel::perWeekCents`.
     */
    private static function baseValue(int $perceivedQuality, MarketValueBalance $balance): float
    {
        if ($balance->referenceQuality <= 0) {
            return (float) $balance->baseValueCents;
        }

        $multiplier = max(
            $balance->valueMultiplierMin,
            min($balance->valueMultiplierMax, $perceivedQuality / $balance->referenceQuality),
        );

        return $balance->baseValueCents * $multiplier;
    }

    private static function peakAge(PlayerPotentials $potentials): int
    {
        return (int) round(($potentials->physicalPeakAge + $potentials->technicalPeakAge + $potentials->mentalPeakAge) / 3);
    }

    /**
     * Avant le pic : rampe de prime jeunesse, de `1.0` au pic jusqu'a
     * `youthPremiumCeiling` a `youthWindowYears` avant (et au-dela, plafonnee).
     * Apres le pic : declin lineaire borne par `agingFloorMultiplier`.
     */
    private static function ageCurve(float $ageYears, int $peakAge, MarketValueBalance $balance): float
    {
        if ($ageYears <= $peakAge) {
            if ($balance->youthWindowYears <= 0.0) {
                return 1.0;
            }

            $t = max(0.0, min(1.0, ($peakAge - $ageYears) / $balance->youthWindowYears));

            return 1.0 + $t * ($balance->youthPremiumCeiling - 1.0);
        }

        $yearsPastPeak = $ageYears - $peakAge;

        return max($balance->agingFloorMultiplier, 1.0 - $balance->agingDeclinePerYear * $yearsPastPeak);
    }

    /**
     * `1.0` a `contractFullValueYears` ou plus de l'echeance, decroissance
     * lineaire jusqu'a `contractFloorMultiplier` a l'echeance ou au-dela.
     */
    private static function contractFactor(SimDate $now, SimDate $expiresOn, MarketValueBalance $balance): float
    {
        $yearsRemaining = $expiresOn->yearsSince($now);

        if ($yearsRemaining >= $balance->contractFullValueYears) {
            return 1.0;
        }

        if ($yearsRemaining <= 0.0) {
            return $balance->contractFloorMultiplier;
        }

        if ($balance->contractFullValueYears <= 0.0) {
            return $balance->contractFloorMultiplier;
        }

        $t = $yearsRemaining / $balance->contractFullValueYears;

        return $balance->contractFloorMultiplier + $t * (1.0 - $balance->contractFloorMultiplier);
    }
}
