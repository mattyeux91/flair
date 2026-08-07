<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Systems;

use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Pipeline\System;
use Flair\Kernel\Core\Pipeline\SystemContext;
use Flair\Kernel\Core\Ruleset\FinanceBalance;
use Flair\Kernel\Football\Components\Contract;
use Flair\Kernel\Football\Components\Facilities;
use Flair\Kernel\Football\Components\Finances;
use Flair\Kernel\Football\Components\SeasonIncome;
use Flair\Kernel\Football\Events\ClubInvestedInFacilities;
use Flair\Kernel\Football\Events\SeasonConcluded;
use Flair\Kernel\Football\Events\TransferAgreed;
use Flair\Kernel\Football\Singletons\MonetaryMass;

/**
 * Le grand livre monetaire (docs/14-algorithmes.md §6, docs/15-roadmap.md §4
 * Phase 2) : une injection (l'enveloppe des droits TV, repartie entre les
 * clubs en fin de saison), deux puits (salaires, entretien et investissement
 * des installations), et depuis docs/17- point 4 un **mouvement interne** qui
 * n'est ni l'un ni l'autre - l'indemnite de transfert. Aucun RNG, aucune
 * variance aleatoire : tout ce qui differencie deux clubs vient de leur
 * classement.
 *
 * ## Un seul systeme, pas deux
 *
 * Revenus et salaires touchent tous les deux `Finances` en ajustant un
 * solde - la meme forme de mutation, contrairement a la retraite
 * (retrait d'archetype) et la progression des competences (mutation de
 * valeur) que `RetirementSystem`/`PlayerDevelopmentSystem` separent
 * justement parce qu'elles n'ont pas la meme forme. Deux systemes qui
 * ecriraient tous les deux `Finances` violeraient l'invariant "un seul
 * writer par composant" (`Football\PipelineInvariantsTest`). Ce systeme
 * reunit donc les deux mouvements, reactif pour l'un, periodique pour
 * l'autre.
 *
 * ## Reactif sur `SeasonConcluded`, pas un jour-de-l'annee invente
 *
 * Le revenu de saison reagit a `Football\Events\SeasonConcluded` (emis par
 * `Football\CompetitionSystem`) plutot que de deriver son propre
 * `tick % 365` : reutilise le decoupage en saisons deja porte par le
 * calendrier au lieu d'en inventer un second. Aucun besoin du canal 1 ici
 * (docs/13- §2) - le credit n'a pas a etre visible le jour meme par un
 * autre systeme.
 *
 * Ce systeme ne lit **jamais** `Standings`, et ne le peut pas : son writer
 * `CompetitionSystem` est place plus loin dans le pipeline, ce que
 * `Football\PipelineInvariantsTest` interdit de lire (dependance inversee).
 * Le classement final arrive donc par le payload de l'evenement, ce qui a
 * l'avantage collateral de rendre ce systeme indifferent a la forme de
 * `Standings`.
 *
 * ## La repartition
 *
 * L'enveloppe totale vaut `clubIncomePerSeasonCents x nombre de clubs` -
 * elle ne depend donc pas du classement, seule sa **repartition** en depend
 * (docs/14- §7, "partage des droits TV" comme levier d'equilibre
 * competitif) :
 *
 * ```
 * meritPool  = round(pot x meritShare)
 * equalPool  = pot - meritPool          (somme exacte, aucune derive)
 * poids(rang) = N - rang                (rang 0-indexe : 1er -> N, dernier -> 1)
 * part(club)  = equalPool/N + meritPool x poids / (N(N+1)/2)
 * ```
 *
 * Ponderation lineaire (l'echelle de merite de la Premier League), sans
 * parametre de courbure : un seul levier a la fois tant que le harness n'a
 * pas mesure l'effet de celui-la. A `meritShare = 0` (le defaut) chaque club
 * touche exactement `clubIncomePerSeasonCents` - strictement le comportement
 * plat d'avant ce lot, division entiere exacte comprise.
 *
 * `meritShare` est clampe a [0, 1] ici plutot que valide a la construction du
 * `Ruleset` : au-dela de 1, `equalPool` deviendrait negatif et le monde
 * injecterait de l'argent negatif chez les derniers du classement. La
 * conservation monetaire resterait vraie (le bookkeeping suit les montants
 * reels), mais le monde n'aurait plus de sens - un clamp est plus sur qu'une
 * exception dans un noyau qui doit tourner 1 000 saisons sans surveillance.
 *
 * **Le reste des divisions entieres n'est pas injecte** : `pot` est un
 * plafond, pas une quantite a epuiser. `MonetaryMass` accumule les montants
 * **reellement credites**, jamais le `pot` theorique - c'est ce qui garde
 * l'invariant de conservation vrai par construction plutot que par
 * arrondi chanceux.
 *
 * **Un classement vide annule la part au merite**, quel que soit
 * `meritShare` : la premiere saison d'un monde n'a aucun match joue, donc
 * rien a recompenser. Sans ce cas particulier, les clubs seraient ordonnes
 * par `clubId` faute de mieux et le plus petit identifiant du monde toucherait
 * plusieurs fois le revenu du plus grand - une hierarchie arbitraire gravee a
 * la creation du monde, que le harness mesurerait ensuite comme une vraie
 * inegalite. Un classement **partiel** reste en revanche honore : un club qui
 * n'a joue aucun match n'a merite aucune prime, il passe en fin de
 * classement.
 *
 * ## Position dans le pipeline
 *
 * Apres `RetirementSystem` : ce systeme lit `Contract`, et
 * `RetirementSystem` le retire sur retraite. `PipelineInvariantsTest`
 * interdit a un systeme de lire un composant qu'un systeme plus loin dans
 * le pipeline retire - `FinanceSystem` doit donc venir apres, jamais avant.
 *
 * ## `MonetaryMass`, et pourquoi pas un test qui recalcule analytiquement
 *
 * `MonetaryMass` (premier singleton du domaine football) accumule les
 * memes montants que ceux ecrits dans `Finances`, dans le meme appel :
 * c'est un sous-produit direct de la boucle, jamais une reconstruction
 * independante. La population sous contrat n'est pas un input
 * deterministe (intake et retraite sont stochastiques,
 * `Football\YouthIntakeSystem`/`Football\RetirementSystem`) - un test qui
 * recalculerait le total attendu a partir du seul `Ruleset` devrait donc
 * dupliquer le suivi de population que fait deja `Harness\Metrics\Sampler`
 * ailleurs, avec son propre risque de divergence.
 *
 * ## Pas d'evenement emis
 *
 * Un versement de salaire ou un credit de saison est de la comptabilite de
 * routine : ni seuil comportemental franchi, ni irreversible, ni
 * racontable (docs/16-evenements-et-cascades.md §2), et aucun consommateur
 * n'existe encore. Emettre un Fait par joueur et par semaine sur 20 saisons
 * reproduirait exactement le piege que docs/15- §5 proscrit ("3 millions
 * d'evenements de bruit par saison").
 *
 * ## Limite connue, non corrigee dans ce lot
 *
 * Ce systeme credite tous les clubs portant `Finances` a chaque
 * `SeasonConcluded`, sans distinguer de competition - correct tant qu'une
 * seule competition existe (Phase 0/1). Si une deuxieme competition demarre
 * sa saison le meme tick, chaque club serait credite deux fois : meme
 * limite, deja documentee, que `Football\CalendarSystem` aujourd'hui. A
 * revisiter quand `CompetitionMembership` existera.
 */
