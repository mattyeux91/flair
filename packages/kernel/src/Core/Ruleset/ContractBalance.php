<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Ruleset;

/**
 * Leviers du cycle de vie des contrats (docs/14-algorithmes.md §5/§6,
 * docs/15-roadmap.md §4 Phase 2), lus par `Football\ContractSystem` - et,
 * pour le seul modele de salaire, par `Football\Support\WageModel` a qui ce
 * groupe est passe en entier.
 *
 * Coupure avec `FinanceBalance` sur la meme regle que
 * `FinanceBalance`/`FacilitiesBalance` : la finance decide **quel argent
 * entre et sort** du club (revenus, entretien, investissement), le contrat
 * decide **combien coute un joueur et combien de temps**. `ContractSystem`
 * lit tout de meme `Finances` pour savoir si le club peut payer - il lit le
 * composant, jamais les leviers de `FinanceSystem`.
 */
final readonly class ContractBalance
{
    public function __construct(
        /**
         * Le jour de l'annee simulee ou tous les contrats arrives a terme
         * sont traites, et le seul ou des joueurs changent de club.
         *
         * Volontairement identique a
         * `YouthIntakeBalance::$intakeDayOfYear` : un monde n'a besoin que
         * d'un moment administratif annuel, et comme `ContractSystem` est
         * place **apres** `YouthIntakeSystem` dans le pipeline, il voit les
         * recrues du jour meme (canal 1, docs/13- §2) - un club qui vient de
         * promouvoir cinq jeunes en tient compte immediatement dans son
         * effectif plutot qu'un an plus tard.
         *
         * Meme caractere provisoire que le modulo de l'intake : une vraie
         * fenetre de mercato (plusieurs tours, docs/14- §5) remplacera ce
         * jour unique quand le marche des transferts existera.
         */
        public int $renewalDayOfYear = 180,
        /**
         * Bornes de la duree d'un contrat, en annees entieres. Tirees par
         * joueur, ce qui **etale** les echeances : avec une duree fixe, une
         * cohorte signee la meme annee reviendrait sur le marche en bloc, et
         * l'effectif d'un club oscillerait au lieu de tourner.
         *
         * Deux ans au minimum pour qu'un joueur ne repasse pas sur le marche
         * l'annee suivant son arrivee ; quatre au maximum pour qu'environ un
         * tiers de l'effectif soit renegocie chaque annee - assez pour que le
         * monde bouge, assez peu pour qu'un club garde une identite d'une
         * saison a l'autre.
         */
        public int $minDurationYears = 2,
        public int $maxDurationYears = 4,
        /**
         * L'effectif que chaque club cherche a tenir, et le plafond qu'il ne
         * depasse jamais : au-dela, il ne renouvelle plus ses joueurs les plus
         * faibles. En pratique c'est `wageBudgetShare` qui mord en premier au
         * calibrage par defaut - ce plafond ne devient contraignant qu'a
         * revenus inegaux (`meritShare > 0`), ou il empeche le club le plus
         * riche d'accumuler un effectif sans fin.
         *
         * **Aucun plancher symetrique**, et c'est un choix mesure. Un club
         * arbitre librement entre peu de bons joueurs et beaucoup de moyens.
         * Un `minSquadSize = 16` a ete essaye : sur six graines appariees il ne
         * change pas le Gini des titres (0,521 contre 0,557) et **detruit** le
         * seul gain consistant du lot des contrats, la rotation du top 5
         * (49,2 % contre 53,3 %, et 4 graines sur 6 au lieu de 6 sur 6).
         * Retire.
         *
         * Ce paragraphe justifiait aussi l'absence de plancher par le fait que
         * concentrer le budget etait avantageux, `Football\MatchSystem` notant
         * la **moyenne** de l'effectif. **Ce n'est plus vrai depuis le lot des
         * postes (2026-08-04)** : le systeme note le onze aligne, donc la
         * valeur marginale d'un joueur est positive jusqu'au onzieme et nulle
         * au-dela, jamais negative. La mesure sur `minSquadSize` reste valide
         * telle quelle, mais son motif d'origine a disparu.
         *
         * Ce plafond n'est plus le seul frein a la composition : depuis le meme
         * lot, un club comble d'abord ses **postes manquants** avant de prendre
         * le meilleur joueur disponible, et ne libere jamais son dernier joueur
         * a un poste dont il a besoin - meme au-dessus de sa cible d'effectif.
         *
         * Calibre sur la population stationnaire mesuree en Phase 0 (~320
         * joueurs pour 18 clubs, soit ~17,8 par club, docs/15- §4) avec une
         * marge : une cible **au-dessus** de la moyenne garde le chomage
         * marginal. Une cible en dessous produirait un vivier permanent de
         * joueurs sans club qui se degradent sans jamais retrouver d'emploi -
         * plausible dans le vrai football, mais un puits de population qu'on
         * ne veut pas ouvrir sans le mesurer.
         */
        public int $targetSquadSize = 20,
        /**
         * Le salaire hebdomadaire d'un joueur exactement a
         * `referenceQuality`. Reprend la valeur historique de
         * `YouthIntakeBalance::$basePlayerWagePerWeekCents`, qui reste le
         * salaire forfaitaire d'un contrat d'academie (voir le docblock de
         * `Football\YouthIntakeSystem`).
         */
        public int $baseWagePerWeekCents = 50_000,
        /**
         * La qualite qui vaut exactement `baseWagePerWeekCents`. 50 sur une
         * echelle de competences bornee a [1, 100] : le milieu, donc un
         * multiplicateur median proche de 1 quel que soit l'etat du monde.
         */
        public int $referenceQuality = 50,
        /**
         * Bornes du multiplicateur de salaire, forme bornee imposee par
         * docs/14- §3 (ne jamais composer des facteurs libres). Reprennent
         * les bornes que ce document donne pour la valorisation d'un joueur :
         * un tres bon joueur coute 2,5 fois le salaire de reference, jamais
         * dix fois. C'est ce clamp qui empeche le salaire - premier vrai prix
         * du monde - de faire exploser la masse salariale des que la
         * distribution de talent derive.
         */
        public float $wageMultiplierMin = 0.4,
        public float $wageMultiplierMax = 2.5,
        /**
         * La part du revenu de la saison ecoulee (`SeasonIncome`) qu'un club
         * accepte de consacrer a sa masse salariale annuelle. C'est le frein
         * economique du lot : sans lui, le salaire indexe sur la qualite
         * laisserait un club accumuler les meilleurs joueurs sans club en creusant
         * son solde, et la boucle riche-s'enrichit de docs/14- §7 rouvrirait
         * par la porte du marche juste apres avoir ete amortie par l'entretien
         * convexe.
         *
         * **Une part d'un revenu, pas un plancher de tresorerie.** La regle
         * evidente - "garder N centimes en caisse", alignee sur
         * `FinanceBalance::$facilityInvestmentReserveCents` - ne tient pas aux
         * ordres de grandeur du monde : un club demarre a 100 000 EUR et la
         * reserve d'investissement vaut 500 000 EUR, donc plus personne ne
         * signerait rien, jamais. Le budget salarial d'un club de football se
         * raisonne d'ailleurs en pourcentage du chiffre d'affaires, pas en
         * solde bancaire.
         *
         * `SeasonIncome` est un **composant** ecrit par `FinanceSystem`, place
         * plus tot dans le pipeline : le lire ne viole ni l'ordre des
         * dependances ni la regle "un systeme ne depend jamais des leviers
         * d'un autre" - c'est le resultat qui est lu, pas le levier qui l'a
         * produit.
         *
         * Un club sans `SeasonIncome` (la premiere annee d'un monde, avant la
         * moindre saison achevee) n'est **pas** contraint plutot que contraint
         * a zero : aucune donnee ne justifie encore de lui refuser quoi que ce
         * soit.
         */
        public float $wageBudgetShare = 0.7,
    ) {
    }
}
