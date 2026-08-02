<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Ruleset;

/**
 * Leviers d'equilibrage du grand livre monetaire (docs/14-algorithmes.md
 * §6), lus uniquement par Football\FinanceSystem.
 *
 * Premier lot volontairement plat : une seule injection (revenu de club
 * periodique, substitut de droits TV/sponsors) et un seul puits (salaires),
 * sans variance ni RNG - voir docs/15-roadmap.md §4 Phase 2 pour le
 * perimetre retenu. `basePlayerWagePerWeekCents`, le salaire seede a la
 * creation d'un `Contract`, vit dans `YouthIntakeBalance` et non ici : c'est
 * `Football\YouthIntakeSystem` qui cree le `Contract` d'un joueur promu, et
 * un systeme ne depend jamais des leviers d'un autre (meme regle que celle
 * documentee sur `Balance`).
 */
final readonly class FinanceBalance
{
    public function __construct(
        /**
         * Revenu credite a chaque club portant `Finances` sur chaque
         * `SeasonStarted` (docs/14- §6, table des injections : droits TV/
         * sponsors/billetterie/merchandising/primes, non distingues dans ce
         * premier lot).
         *
         * Ordre de grandeur cale sur la masse salariale de la population de
         * reference du harness (500 joueurs/18 clubs, ~28 joueurs/club,
         * `PopulationSpec` par defaut) : `basePlayerWagePerWeekCents` par
         * defaut (`YouthIntakeBalance`) x 52 semaines x ~28 joueurs =
         * ~72,8M centimes/an. Une premiere valeur choisie sans verifier ce
         * produit (5M) laissait un club moyen s'endetter de ~590k EUR/an,
         * un facteur ~15 hors sujet meme pour un premier jet qualitatif -
         * corrige le 2026-08-02 en observant `Harness\Simulation\StepRunner`
         * sur 5 saisons plutot qu'en redevinant un chiffre. Reste un premier
         * jet a affiner via le harness d'equilibrage : aucune variance
         * inter-clubs (droits TV egaux, pas de billetterie liee a la
         * `FanBase`), donc un club structurellement plus modeste qu'un autre
         * n'a aucune raison endogene de l'etre encore une fois la saison
         * suivante.
         */
        public int $clubIncomePerSeasonCents = 70_000_000,
        /** Jour de la semaine (`tick % 7`) ou les salaires sont verses a chaque joueur sous `Contract`. */
        public int $wagePaymentDayOfWeek = 0,
    ) {
    }
}
