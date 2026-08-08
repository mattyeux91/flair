<?php

declare(strict_types=1);

namespace Flair\Worldgen;

/**
 * La forme d'un monde a engendrer : combien de clubs, combien de joueurs,
 * avec quelle graine, et les quelques grandeurs qui decrivent l'etat de
 * depart plutot que les regles du jeu.
 *
 * ## Ce qui est ici, et ce qui appartient au `Ruleset`
 *
 * La frontiere n'est pas cosmetique, et elle a une consequence pratique :
 * le `Ruleset` dit **comment le monde se comporte**, `WorldSpec` dit **de
 * quoi il est fait au premier tick**. `PerceptionBalance` dit de combien un
 * jugement de recruteur se trompe ; `scoutJudgementMean`/`Spread` disent qui
 * sont les recruteurs. `TransferBalance` dit comment la patience module une
 * rupture de negociation ; `boardPatienceMean`/`Spread` disent quelle
 * patience chaque club a recu a sa naissance.
 *
 * La consequence : une comparaison a graines appariees
 * (`Harness\Comparison\PairedSeedComparison`) ne rejoue **jamais** la
 * generation - c'est tout son principe, meme population des deux cotes. Un
 * levier de generation loge par erreur dans le `Ruleset` serait donc
 * silencieusement inoperant sous `--set`. Faire varier la dispersion du staff
 * demande deux runs entiers, pas une comparaison appariee.
 *
 * `facilitiesQuality` et `startingBalanceCents` sont uniformes sur tous les
 * clubs : premier jet volontairement simple, pas de variance inter-clubs
 * (cf. `ClubFactory`).
 *
 * ## Ce qui n'est pas ici
 *
 * Aucune duree de simulation. « Combien d'annees fait-on tourner ce monde »
 * est une question d'appelant, pas une propriete du monde - c'est
 * `Harness\Population\PopulationSpec` qui porte ce `years`, et qui construit
 * un `WorldSpec` a partir du reste.
 */
final readonly class WorldSpec
{
    public function __construct(
        public int $playerCount,
        public int $seed,
        public int $clubCount = 18,
        public float $facilitiesQuality = 1.0,
        public int $startingBalanceCents = 10_000_000,
        public int $scoutJudgementMean = 50,
        public int $scoutJudgementSpread = 25,
        public int $boardPatienceMean = 50,
        public int $boardPatienceSpread = 25,
    ) {
    }
}
