<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Ruleset;

/**
 * Leviers d'equilibrage du grand livre monetaire (docs/14-algorithmes.md
 * §6), lus uniquement par Football\FinanceSystem.
 *
 * Une seule injection (l'enveloppe des droits TV, substitut de
 * TV/sponsors/billetterie) et un seul puits (salaires), sans variance ni RNG
 * - voir docs/15-roadmap.md §4 Phase 2 pour le perimetre retenu. Ce qui
 * varie d'un club a l'autre n'est pas le montant de l'enveloppe mais sa
 * **repartition** (`$meritShare`). `basePlayerWagePerWeekCents`, le salaire seede a la
 * creation d'un `Contract`, vit dans `YouthIntakeBalance` et non ici : c'est
 * `Football\YouthIntakeSystem` qui cree le `Contract` d'un joueur promu, et
 * un systeme ne depend jamais des leviers d'un autre (meme regle que celle
 * documentee sur `Balance`).
 */
final readonly class FinanceBalance
{
    public function __construct(
        /**
         * Revenu **moyen** par club et par saison : l'enveloppe totale
         * distribuee a chaque `Football\Events\SeasonConcluded` vaut cette
         * valeur multipliee par le nombre de clubs portant `Finances`
         * (docs/14- §6, table des injections : droits TV/sponsors/
         * billetterie/merchandising/primes, non distingues ici).
         *
         * C'est donc `$meritShare`, et lui seul, qui decide de ce que touche
         * un club en particulier : faire varier ce champ change la masse
         * monetaire injectee dans le monde, pas l'inegalite entre clubs.
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
         * jet a affiner via le harness d'equilibrage.
         */
        public int $clubIncomePerSeasonCents = 70_000_000,
        /**
         * Part de l'enveloppe distribuee au merite (classement final de la
         * saison ecoulee) plutot qu'a parts egales, entre 0 et 1 - le levier
         * de "partage des droits TV" que docs/14-algorithmes.md §7 range
         * parmi les regulations exogenes de l'equilibre competitif.
         *
         * `0.0` = repartition strictement egale (modele Premier League
         * pousse a l'extreme), `1.0` = merite pur (echelle lineaire du
         * premier au dernier). Le monde reel se situe entre les deux.
         *
         * **Defaut a 0.0, volontairement.** A cette valeur chaque club
         * touche exactement `clubIncomePerSeasonCents`, soit le comportement
         * plat d'avant l'introduction de ce champ : le monde par defaut
         * reste bit-identique, les mesures des Phases 0/1 gardent leur
         * validite, et l'effet du merite se mesure par comparaison a graines
         * appariees (`--set meritShare=...`) avant de devenir un defaut.
         * Meme discipline que la correction 5M -> 70M ci-dessus : on ne
         * change un defaut qu'avec la mesure a l'appui.
         */
        public float $meritShare = 0.0,
        /**
         * Cout d'entretien annuel d'un point de qualite d'installations, a
         * `quality = 1.0` (qualite moyenne) - au-dela, le cout croit avec le
         * **carre** de la qualite, pas lineairement : un club a
         * `quality = 2.0` (le maximum) paie quatre fois ce montant, pas deux
         * (docs/14- §6, table des puits : "amortissement des
         * infrastructures").
         *
         * Convexe plutot que lineaire pour amortir la boucle "succes ->
         * argent -> meilleurs joueurs -> succes" (docs/14- §7) : la version
         * lineaire laissait un club riche convertir indefiniment ses revenus
         * en qualite sans jamais vraiment ralentir, la contre-reaction restant
         * trop faible face au revenu au merite. Un centre de formation de
         * classe mondiale n'est pas deux fois plus cher qu'un centre correct,
         * il l'est nettement plus - se defend dans la fiction comme dans
         * l'equilibrage. C'est ce qui empeche un club riche de convertir
         * indefiniment ses revenus en qualite, sans avoir a lui imposer un
         * plafond arbitraire. La borne haute de `Facilities` existe, mais
         * l'entretien mord bien avant elle, et mord plus fort a l'approche du
         * sommet.
         *
         * Convexe **continue** (le carre), pas par paliers - meme raisonnement
         * que `FacilitiesBalance::$centsPerQualityPoint` : un seuil fixe
         * creerait un effet de falaise autour d'un niveau de qualite precis,
         * la ou le continu garde la qualite d'equilibre une fonction monotone
         * et lisse de la richesse du club. Vaut `$facilityUpkeepPerQualityPointCents`
         * exactement a `quality = 1.0`, ce qui est deliberement le meme point
         * neutre que la version lineaire qu'il remplace.
         */
        public int $facilityUpkeepPerQualityPointCents = 14_000_000,
        /**
         * Tresorerie qu'un club garde avant d'envisager le moindre
         * investissement. En dessous, il ne finance que son entretien.
         */
        public int $facilityInvestmentReserveCents = 50_000_000,
        /**
         * Plafond investi par saison, quelle que soit la tresorerie
         * disponible. Sans lui, un club assis sur des annees d'excedent
         * sauterait a la qualite maximale en une seule saison - une marche
         * que ni le harness ni la narration ne sauraient lire.
         */
        public int $facilityInvestmentMaxPerSeasonCents = 40_000_000,
        /** Jour de la semaine (`tick % 7`) ou les salaires sont verses a chaque joueur sous `Contract`. */
        public int $wagePaymentDayOfWeek = 0,
    ) {
    }
}
