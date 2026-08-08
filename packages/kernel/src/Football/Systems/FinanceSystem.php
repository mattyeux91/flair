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
use Flair\Kernel\Football\Singletons\MarketInflation;
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
    /**
     * Les salaires sont verses a la semaine mais la solvabilite se raisonne a
     * l'annee - c'est l'horizon sur lequel un club arbitre, et le seul qui se
     * compare a `SeasonIncome`. Meme constante, meme motif, que
     * `Football\ContractSystem`.
     */
    private const int WEEKS_PER_YEAR = 52;

    /**
     * Un montant nominal du `Ruleset` porte a l'unite monetaire du jour
     * (docs/17- point 5).
     *
     * Le clamp n'est pas decoratif : PHP bascule silencieusement un
     * depassement d'`int` en `float`, et `(int) round(PHP_INT_MAX * 1.0)`
     * rend un entier **negatif**. Un `Ruleset` qui met une reserve
     * d'investissement a `PHP_INT_MAX` pour la rendre inatteignable obtenait
     * ainsi l'exact inverse - le club investissait tout. Meme famille de piege
     * que le PRNG 32 bits de docs/11- §6, et trouve de la meme facon : par un
     * test qui a casse.
     */
    private static function scaled(int $nominalCents, float $index): int
    {
        $scaled = round($nominalCents * $index);

        // Comparaisons explicites plutot qu'un `min`/`max` suivi d'un cast :
        // `(float) PHP_INT_MAX` vaut deja 2^63, un cran **au-dessus** du plus
        // grand entier representable, donc le clamper puis le caster deborde
        // quand meme. C'est le meme piege une couche plus bas.
        if ($scaled >= (float) PHP_INT_MAX) {
            return PHP_INT_MAX;
        }

        if ($scaled <= (float) PHP_INT_MIN) {
            return PHP_INT_MIN;
        }

        return (int) $scaled;
    }

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
            MarketInflation::class,
        ];
    }

    /** @return list<class-string> */
    public function writes(): array
    {
        return [
            Finances::class,
            SeasonIncome::class,
            MonetaryMass::class,
            MarketInflation::class,
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

        // L'etat monetaire de la saison **passee** : c'est lui qui dimensionne
        // l'enveloppe d'aujourd'hui. Le decalage d'une saison n'est pas une
        // approximation, c'est ce qui empeche le regulateur de se lire
        // lui-meme.
        $inflation = $ctx->singleton(MarketInflation::class) ?? new MarketInflation();

        $meritShare = $event->finalTable === [] ? 0.0 : max(0.0, min(1.0, $finance->meritShare));

        // L'enveloppe, en deux morceaux qui ne disent pas la meme chose : le
        // nominal porte a l'unite du jour, et la croissance que le stock de
        // monnaie doit prendre cette saison pour suivre l'unite. Ce second
        // terme est **connu analytiquement**, d'ou une boucle ouverte : le
        // calculer est ce qui evite d'avoir a le chercher par asservissement,
        // et c'est l'asservissement qui s'etait revele instable.
        //
        // `max(0, ...)` coupe l'anticipation pendant le transitoire de
        // demarrage : il n'y a rien a faire croitre tant que le monde est
        // insolvable, et l'y appliquer quand meme le ferait sortir du
        // transitoire avec un coussin durablement trop gros.
        $inflationBalance = $ctx->ruleset()->balance->inflation;
        $pot = self::scaled($finance->clubIncomePerSeasonCents * $clubCount, $inflation->index)
            + self::scaled(max(0, $inflation->massCents), $inflationBalance->marketInflationTarget);
        $meritPool = (int) round($pot * $meritShare);
        $equalPool = $pot - $meritPool;

        $equalShare = intdiv($equalPool, $clubCount);
        $totalWeight = intdiv($clubCount * ($clubCount + 1), 2);

        $injected = 0;
        $drained = 0;

        foreach (self::orderByRank($clubIds, $event->ranking()) as $rank => $clubId) {
            $finances = $ctx->read(Finances::class)->get($clubId);

            if ($finances === null) {
                continue;
            }

            $income = $equalShare + intdiv($meritPool * ($clubCount - $rank), $totalWeight);

            $balance = $finances->balanceCents + $income;
            $ctx->write(SeasonIncome::class)->set($clubId, new SeasonIncome($income));
            $injected += $income;

            $balance -= $this->chargeUpkeep($ctx, $clubId, $finance, $inflation->index, $drained);
            $balance -= $this->investInFacilities($ctx, $clubId, $balance, $finance, $inflation->index, $drained);

            $ctx->write(Finances::class)->set($clubId, new Finances($balance));
        }

        $mass = $ctx->singleton(MonetaryMass::class) ?? new MonetaryMass();
        $ctx->setSingleton(new MonetaryMass(
            $mass->totalInjectionsCents + $injected,
            $mass->totalSinksCents + $drained,
        ));

        $ctx->setSingleton($this->regulate($ctx, $clubIds, $inflation));
    }

    /**
     * Le regulateur monetaire (docs/14- §6 « Cible de regulation »,
     * docs/17-marche-transferts.md point 5), execute une fois par saison,
     * **apres** la repartition - il mesure sur les soldes qu'on vient
     * d'ecrire, et son resultat dimensionne l'enveloppe de la saison
     * *suivante*. Ce decalage d'une saison est structurel : dimensionner
     * l'enveloppe avec un indice calcule apres l'avoir versee reviendrait a
     * lire sa propre sortie.
     *
     * Il fait deux choses, et **aucune boucle fermee** :
     *
     * 1. **Avancer l'indice** de `marketInflationTarget`. L'indice est une
     *    decision de politique monetaire, pas une mesure - le monde n'a aucune
     *    inflation endogene (sans intervention, masse et masse salariale sont
     *    plates trente saisons durant, parce que salaires et valeurs sont des
     *    formules du `Ruleset` et non des prix d'equilibre). Le taux realise
     *    egale donc la cible par construction, et docs/14- §6 l'assume :
     *    « une economie administree, pas une economie libre ».
     * 2. **Relever la masse et la masse salariale**, qui alimentent
     *    l'enveloppe de la saison suivante : sa part nominale suit l'indice,
     *    et un terme d'anticipation `cible x masse` fait grandir le stock au
     *    meme rythme, sans quoi le coussin de tresorerie fondrait en termes
     *    reels pendant que les salaires s'indexent.
     *
     * ## Le correcteur proportionnel a ete retire
     *
     * Une version anterieure asservissait l'enveloppe sur la solvabilite.
     * Construit, **mesure instable deux fois**, retire (docs/17- point 5) : la
     * grandeur asservie a un denominateur endogene qui bouge dans le mauvais
     * sens - moins d'emploi donne une masse salariale plus petite, donc une
     * solvabilite plus haute, donc un regulateur qui coupe encore. Ce qui
     * reste est en boucle ouverte, donc stable par construction. `$solvency`
     * survit comme **observable**, lue par le harness, jamais comme entree de
     * commande.
     *
     * `MonetaryMass` n'est pas lu ici, et c'est delibere : il porte des
     * **cumuls** d'injections et de puits depuis la creation du monde, pas la
     * masse en circulation. La masse, c'est la somme des soldes - la meme
     * grandeur que `Harness\Tests\Regression\MonetaryConservationTest` compare
     * au bookkeeping.
     *
     * @param list<int> $clubIds
     */
    private function regulate(SystemContext $ctx, array $clubIds, MarketInflation $previous): MarketInflation
    {
        $balance = $ctx->ruleset()->balance->inflation;
        $mass = 0;

        foreach ($clubIds as $clubId) {
            $finances = $ctx->read(Finances::class)->get($clubId);
            $mass += $finances === null ? 0 : $finances->balanceCents;
        }

        $wageBill = 0;

        foreach ($ctx->read(Contract::class)->entities() as $playerId) {
            $contract = $ctx->read(Contract::class)->get($playerId);
            $wageBill += $contract === null ? 0 : $contract->wagePerWeekCents * self::WEEKS_PER_YEAR;
        }

        // L'indice avance **toujours** : c'est une decision de politique
        // monetaire, elle ne depend pas de l'etat de l'effectif. Seule la
        // solvabilite, qui est une observation, a besoin d'un denominateur -
        // un monde sans le moindre contrat n'en a pas de definie.
        $index = $previous->index * (1.0 + $balance->marketInflationTarget);
        $realized = $previous->index > 0.0 ? $index / $previous->index - 1.0 : 0.0;

        return new MarketInflation(
            $index,
            $realized,
            $wageBill > 0 ? $mass / $wageBill : $previous->solvency,
            $wageBill,
            $mass,
        );
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
     *
     * Indexe comme tout montant nominal du `Ruleset` (docs/17- point 5) : un
     * changement d'unite monetaire qui n'indexerait qu'une partie des prix
     * n'en serait pas un, et laisser l'entretien nominal le ferait valoir le
     * tiers de sa valeur reelle en quarante saisons a 3 % - les clubs
     * sur-investiraient et le monde decrocherait par ce bout-la.
     */
    private function chargeUpkeep(SystemContext $ctx, int $clubId, FinanceBalance $finance, float $index, int &$drained): int
    {
        $facilities = $ctx->read(Facilities::class)->get($clubId);

        if ($facilities === null) {
            return 0;
        }

        $upkeep = max(0, self::scaled((int) round($finance->facilityUpkeepPerQualityPointCents * $facilities->quality ** 2), $index));
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
    private function investInFacilities(SystemContext $ctx, int $clubId, int $balance, FinanceBalance $finance, float $index, int &$drained): int
    {
        $facilities = $ctx->read(Facilities::class)->get($clubId);

        if ($facilities === null || $facilities->quality >= Facilities::MAX_QUALITY) {
            return 0;
        }

        $invested = min(
            max(0, $balance - self::scaled($finance->facilityInvestmentReserveCents, $index)),
            max(0, self::scaled($finance->facilityInvestmentMaxPerSeasonCents, $index)),
        );

        if ($invested === 0) {
            return 0;
        }

        $drained += $invested;

        // Deux montants, deux grandeurs. Le club a bien sorti `$invested` de sa
        // caisse - c'est ce que le grand livre draine et ce qu'un journal doit
        // enregistrer. Mais ce que cette somme *achete* en beton se compte a
        // l'unite de reference, sinon un club batirait plus vite a mesure que
        // la monnaie change d'unite.
        //
        // Le second champ existe parce que `Football\FacilitiesSystem` ne peut
        // pas lire l'indice lui-meme : il ecrit `Facilities` que ce systeme
        // lit, donc l'arete existe deja dans ce sens et l'inverse ferait un
        // **cycle** que `Core\Pipeline\SystemGraph` leverait au montage.
        $ctx->emit(new ClubInvestedInFacilities(
            $clubId,
            $invested,
            $index > 0.0 ? (int) round($invested / $index) : $invested,
        ), entityId: $clubId);

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
