<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Ruleset;

/**
 * Leviers d'equilibrage de l'arrivee des jeunes (docs/12- §7, docs/15- §4),
 * lus uniquement par Football\YouthIntakeSystem.
 *
 * ## Le niveau d'abstraction, et pourquoi il n'est pas celui du vrai foot
 *
 * Un centre de formation reel accueille 8 a 12 jeunes par promotion. Les
 * modeliser tous ferait exploser la population : ~180 entrees/an sur 18
 * clubs, contre ~28 sorties par la retraite - un monde multiplie par 5 en
 * quelques saisons, sans qu'aucun test ne le signale. Le vrai football ne
 * gonfle pas parce que la grande majorite des formes est liberee et quitte
 * le football professionnel ; il faudrait donc, en plus, un mecanisme
 * d'echec/liberation (un systeme, un Fait `PlayerReleased` distinct de
 * `PlayerRetired`, un composant de statut).
 *
 * Choix retenu : `intake` ne modelise pas "entre au centre de formation"
 * mais "**entre dans la population professionnelle**" - soit 1 a 3 joueurs
 * par club et par saison. Les 8-12 scolaires restent hors modele, ils
 * n'ont aucune valeur de gameplay en Phase 0 et representeraient ~2 500
 * entites inutiles (le piege "250 attributs par joueur" de docs/15- §5
 * sous un autre nom).
 *
 * Ordre de grandeur vise, a verifier et non a garantir : population ~500,
 * carriere 17->35 ans = 18 ans, soit ~28 remplacements/an, soit ~1,5 joueur
 * par club et par saison sur 18 clubs. Aucun regulateur ne force ce
 * chiffre : la stationnarite de la pyramide des ages est le critere de
 * sortie de la Phase 0 (docs/15- §4), elle doit rester une propriete
 * **emergente** a mesurer. Un intake asservi a une cible de population
 * mesurerait son propre thermostat et viderait le critere de son sens.
 *
 * Premier jet qualitatif, a calibrer via le harness (Phase 1) - cette
 * classe existe pour que ce calibrage ne touche jamais au code du systeme.
 */