final class FinanceSystem implements System
{
    public function id(): string
    {
        return 'finance';
    }

    /** @return list<class-string> */
    public function reads(): array
    {
        return [
            Finances::class,
            Contract::class,
            Facilities::class,
            MonetaryMass::class,
        ];
    }

    /** @return list<class-string> */
    public function writes(): array
    {
        return [
            Finances::class,
            SeasonIncome::class,
            MonetaryMass::class,
        ];
    }

    /** @return list<class-string> */
    public function removes(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function creates(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function subscribesTo(): array
    {
        return [
            SeasonConcluded::class,
            TransferAgreed::class,
        ];
    }

    public function handle(DomainEvent $event, SystemContext $ctx): void
    {
        if ($event instanceof TransferAgreed) {
            $this->settleTransfer($ctx, $event);

            return;
        }

        if (!$event instanceof SeasonConcluded) {
            return;
        }

        $finance = $ctx->ruleset()->balance->finance;
        $clubIds = $ctx->read(Finances::class)->entities();
        $clubCount = \count($clubIds);

        if ($clubCount === 0) {
            return;
        }

        $meritShare = $event->finalRanking === [] ? 0.0 : max(0.0, min(1.0, $finance->meritShare));
        $pot = $finance->clubIncomePerSeasonCents * $clubCount;
        $meritPool = (int) round($pot * $meritShare);
        $equalPool = $pot - $meritPool;

        $equalShare = intdiv($equalPool, $clubCount);
        $totalWeight = intdiv($clubCount * ($clubCount + 1), 2);

        $injected = 0;
        $drained = 0;

        foreach (self::orderByRank($clubIds, $event->finalRanking) as $rank => $clubId) {
            $finances = $ctx->read(Finances::class)->get($clubId);

            if ($finances === null) {
                continue;
            }

            $income = $equalShare + intdiv($meritPool * ($clubCount - $rank), $totalWeight);

            $balance = $finances->balanceCents + $income;
            $ctx->write(SeasonIncome::class)->set($clubId, new SeasonIncome($income));
            $injected += $income;

            $balance -= $this->chargeUpkeep($ctx, $clubId, $finance, $drained);
            $balance -= $this->investInFacilities($ctx, $clubId, $balance, $finance, $drained);

            $ctx->write(Finances::class)->set($clubId, new Finances($balance));
        }

        $mass = $ctx->singleton(MonetaryMass::class) ?? new MonetaryMass();
        $ctx->setSingleton(new MonetaryMass(
            $mass->totalInjectionsCents + $injected,
            $mass->totalSinksCents + $drained,
        ));
    }

    /**
     * L'indemnite de transfert : le seul mouvement d'argent du monde qui ne
     * soit **ni une injection ni un puits** (docs/14- §6, docs/17- point 4).
     * L'acheteur est debite, le vendeur credite du meme montant, et la masse
     * monetaire totale ne bouge pas d'un centime.
     *
     * ⚠️ **Ne pas toucher `MonetaryMass` ici.** C'est exactement l'endroit ou
     * une ligne « pour la symetrie » casserait
     * `Harness\Tests\Regression\MonetaryConservationTest` : le singleton
     * compte ce qui entre dans le monde et ce qui en sort, pas ce qui y
     * circule. Ce chemin est aussi le premier qui rende ce test non trivial -
     * jusqu'a ce point, aucun argent ne passait d'un club a l'autre, donc
     * l'invariant ne pouvait pas casser la ou docs/14- §6 dit qu'il compte.
     *
     * Le transfert est **atomique ou nul** : si l'un des deux clubs ne porte
     * plus `Finances` (dissous entre la conclusion et son application au tick
     * suivant), rien ne bouge. Debiter sans crediter detruirait de la monnaie.
     *
     * L'ecriture de `Contract`/`SquadMembership` n'a pas lieu ici : le joueur
     * change de club via le Fait `ContractSigned` emis en meme temps par
     * `Football\TransferSystem`, applique par `Football\SquadSystem`. Les deux
     * consequences d'un transfert suivent chacune son proprietaire de
     * composant.
     */
    private function settleTransfer(SystemContext $ctx, TransferAgreed $event): void
    {
        $buyer = $ctx->read(Finances::class)->get($event->buyerClubId);
        $seller = $ctx->read(Finances::class)->get($event->sellerClubId);

        if ($buyer === null || $seller === null || $event->buyerClubId === $event->sellerClubId) {
            return;
        }

        $ctx->write(Finances::class)->set($event->buyerClubId, new Finances($buyer->balanceCents - $event->agreedPriceCents));
        $ctx->write(Finances::class)->set($event->sellerClubId, new Finances($seller->balanceCents + $event->agreedPriceCents));
    }

    /**
     * L'entretien des installations : un puits qui croit avec le **carre** de
     * la qualite, pas lineairement (docs/14- §6/§7 - voir le docblock de
     * `FinanceBalance::$facilityUpkeepPerQualityPointCents` pour le
     * raisonnement complet). C'est lui, plus que la borne haute de
     * `Facilities`, qui empeche un club riche de convertir indefiniment ses
     * revenus en qualite - un plafond arbitraire aurait donne une marche,
     * l'entretien convexe donne un rendement decroissant qui mord plus fort
     * a mesure qu'on approche du sommet, amortissant la boucle "succes ->
     * argent -> meilleurs joueurs -> succes" de docs/14- §7.
     *
     * Un club sans `Facilities` ne paie rien : il n'a rien a entretenir.
     */
    private function chargeUpkeep(SystemContext $ctx, int $clubId, FinanceBalance $finance, int &$drained): int
    {
        $facilities = $ctx->read(Facilities::class)->get($clubId);

        if ($facilities === null) {
            return 0;
        }

        $upkeep = max(0, (int) round($finance->facilityUpkeepPerQualityPointCents * $facilities->quality ** 2));
        $drained += $upkeep;

        return $upkeep;
    }

    /**
     * Ce que le club consacre a ameliorer ses installations : tout ce qui
     * depasse sa reserve, plafonne par saison. Un puits lui aussi - cet
     * argent quitte le monde (docs/14- §6, "amortissement des
     * infrastructures"), il n'est transfere a personne.
     *
     * **Un club deja au plafond n'investit pas.** Sans ce test, son argent
     * disparaitrait sans contrepartie : `Football\FacilitiesSystem` clamperait
     * la qualite en silence et le club paierait pour rien. C'est la raison
     * pour laquelle la borne vit sur `Facilities` et non dans le `Ruleset` de
     * `FacilitiesSystem` - ce systeme-ci doit pouvoir la consulter sans
     * dependre des leviers d'un autre.
     *
     * L'ecriture de `Facilities` n'a pas lieu ici : elle appartient a
     * `FacilitiesSystem`, qui recoit le Fait au tick suivant (voir le
     * docblock de `Football\Events\ClubInvestedInFacilities` pour pourquoi ce
     * detour est structurellement force).
     */
    private function investInFacilities(SystemContext $ctx, int $clubId, int $balance, FinanceBalance $finance, int &$drained): int
    {
        $facilities = $ctx->read(Facilities::class)->get($clubId);

        if ($facilities === null || $facilities->quality >= Facilities::MAX_QUALITY) {
            return 0;
        }

        $invested = min(
            max(0, $balance - $finance->facilityInvestmentReserveCents),
            max(0, $finance->facilityInvestmentMaxPerSeasonCents),
        );

        if ($invested === 0) {
            return 0;
        }

        $drained += $invested;
        $ctx->emit(new ClubInvestedInFacilities($clubId, $invested), entityId: $clubId);

        return $invested;
    }

    /**
     * Les clubs a crediter, du premier au dernier : ceux du classement final
     * d'abord, dans son ordre, puis ceux qui n'y figurent pas (club sans
     * aucun match joue, premiere saison d'un monde) par `clubId` croissant.
     *
     * Cet ordre n'est pas celui de `ComponentStore::entities()`, et c'est
     * assume : c'est un ordre **total et deterministe** derive du payload
     * d'un Fait, pas l'ordre d'insertion d'une Map que docs/12- §2 proscrit.
     * `$ranking` peut contenir des clubs qui ne portent plus `Finances` - ils
     * sont ignores plutot que de decaler les rangs suivants.
     *
     * @param list<int> $clubIds trie par clubId croissant
     * @param list<int> $ranking classement final, du premier au dernier
     * @return list<int>
     */
    private static function orderByRank(array $clubIds, array $ranking): array
    {
        $remaining = array_fill_keys($clubIds, true);
        $ordered = [];

        foreach ($ranking as $clubId) {
            if (isset($remaining[$clubId])) {
                $ordered[] = $clubId;
                unset($remaining[$clubId]);
            }
        }

        foreach ($clubIds as $clubId) {
            if (isset($remaining[$clubId])) {
                $ordered[] = $clubId;
            }
        }

        return $ordered;
    }

    public function update(SystemContext $ctx): void
    {
        $finance = $ctx->ruleset()->balance->finance;

        if ($ctx->tick % 7 !== $finance->wagePaymentDayOfWeek) {
            return;
        }

        $paid = 0;

        foreach ($ctx->read(Contract::class)->entities() as $playerId) {
            $contract = $ctx->read(Contract::class)->get($playerId);

            if ($contract === null) {
                continue;
            }

            $finances = $ctx->read(Finances::class)->get($contract->clubId);

            if ($finances === null) {
                continue;
            }

            $ctx->write(Finances::class)->set($contract->clubId, new Finances($finances->balanceCents - $contract->wagePerWeekCents));
            $paid += $contract->wagePerWeekCents;
        }

        $mass = $ctx->singleton(MonetaryMass::class) ?? new MonetaryMass();
        $ctx->setSingleton(new MonetaryMass($mass->totalInjectionsCents, $mass->totalSinksCents + $paid));
    }
}
