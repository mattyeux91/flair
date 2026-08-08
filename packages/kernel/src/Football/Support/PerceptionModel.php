<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Support;

use Flair\Kernel\Core\Ruleset\PerceptionBalance;

/**
 * Ce qu'un observateur **croit** valoir un joueur (docs/12-modele-du-monde.md
 * §4). Fonctions pures et statiques : aucun etat, aucun RNG, aucune lecture du
 * monde, et surtout aucun hachage - l'appelant fournit l'entier de bruit, ce qui
 * rend cette classe testable a la main.
 *
 * ## Le principe, et pourquoi il n'y a rien a stocker
 *
 * L'estimation n'est **jamais stockee** : elle se derive a la lecture d'un bruit
 * fonction de `(monde, observateur, sujet, nb d'observations)`, via
 * `Core\SystemContext::stableHash()`. Deux consequences qui sont tout l'interet
 * de la forme : cout memoire nul (aucune structure par paire, qui serait en
 * O(observateurs x joueurs)), et **stabilite parfaite** - le meme observateur
 * relit la meme estimation tant que son nombre d'observations n'a pas change.
 * Un bruit re-tire a chaque lecture donnerait un rapport de scouting qui change
 * a chaque rafraichissement de page, ce qui n'est pas une erreur d'evaluation
 * mais une hallucination.
 *
 * ## La forme
 *
 * ```
 * facteur   = jugement / reference
 * sigma     = erreurDeBase / sqrt(facteur x (1 + nbObservations))
 * estimation = clamp(verite + z x sigma, 1, 100)
 * ```
 *
 * **Ecart assume avec l'esquisse de docs/12- §4**, qui ecrit
 * `sigma = baseSigma / sqrt(1 + nbObservations x jugement)`. Sous cette forme,
 * un `nbObservations` de zero annule l'effet du jugement : *tous* les clubs
 * jugeraient un joueur qu'ils n'ont jamais eu sous les yeux aussi mal les uns
 * que les autres, et un bon scout ne servirait qu'a apprendre plus vite sur les
 * joueurs deja maison. C'est l'inverse du metier. La forme retenue garde les
 * deux effets - le jugement aide toujours, l'observation compose - et redonne
 * l'esquisse a un facteur pres.
 *
 * ## `z` sans fonction transcendante
 *
 * `z` est une somme de quatre uniformes (loi d'Irwin-Hall), tiree des quatre
 * octets de l'entier de bruit, centree et reduite : approximativement normale,
 * bornee a +/-3,45 sigma. Pas de Box-Muller, donc ni `log` ni `cos` - meme
 * arbitrage que `Football\Generation\PlayerFactory`, qui prefere un `Beta(1,k)`
 * a une log-normale. `sqrt` est algebrique et exactement specifiee par
 * IEEE-754 : elle ne rouvre pas la question des transcendantes du noyau
 * (docs/13- §4.8).
 *
 * Les bornes de `z` ne sont pas une limite : a `sigma` typique, +/-3,45 sigma
 * depasse largement l'echelle 1-100 sur laquelle l'estimation est clampee.
 */
final class PerceptionModel
{
    /**
     * Nombre d'uniformes sommees, et leur borne : les quatre octets d'un entier
     * 32 bits. Moyenne de la somme `4 x 127,5`, ecart-type
     * `sqrt(4 x (256^2 - 1) / 12)`.
     */
    private const NOISE_TERMS = 4;
    private const NOISE_TERM_MAX = 255;

    /**
     * Plancher du facteur de jugement, pour qu'un `judgementReference`
     * aberrant ne fasse pas exploser sigma ni diviser par zero. Clamp
     * defensif au consommateur plutot que validation du `Ruleset` - un noyau
     * qui doit tourner 1 000 saisons sans surveillance prefere une valeur
     * bornee a une exception (meme choix que `Football\FinanceSystem`).
     */
    private const MIN_JUDGEMENT_FACTOR = 0.01;

    public const MIN_ESTIMATE = 1;
    public const MAX_ESTIMATE = 100;

    /**
     * L'ecart-type de l'erreur de cet observateur sur ce sujet, en points de
     * l'echelle 1-100.
     */
    public static function sigma(int $observationYears, int $judgement, PerceptionBalance $balance): float
    {
        if ($balance->baseErrorPoints <= 0.0) {
            return 0.0;
        }

        $judgement = max(self::MIN_ESTIMATE, min(self::MAX_ESTIMATE, $judgement));
        $factor = $judgement / max(1, $balance->judgementReference);
        $observations = max(0, $observationYears);

        return $balance->baseErrorPoints
            / sqrt(max(self::MIN_JUDGEMENT_FACTOR, $factor * (1 + $observations)));
    }

    /**
     * L'estimation de `$trueValue` par un observateur de jugement `$judgement`
     * qui a observe son sujet pendant `$observationYears` annees.
     *
     * `$noise32` est un entier 32 bits deja derive de
     * `(monde, observateur, sujet, nb d'observations)` par l'appelant - c'est
     * lui qui porte l'identite de l'observateur, pas cette fonction.
     *
     * A `baseErrorPoints` nul, rend `$trueValue` **exactement** : c'est ce qui
     * fait de la desactivation de la perception une reduction stricte au
     * comportement d'avant le lot, verifiable au hash du monde.
     */
    public static function estimate(
        int $trueValue,
        int $noise32,
        int $observationYears,
        int $judgement,
        PerceptionBalance $balance,
    ): int {
        $sigma = self::sigma($observationYears, $judgement, $balance);

        if ($sigma === 0.0) {
            return $trueValue;
        }

        $estimate = (int) round($trueValue + self::standardNormal($noise32) * $sigma);

        return max(self::MIN_ESTIMATE, min(self::MAX_ESTIMATE, $estimate));
    }

    /**
     * Somme des quatre octets de `$noise32`, centree et reduite. Approche une
     * normale centree reduite sans aucune fonction transcendante.
     */
    private static function standardNormal(int $noise32): float
    {
        $sum = 0;

        for ($byte = 0; $byte < self::NOISE_TERMS; $byte++) {
            $sum += ($noise32 >> ($byte * 8)) & self::NOISE_TERM_MAX;
        }

        $mean = self::NOISE_TERMS * self::NOISE_TERM_MAX / 2.0;
        $variance = self::NOISE_TERMS * ((self::NOISE_TERM_MAX + 1) ** 2 - 1) / 12.0;

        return ($sum - $mean) / sqrt($variance);
    }
}
