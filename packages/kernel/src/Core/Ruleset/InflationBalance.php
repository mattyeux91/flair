<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Ruleset;

/**
 * La regulation monetaire du monde (docs/14-algorithmes.md §6, « Cible de
 * regulation »), lue par `Football\FinanceSystem` a qui ce groupe est passe en
 * entier.
 *
 * « Le ruleset definit un `marketInflationTarget` (ex. 3 %/an). Un regulateur
 * simple ajuste les injections marginales pour tenir la cible. C'est
 * artificiel, mais assume : **un monde persistant est une economie
 * administree, pas une economie libre.** »
 *
 * ## Deux champs seulement, et c'est un resultat de mesure
 *
 * Une version anterieure portait un correcteur proportionnel (gain, bornes,
 * cible de solvabilite) qui asservissait les injections. Il a ete **construit,
 * mesure instable deux fois, et retire** - voir docs/17- point 5 pour les
 * chiffres. La cause n'est pas un reglage : la grandeur asservie
 * (masse monetaire / masse salariale) a un **denominateur endogene qui bouge
 * dans le mauvais sens**. Moins d'emploi produit une masse salariale plus
 * petite, donc une solvabilite plus haute, donc un regulateur qui coupe encore
 * les revenus. Aucun gain ne rattrape une contre-reaction positive.
 *
 * Ce qui reste est en **boucle ouverte**, donc stable par construction :
 * l'indice avance de la cible, et les injections gagnent la croissance que la
 * masse doit prendre pour suivre. C'est exactement « ajuster les injections
 * marginales », sans asservissement.
 *
 * ## Un groupe, pas un champ nu
 *
 * L'esquisse de docs/12- §6 et le README de ce paquet annoncaient
 * `marketInflationTarget` comme champ de `Balance`, a cote de `trainingRate`.
 * Il en faut deux, et surtout le second definit un critere de sortie de phase -
 * ca merite son toit. Meme arbitrage que `MarketValueBalance`/`TransferBalance`.
 *
 * ## La frontiere avec `MarketInflation`
 *
 * docs/12- §3 bis : ce groupe porte des **regles** (ce qu'on veut), le
 * singleton porte l'**etat** (ce qu'on obtient). Rien ici ne bouge en cours de
 * partie ; tout ce qui bouge est dans le singleton.
 */
final readonly class InflationBalance
{
    public function __construct(
        /**
         * Le taux de croissance annuel de l'unite monetaire du monde, donc du
         * niveau de tous ses prix - salaires, valeurs de transfert, couts
         * d'installations, enveloppe des droits TV.
         *
         * **Defaut a `0.0`, et c'est une decision appuyee sur la mesure.** A
         * cette valeur le monde produit est **rigoureusement** celui d'avant ce
         * lot : masse 623 385 000, masse salariale 831 220 000, 45 joueurs sans
         * club, 327 actifs sur 40 saisons - identiques au centime. Le mecanisme
         * existe, il est teste, et il ne change rien tant qu'on ne l'active pas.
         *
         * A `0.03` (l'exemple de docs/14- §6), le monde reste **stable** -
         * solvabilite plate, masse salariale sur revenus a 0,64 contre 0,66 -
         * mais il **decroche sur l'emploi** : le coussin de tresorerie se
         * stabilise 43 % au-dessus de son niveau naturel, la garde de
         * solvabilite des clubs ne mord plus, et le chomage tombe de ~35 a ~2.
         * Un effet mesure, chiffre, et pour l'instant non corrige : d'ou le
         * defaut a zero plutot qu'un defaut a 3 % qu'il faudrait excuser.
         */
        public float $marketInflationTarget = 0.0,
        /**
         * La demi-largeur relative de la bande qui definit « **inflation dans
         * la cible** », c'est-a-dire le critere de sortie de la Phase 2
         * (docs/15- §4).
         *
         * Ce critere n'etait defini nulle part : ni la grandeur, ni la
         * fenetre, ni la tolerance, ni le nombre de graines. Il l'est ici, et
         * dans le `Ruleset` plutot qu'en constante de test - une exigence
         * d'equilibrage se regle comme les autres, et un monde au calibrage
         * different doit pouvoir la deplacer sans toucher au harness.
         *
         * Elle porte sur la **stabilite en termes reels** (la solvabilite ne
         * derive pas d'une saison a l'autre), pas sur le taux realise : celui-la
         * egale la cible par construction, l'indice etant une decision et non
         * une mesure. Verifier qu'il l'egale ne prouverait rien.
         */
        public float $toleranceBand = 0.10,
    ) {
    }
}
