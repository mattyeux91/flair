<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Singletons;

/**
 * L'etat monetaire du monde vu comme un **niveau de prix** (docs/14- §5/§6,
 * docs/17-marche-transferts.md point 5). Second singleton du domaine football,
 * sibling de `MonetaryMass` exactement comme docs/12- §3 bis l'anticipait
 * (« MarketInflation : indice global, masse monetaire, tendance »).
 *
 * ## Etat, jamais regle
 *
 * La frontiere de docs/12- §3 bis : `Ruleset\InflationBalance::$marketInflationTarget`
 * est une **regle** decidee par un humain, `MarketInflation::$annualRate` est
 * l'**etat** que la simulation produit. Le regulateur lit les deux et corrige
 * l'ecart. `$injectionTrim` est de l'etat lui aussi - c'est la position
 * courante du regulateur, pas un levier.
 *
 * ## Pourquoi l'indice est une **politique** et non une mesure
 *
 * L'esquisse de docs/14- §5 fait suivre a l'indice « la masse monetaire du
 * monde », et §6 fait ajuster au regulateur « les injections marginales pour
 * tenir la cible ». Deux mesures ont montre que ce montage ne tient pas ici :
 *
 * 1. **Le monde n'a aucune inflation endogene.** Sans regulateur, sa masse
 *    monetaire est *plate* a ~620M centimes et sa masse salariale a ~830M/an,
 *    pendant trente saisons d'affilee. Rien ne pousse les prix : salaires et
 *    valeurs sont des formules du `Ruleset` (`base x qualite / reference`),
 *    pas des prix d'equilibre. Aucune quantite de monnaie ne les fait bouger.
 * 2. **La masse ne peut pas servir de denominateur.** Elle est negative les
 *    neuf premieres saisons - une annee entiere de salaires est versee avant
 *    la premiere enveloppe - donc elle **traverse zero**, et tout rapport a
 *    elle explose au passage.
 *
 * L'indice est donc pose comme ce qu'il est reellement : une **decision de
 * politique monetaire**, qui avance de `marketInflationTarget` par saison. Le
 * doc l'assume d'ailleurs mot pour mot : « C'est artificiel, mais assume : un
 * monde persistant est une economie administree, pas une economie libre. »
 * Le taux realise egale donc la cible **par construction**, et le critere de
 * sortie ne peut pas se contenter de le verifier.
 *
 * Ce que le regulateur regule, du coup, c'est la seule grandeur qu'il puisse
 * reellement deplacer : la **solvabilite** du monde (`$solvency`), c'est-a-dire
 * si les clubs suivent le niveau de prix ou decrochent en dessous.
 *
 * ## Ce que l'indice multiplie
 *
 * Les valeurs de transfert (`Football\Support\MarketValueModel`) **et** les
 * salaires (`Football\Support\WageModel`). C'est ce qui en fait un
 * « changement d'unite monetaire [qui] s'applique uniformement a tout le
 * marche » (docs/14- §5) plutot qu'une distorsion du prix relatif
 * indemnite/salaire. Et c'est ce qui ferme la boucle : les salaires sont le
 * principal **puits**, donc ils grossissent derriere l'indice et la masse
 * reagit a son tour - le regulateur ne peut pas atteindre sa cible d'un seul
 * coup.
 *
 * Ecrit uniquement par `Football\FinanceSystem`, une fois par saison.
 */
final readonly class MarketInflation
{
    public function __construct(
        /**
         * Le niveau de prix courant, `1.0` a la creation du monde. Il avance
         * de `marketInflationTarget` a chaque saison achevee - c'est l'unite
         * monetaire du monde, et elle est **decidee**, pas observee.
         */
        public float $index = 1.0,
        /**
         * Le taux realise sur la derniere saison, `index / index precedent - 1`.
         * Egal a la cible par construction une fois le monde lance : ce champ
         * existe pour que la mesure soit **lisible** dans le harness et dans un
         * futur client, pas pour prouver quoi que ce soit.
         */
        public float $annualRate = 0.0,
        /**
         * La solvabilite du monde : masse monetaire en circulation rapportee a
         * la masse salariale annuelle engagee. **La grandeur que le regulateur
         * regule reellement.**
         *
         * Sans intervention, elle se stabilise a ~0,73 (mesure sur 30 saisons)
         * - un monde qui garde en caisse environ neuf mois de salaires. Elle
         * est **negative** les premieres saisons, le temps qu'une annee de
         * salaires versee d'avance soit rattrapee par les enveloppes ; c'est
         * un transitoire de demarrage, pas une maladie.
         *
         * Un rapport, et non la masse elle-meme : la masse traverse zero au
         * demarrage, donc tout indice bati dessus explose au passage. Le
         * rapport, lui, se compare a une cible constante du `Ruleset`, sans
         * reference a capturer.
         */
        public float $solvency = 0.0,
        /**
         * La masse salariale annuelle engagee, relevee a la saison ecoulee.
         *
         * Elle sert au **terme d'anticipation** du regulateur : pour que la
         * solvabilite tienne pendant que les salaires s'indexent, la masse
         * doit gagner `solvencyTarget x marketInflationTarget x` cette valeur
         * a chaque saison. Cette quantite est connue analytiquement, donc on
         * la donne au regulateur au lieu de le laisser la chercher a tatons -
         * un correcteur proportionnel seul la trouve en oscillant sur des
         * dizaines de saisons, parce qu'un contrat se renegocie sur deux a
         * quatre ans et que le puits salarial repond donc avec un temps mort.
         *
         * Stockee plutot que recalculee en tete de saison : `regulate()` la
         * mesure deja, et une seconde boucle sur tous les contrats n'ajouterait
         * rien.
         */
        public int $wageBillCents = 0,
        /**
         * La masse monetaire en circulation a la saison ecoulee, en centimes.
         *
         * C'est elle, et non la masse salariale, qui porte le **terme
         * d'anticipation** du regulateur : pour que le niveau de tresorerie du
         * monde suive l'unite monetaire, la masse doit croitre de
         * `marketInflationTarget` par saison, ni plus ni moins. La formule est
         * donc `target x masse`, ce qui la rend **preservatrice de niveau** :
         * elle fait grandir le coussin existant au bon rythme sans jamais
         * decider quel doit etre son niveau.
         *
         * Negative pendant le transitoire de demarrage, ou l'anticipation est
         * coupee : rien a faire croitre tant qu'il n'y a rien. C'est cette
         * garde qui empeche le monde de sortir du transitoire avec un coussin
         * durablement trop gros - mesure a l'appui, sans elle la solvabilite
         * se stabilisait 43 % au-dessus de son niveau naturel et le chomage
         * tombait de ~35 a ~4.
         */
        public int $massCents = 0,
    ) {
    }
}