final readonly class YouthIntakeBalance
{
    public function __construct(
        /**
         * Jour de l'annee simulee (`tick % 365`) ou les promotions arrivent.
         *
         * Placeholder assume : dans la realite l'entree dans la population
         * professionnelle est bornee par le calendrier (bascule de saison
         * au 1er juillet en Europe, contrats aspirant/scholarship a 16-17
         * ans), et les jeux du genre placent de meme un "youth intake day".
         * Mais `SimDate` n'est aujourd'hui qu'un compteur de jours sans
         * epoch (docs/13- §1) : ni mois ni "1er juillet" ne sont
         * exprimables, donc `tick % 365` est la seule forme disponible. A
         * remplacer par une vraie phase de saison quand le lot calendrier
         * apportera `SeasonPhase`.
         */
        public int $intakeDayOfYear = 180,
        /** Age (annees) auquel un jeune integre l'effectif professionnel. */
        public float $intakeAgeYears = 17.0,
        /** Nombre moyen de joueurs promus par club et par saison, avant modulation par `Facilities::$quality`. Le resultat fractionnaire est arrondi stochastiquement, pour que l'esperance reste exacte malgre des cohortes entieres. */
        public float $baseIntakePerClub = 1.2,
        /**
         * Bornes du `ceiling` tire pour un jeune, sur l'echelle **absolue et
         * mondiale** 1-100 de docs/12- §5 (~50 = professionnel median toutes
         * divisions confondues, ~70 = titulaire de premiere division, ~85 =
         * international, ~95 = une poignee de joueurs vivants).
         *
         * Ces bornes ne decrivent pas "le talent en general" mais **la
         * tranche dans laquelle recrute un club donne** (12- §5, corollaire).
         * Elles sont globales tant qu'aucune notion de niveau de division ni
         * de `Reputation` de club n'existe ; le monde de la Phase 0 etant une
         * seule premiere division (docs/15- §4), elles decrivent donc une
         * promotion de premiere division - pas la pyramide entiere, dont le
         * median a ~50 serait trop bas pour cette population.
         *
         * Avec `talentSkew = 3`, `[55, 95]` donne : moyenne ~65, mediane ~63,
         * p90 ~76, et **~1,6 % au-dessus de 85**. Ce dernier chiffre est le
         * garde-fou a surveiller en calibrant : une distribution qui mettrait
         * 20 % de la population au-dessus de 85 ne serait pas "genereuse",
         * elle casserait la semantique de l'echelle - 85 cesserait de vouloir
         * dire "international".
         */
        public int $ceilingMin = 55,
        public int $ceilingMax = 95,
        /**
         * Asymetrie de la loi de talent : `k` dans `min(U_1..U_k)`, qui suit
         * une Beta(1, k) - beaucoup de joueurs ordinaires, une longue queue
         * de rares talents. `k = 1` redonne l'uniforme, `k` grand concentre
         * la masse sur `ceilingMin`.
         *
         * docs/12- §7 demande une **log-normale**. On lui substitue cette
         * Beta(1, k), qui en reproduit la forme qualitative (asymetrie a
         * droite, queue longue), parce qu'une vraie log-normale exige
         * `exp`/`log`/`sqrt`/`cos` (Box-Muller). Ces fonctions viennent de
         * la libm : contrairement a `+`/`-`/`*`//` qui sont exactes au bit
         * pres en IEEE 754, elles peuvent differer d'un ulp d'une plateforme
         * ou d'une version de libc a l'autre - ce qui casserait le
         * determinisme du noyau (docs/11- §1) sans la moindre erreur. Le
         * noyau n'utilise a ce jour **aucune** fonction transcendante, et ce
         * lot ne sera pas le premier a en introduire. Si la Phase 1 montre
         * que la forme exacte de la queue compte, la remplacer par une table
         * de quantiles interpolee lineairement (donnee, arithmetique pure) -
         * pas par Box-Muller.
         */
        public int $talentSkew = 3,
        /** Fraction du `ceiling` a laquelle un jeune demarre ses competences. Un jeune est loin de son potentiel : c'est PlayerDevelopmentSystem qui l'y amene. */
        public float $startingSkillRatio = 0.45,
        /** Amplitude du bruit (en points de competence) applique de part et d'autre du niveau de depart, independamment par categorie. */
        public int $startingSkillJitter = 4,
        /**
         * Bornes des tirages uniformes des ages de pic par categorie
         * (`PlayerPotentials::$*PeakAge`). L'ordre physique < technique <
         * mental est un fait de football etabli (docs/12- §5) : ces
         * fourchettes doivent le respecter, PlayerDevelopmentSystem en
         * depend pour que le declin physique precede le declin mental.
         */
        public int $physicalPeakAgeMin = 21,
        public int $physicalPeakAgeMax = 26,
        public int $technicalPeakAgeMin = 23,
        public int $technicalPeakAgeMax = 29,
        public int $mentalPeakAgeMin = 26,
        public int $mentalPeakAgeMax = 30,
        /** Bornes du tirage uniforme de `PlayerPotentials::$growthRate` (vitesse d'approche du `ceiling`). */
        public float $growthRateMin = 0.2,
        public float $growthRateMax = 0.6,
        /** Bornes du tirage uniforme de `PlayerPotentials::$fragility` (0-1), lue par RetirementSystem comme par PlayerDevelopmentSystem. */
        public float $fragilityMin = 0.1,
        public float $fragilityMax = 0.9,
        /** Salaire hebdomadaire (centimes) du `Contract` attribue a un jeune tout juste promu. Vit ici plutot que dans `FinanceBalance` : c'est ce systeme qui cree le `Contract`, et un systeme ne depend jamais des leviers d'un autre (meme regle documentee sur `Balance`). Meme valeur de reference que `Harness\Population\PopulationFactory` applique aux joueurs du genesis, pour ne pas faire diverger les deux populations. */
        public int $basePlayerWagePerWeekCents = 50_000,
    ) {
    }
}
