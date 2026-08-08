# Roadmap — et les objections avant de commencer

## 1. L'objection principale : l'ordre de construction

> « Une fois que la simulation sera opérationnelle, on pourra créer des clients. »

**Je pense que cet ordre est le principal risque du projet.** Il implique 12 à 18 mois de développement en solo avant que quiconque touche quoi que ce soit. Trois conséquences :

1. Tu construis un moteur sans savoir si le jeu qu'il porte est amusant.
2. Tu n'as aucun retour correctif pendant un an — les erreurs de conception s'accumulent en silence.
3. La motivation ne tient pas 18 mois sans un monde visible.

**Contre-proposition** : chaque phase se termine par **quelque chose d'observable**. Pas forcément jouable — mais lisible. Le rapport de saison en texte pendant les trois premiers mois, c'est déjà un livrable.

Et surtout : la boucle de jeu de l'agent (le *fun*) doit être testée **hors simulation**, sur papier ou en CLI, avant d'être codée. Une simulation qui tourne n'est pas un jeu.

## 2. Le périmètre — tranché

**Décision (2026-07-31) : le concept de deckbuilder est abandonné.** Le projet est la simulation de monde persistant, avec l'incarnation d'agent comme client de jeu. La doc issue de l'idéation du 27/07 est caduque.

Conséquence directe et importante : **il n'y a plus qu'une seule boucle de jeu humaine, et elle n'est pas validée.**

Avec le deckbuilder, le joueur avait une activité serrée et testable (jouer un match). Sans lui, tout le plaisir repose sur le métier d'agent : scouter, placer, négocier, gérer une carrière. C'est un pari plus intéressant, mais il déplace le risque du technique vers le game design.

Ce que ça change dans la conduite du projet :

- La **phase 5 devient le point de rupture du projet**, pas sa cerise. Si le métier d'agent n'est pas amusant, il n'y a pas de jeu — quelle que soit la qualité de la simulation.
- Le prototype papier / CLI de la boucle d'agent passe de « souhaitable » à **prérequis**, à mener en parallèle de la phase 0.
- La tension à valider en priorité est celle de la fonction d'utilité de l'agent (cf. `14-algorithmes.md` §5) : **commission vs satisfaction du client vs réputation**. Placer un client au club le plus offrant peut le rendre malheureux, détruire la relation et donc les revenus futurs. Si cet arbitrage est intéressant sur papier, le jeu existe. Sinon, il faut le retravailler avant d'écrire une ligne du client.

Le niveau `L3` (« match joué par un humain ») disparaît du LOD du moteur de match. Le LOD reste utile pour L0/L1 — c'est le mécanisme qui permet de simuler un monde entier à coût raisonnable.

## 3. La deuxième objection : la population

Un monde persistant multijoueur meurt sans joueurs. Contrainte à intégrer dès le premier jour :

> **Le monde doit être intéressant avec un seul humain dedans, entouré à 99 % de PNJ.**

L'architecture le permet naturellement (l'humain n'est qu'une `IntentSource` parmi d'autres — cf. `11-architecture-generale.md` §3). Mais c'est une contrainte de *game design*, pas seulement de code : si le jeu n'a de sens qu'avec 500 agents humains actifs, il ne démarrera jamais.

---

## 4. Les phases

Chaque phase a un **critère de sortie mesurable**. On ne passe pas à la suivante sans.

### Phase 0 — Le noyau nu *(≈ 3 semaines)*

1 pays, 1 division, 18 clubs, ~500 joueurs. Moteur L0 uniquement. Systèmes : calendrier, match, classement, vieillissement, entraînement. Aucune DB, aucun serveur, aucun client. Sortie = un **rapport de saison en texte**.

**Les cinq briques structurantes vont dans cette phase**, parce qu'elles sont coûteuses à rajouter après coup :

| Brique | Pourquoi maintenant |
|---|---|
| **PRNG 32 bits masqué** | `13-` §4.3 — sans lui, aucun déterminisme, et le rattrapage impose de réauditer tout le code aléatoire |
| **Files In/Out** | `13-` §2 — le modèle de propagation ne se change pas une fois trente systèmes écrits |
| **Scheduler** | `13-` §3 — sinon chaque système invente son propre balayage périodique |
| **Taxonomie Fait / DecisionRequest / Intent** | `16-` §1 — renommer trois cents événements plus tard n'arrivera jamais |
| **Singletons** | `12-` §3 bis — sinon l'état global finit en variables statiques, et le noyau cesse d'être pur |

> **Critère de sortie :** simuler **20 saisons d'affilée** et obtenir un monde encore plausible — pyramide des âges stationnaire, pas de club unique dominant, distribution des scores proche du réel.

C'est la phase la plus importante du projet. Si elle échoue, rien d'autre n'a d'intérêt.

> **Mesuré empiriquement le 2026-08-02** (`packages/harness/bin/aggregate.php`, seeds 42 et 7, 500 joueurs / 18 clubs) : le critère "20 saisons" tel qu'écrit est trop court pour une population initiale de 500 joueurs répartie sur tout l'éventail d'âges (`Worldgen\WorldFactory`, alors `Harness\Population\PopulationFactory`) — elle n'est pas encore à l'équilibre d'âge à l'année 20 (effectif encore en décroissance transitoire de 459 à ~320). Deux options pour rendre le critère opérationnel : (a) partir d'une population déjà à l'équilibre d'âge (nécessiterait un mode de génération dédié, hors périmètre actuel), ou (b) mesurer sur une fenêtre de 30-40 saisons avec une population initiale large. On retient (b). Résultat sur 40 saisons : effectif stationnaire ~313-329 joueurs dès l'année ~13 (confirmé sur deux graines indépendantes), répartition domicile/nul/extérieur 41.8%/29.6%/28.6% (proche du réel), scores les plus fréquents 1-1/0-0/1-0/2-1 dans un ordre réaliste. Sur 19 saisons (seed 42) : 11 champions différents sur 18 clubs (deux clubs à 4 titres chacun, aucun quasi-monopole). **Phase 0 est close sur cette base.**

### Phase 1 — Le harness d'équilibrage *(≈ 2 semaines)*

1 000 saisons sans tête, métriques de santé du monde (Gini des titres, rotation du top 5 — le Gini des revenus et l'inflation ne sont mesurables qu'à partir de la Phase 2, une fois l'économie et le grand livre monétaire codés, cf. §4 Phase 2 et `14-` §6/§7), rapport automatique, test de régression en CI. Test de déterminisme (même graine → même hash de l'état **et** de la séquence d'événements).

Deux ajouts qui font la valeur de cette phase :

- **Comparaison à graines appariées** — le mode par défaut. On rejoue le *même* jeu de graines avant et après un changement de `ruleset.balance`, ce qui isole l'effet du bruit (`13-` §4.0). C'est ce qui rend le critère de sortie ci-dessous réellement atteignable : sans appariement, il faudrait 5 à 20 fois plus de runs pour la même confiance.
- **Métriques de graphe d'événements** — volume par type, profondeur, entités sur-modifiées, croissance des files (`16-` §6). Une boucle non amortie ne se voit pas dans les métriques métier ; elle se voit ici.

> **Critère de sortie :** modifier une valeur de `ruleset.balance` et **voir l'effet chiffré** sur la santé du monde en moins de 5 minutes.

> **Mesuré empiriquement le 2026-08-02** (`packages/harness/bin/aggregate.php`, seed 42, 500 joueurs / 18 clubs / 40 saisons) : run baseline seul ~56s ; comparaison à graines appariées complète (baseline + `--set trainingRate=1.5` + delta chiffré Gini/rotation) ~1min49s — sous la barre des 5 minutes avec large marge. Gini des titres 0.298 → 0.346 et rotation du top 5 63.7% → 62.1% entre baseline et modifié, effet lisible directement dans le rapport texte. Les briques prévues sont toutes en place : Gini des titres + rotation du top 5 (`CompetitiveBalance`), graphe d'événements opt-in (`EventGraphCollector`), test de déterminisme état+séquence d'événements (`DeterministicRunTest`), CI à deux jobs (`kernel` puis `harness`, suite `Regression` incluse). **Phase 1 est close sur cette base.**
>
> En parallèle (prérequis Phase 5, §2) : le prototype papier/CLI `prototype/agent-loop/` a confirmé que la tension commission/satisfaction/réputation est un vrai dilemme (styles de jeu divergents sur 3 graines, cf. son README) — la question qu'il devait trancher est tranchée. Conservé tel quel, en l'état de prototype, sans promotion en document de conception ni suppression.

À partir d'ici tu pilotes au lieu de deviner. C'est ton avantage sur un studio.

### Phase 2 — Économie et marché *(≈ 4 semaines)*

Finances des clubs, grand livre monétaire, contrats, marché des transferts multi-tours, perception/scouting, agents PNJ.

> **Critère de sortie :** invariant de conservation monétaire vert sur 20 saisons, et inflation dans la cible du ruleset.

> ✅ **Phase close le 2026-08-08.** Les deux moitiés du critère (la seconde réécrite, voir la note ⚠️ ci-dessous) sont **mécanisées et vertes** : `Harness\Tests\Regression\MonetaryConservationTest` (les deux cas, avec le garde-fou qui exige qu'il ait réellement circulé des indemnités) et `Harness\Tests\Regression\InflationRegressionTest` (no-op strict au défaut, stationnarité réelle à 3 %). Suites au moment de la clôture : kernel 300 tests, harness 72, Regression 7. Deux limites mesurées, chiffrées et **non corrigées**, à connaître avant la Phase 3 : le décrochage de l'emploi à 3 % d'inflation, et un marché réel mais économiquement inerte (~3 transferts par saison, indemnité médiane à 9 % de `baseValueCents`). La seconde n'est pas un défaut d'équilibrage à rattraper ici — elle tient à ce qu'aucun prix de ce monde n'est encore un prix d'**équilibre** — mais c'est le chiffre à surveiller, parce que c'est le volume de la boucle de jeu de la Phase 5.

> ⚠️ **Seconde moitié réécrite le 2026-08-07, à la fin du lot 3.** « Inflation dans la cible » n'était défini nulle part — ni la grandeur, ni la fenêtre, ni la tolérance, ni le nombre de graines — et il s'est avéré, mesure à l'appui, **sans contenu tel que formulé**. Ce monde n'a aucune inflation endogène : salaires et valeurs sont des formules du `Ruleset`, pas des prix d'équilibre, donc aucune quantité de monnaie ne les déplace (mesuré : masse et masse salariale plates trente saisons durant, sans aucun régulateur). L'indice d'inflation est donc nécessairement une **décision** de politique monétaire, et le taux réalisé égale la cible **par construction** — le vérifier ne prouve rien.
>
> L'énoncé qui le remplace, et que `Harness\Tests\Regression\InflationRegressionTest` mécanise :
>
> > **Critère de sortie, seconde moitié :** à la cible par défaut, le mécanisme d'inflation est un **no-op strict** (le monde produit est celui d'avant, au centime) ; et à une cible non nulle, le monde reste **stationnaire en termes réels** — la solvabilité, grandeur sans dimension, ne s'emballe pas, et les salaires suivent l'unité monétaire au lieu de rester nominaux.
>
> Détail complet, mesures et déviations dans `17-marche-transferts.md` point 5.

> **Avancement mesuré le 2026-08-02 — les contrats ont une fin.** `Contract` porte `expiresOn` ; `Football\ContractSystem` (décision, en queue de pipeline) et `Football\SquadSystem` (application, en tête) font vivre un mercato annuel : expiration, renouvellement au prix du marché, joueurs sans club repris par les clubs en déficit. Le découpage en deux systèmes est structurellement forcé — décider exige de lire les compétences et `Finances`, appliquer exige d'écrire `SquadMembership` avant `TrainingSystem`/`MatchSystem` — même mur que `ClubInvestedInFacilities`. `Football\Support\WageModel` introduit **le premier vrai prix du monde** (`base × clamp(qualité/référence, 0.4, 2.5)`, forme bornée de `14-` §3), ce qui donne un point d'application futur à `indice_inflation_global`. Aucune indemnité de transfert : rien ne change de mains entre clubs, donc `MonetaryConservationTest` reste vert sans modification — c'est le meilleur test du lot. Nouvel invariant mécanisé, `Harness\Tests\Regression\SquadIntegrityTest` (`12-` §1 : jamais deux contrats actifs, `Contract` et `SquadMembership` toujours sur le même club).
>
> **Ce que la mesure dit, et la leçon de méthode.** Six graines appariées (40 saisons, 500 joueurs / 18 clubs) : la **rotation du top 5 monte de 47,8 % à 53,3 %, sur 6 graines sur 6** — c'est la propriété anti-sclérose de `14-` §7, et le seul effet consistant. Le **Gini des titres ne bouge pas de façon détectable** (0,528 → 0,557), parce que la dispersion entre graines de la seule référence (0,363 sur la graine 42, 0,614 sur la 7) écrase l'effet cherché. **Un Gini de titres lu sur une graine unique ne veut rien dire** : c'est l'erreur commise pendant ce lot — une « dégradation 0,363 → 0,528 » diagnostiquée sur la seule graine 42, qui a motivé un correctif (plancher d'effectif) lui-même mesuré nuisible ensuite (rotation retombée à 49,2 %, 4 graines sur 6) et retiré. À retenir pour tout futur arbitrage d'équilibre : six graines minimum, et le test du signe avant la moyenne.
>
> **Limite requalifiée le 2026-08-04 : ce n'est pas une limite assumée, c'est le premier lot restant.** `MatchSystem` note la moyenne des compétences d'un effectif quand le budget salarial en contraint le total : concentrer est légèrement gagnant (14 joueurs à 60,3 de qualité moyenne pour le meilleur club, 18 à 50,8 pour le dernier). Ce paragraphe renvoyait la correction hors périmètre, au motif qu'elle demandait `PositionAffinity`. Les deux moitiés étaient fausses :
>
> - **Elle ne demande pas `PositionAffinity`.** Prendre les onze meilleurs par l'agrégat que `MatchSystem::ratings()` calcule **déjà par joueur** est un tri, sans aucune notion de poste — pas moins juste que le split attaque/défense par catégorie de compétence qui est déjà en place.
> - **Ce n'est pas un raffinement du moteur de match, c'est une incitation économique inversée.** Sous la moyenne, la valeur marginale d'un joueur sous le niveau de l'effectif est **négative** : acheter un joueur de rotation fait baisser la note du club. Le lot suivant construit un marché où des clubs dépensent pour acquérir des joueurs, et le seul signal qui dit si l'achat était bon est à l'envers. Calibrer des prix contre une incitation qui s'inversera le jour de la correction, c'est calibrer deux fois.
>
> Réserve honnête sur la correction : ne noter que les onze meilleurs rend la profondeur d'effectif **neutre**, pas valorisée — sa vraie valeur suppose blessures et rotation, non modélisées. Neutre est strictement meilleur qu'inversé, et c'est l'intermédiaire correct.

> **Reste à faire dans la phase — ordre révisé le 2026-08-04.**
>
> 1. ~~Les onze meilleurs dans `MatchSystem`~~ — **absorbé par le lot des postes, fait le 2026-08-04** (voir ci-dessous). Il ne tenait pas debout séparément : il aurait fallu écrire une sélection d'onze fondée sur un agrégat aveugle aux postes, que le lot suivant aurait réécrite intégralement.
> 2. ~~**Perception/scouting**~~ — **fait le 2026-08-05** (voir « Lot de perception » ci-dessous). Périmètre livré exactement comme prévu : rôle scout semé au genesis, composant d'emploi distinct de `SquadMembership`, `signedOn` sur `Contract`, fonction pure de bruit dans `Football\Support\`, et une seule bascule dans `ContractSystem`. **Aucun mécanisme d'observation** : « qui observe qui » reste une mécanique du jeu d'agent, Phase 5.
> 3. **Marché des transferts et inflation — un seul lot, pas deux.** L'ordre précédent listait `marketInflationTarget` et son régulateur *après* le marché, comme un troisième morceau. C'est intenable : le seul prix du monde est aujourd'hui `WageModel = base × clamp(qualité/référence)` avec `base` constante du `Ruleset`. Le niveau de prix ne peut pas dériver, donc **l'inflation vaut identiquement zéro par construction** et un régulateur ne régulerait rien. L'inflation devient une grandeur mesurable exactement quand de l'argent poursuit un actif rare, c'est-à-dire avec les indemnités de transfert — et `indice_inflation_global` est déjà, dans `14-` §5, un facteur de la formule de valorisation, hors du clamp. Il se conçoit **dans** le lot marché, pas greffé sur un marché déjà calibré sans lui. Contenu : indemnités, valorisation `14-` §5 sur qualité **perçue**, négociation multi-tours et agents PNJ, `marketInflationTarget` et son régulateur.
>
>    **Terminé — suivi point par point dans `17-marche-transferts.md`, 5/5 au 2026-08-07.** Faits : la valorisation pure (`Football\Support\MarketValueModel`), la négociation multi-tours en PNJ seul (`Football\TransferSystem` + `Negotiation`, premier état multi-tick du noyau — mesuré : médiane 2 tours, le risque « converge instantanément » est écarté), la patience individuelle du club vendeur (`BoardPatience`), le branchement `IntentSource` côté acheteur (`Football\Intents\`, qui donne à `TickContext::$intents` son premier consommateur réel), et les **indemnités réelles** : `TransferAgreed` est exécuté par `FinanceSystem` au tick suivant, un mouvement qui n'est ni injection ni puits, ce qui rend `MonetaryConservationTest` non trivial pour la première fois (avec un garde-fou exigeant qu'il ait réellement circulé des indemnités). Et le point 5, `indice_inflation_global` et sa régulation — le point qui a le plus dévié de sa conception, sur mesure à chaque fois : l'indice est une **décision** de politique monétaire et non une mesure, il multiplie tout ce qui est nominal, et l'asservissement des injections a été construit, **mesuré instable deux fois**, puis retiré au profit d'une boucle ouverte. Défaut à `0.0`, donc **no-op strict** vérifié au centime.

    **Ce que le point 4 a mesuré, et qui cadre le point 5** : le marché est réel mais **économiquement inerte**. L'indemnité médiane vaut 9 % de `baseValueCents` — le PNJ maximise `qualité perçue / prix estimé`, donc il chasse les joueurs en fin de contrat, que `MarketValueModel` brade. Et sur 40 saisons, les indemnités totales (74,8 M centimes) restent marginales devant la masse en circulation (623 M) : aucun solde négatif, Gini des soldes de club à 0,011 et 0,008 sur deux graines. La boucle « riche s'enrichit » n'est donc **pas** rouverte par le marché à ce calibrage.

    **Ce que le point 5 laisse ouvert, et qu'il faut savoir avant la Phase 3** : à 3 %/an le monde reste stable mais **décroche sur l'emploi** (chômage de ~35 à ~2, le coussin de trésorerie se stabilisant 43 % au-dessus de son niveau naturel). C'est mesuré, chiffré, et non corrigé — d'où le défaut à zéro. L'inflation ne comptera vraiment que le jour où un prix de ce monde sera un prix d'**équilibre** et non une formule du `Ruleset` ; ce n'est pas la Phase 2.
>
> **Lot des postes — fait le 2026-08-04, et pourquoi il s'est intercalé.** Le marché de `14-` §5 a pour étape 1 « chaque club évalue son effectif **par poste** » et une valorisation contenant `rareté_poste` : sans postes, tous les clubs veulent la même chose — le plus gros nombre — et le marché dégénère en enchère sur un scalaire, exactement le « marché qui converge instantanément, économiquement correct et ludiquement mort » que ce document interdit. **Ce sont les postes qui créent une demande hétérogène, et la demande hétérogène est ce qui fait qu'un marché est un marché.**
>
> L'audit qui a déclenché le lot : sur seize attributs, **sept ne décidaient de rien**, `reflexes` servait de compétence défensive à tous les joueurs de champ, et la génération donnait la même valeur à tous les attributs d'une catégorie. Le monde n'avait **aucune différenciation de joueur** — un niveau scalaire plus du bruit.
>
> Contenu : `Position` (GK/DEF/MID/ATT), `Football\Support\PositionModel` (matrice de contribution façon Hattrick, source de vérité unique des poids), `Ruleset\PositionBalance`, un potentiel qui plafonne une **composition** et non chaque compétence (`12-` §5 bis), le onze composé par poste dans `MatchSystem`, `WageModel::quality()` devenue « note au meilleur poste », et une conscience minimale des postes dans `ContractSystem` et `YouthIntakeSystem`.
>
> **Mesures.** Différenciation *entre* postes : écart-type des seize attributs à l'intérieur d'un même joueur, à l'âge du pic, **4,0 → 16,7**. Différenciation *dans* un poste : écart-type entre les cinq attributs du profil, **1,5 → 8,6** — deux milieux de même potentiel cessent d'être le même joueur. Approvisionnement en gardiens, à graines appariées : club-années sans gardien **7,87 % → 1,39 %**.
>
> **Campagne à graines appariées (6 graines, 40 saisons, 500 joueurs / 18 clubs), avant/après sur le même code de harness.** Le résultat est celui **enregistré avant l'implémentation** : aucun effet sur l'équilibre compétitif, aucune régression Phase 0.
>
> | Grandeur | Avant | Après | Test du signe |
> |---|---|---|---|
> | Gini des titres | 0,568 | 0,531 | **3 hausses / 3 baisses** — aucun effet |
> | Rotation du top 5 | 54,2 % | 53,3 % | **3 hausses / 3 baisses** — aucun effet |
> | Victoires à domicile | 42,6 % | 42,0 % | 6 baisses sur 6, ampleur ~0,6 pt |
> | Nuls | 29,0 % | 29,4 % | stable |
> | Population finale | 319 | 320 | stable |
>
> Le seul effet consistant est une baisse de **0,6 point** des victoires à domicile, très à l'intérieur des ±8 points du critère Phase 0 : les notes d'attaque et de défense montent toutes deux (un onze sélectionné vaut mieux qu'un effectif moyenné), donc leur différence — la seule chose que lit Dixon-Coles — se comprime légèrement. **Aucun recalibrage de `strengthScale` n'a été nécessaire**, contrairement au risque annoncé au moment de planifier le lot.
>
> Le coût CPU est nul à la mesure : 59 s par run de 40 saisons contre 57 s avant.
>
> ⚠️ **Piège de méthode rencontré, à ne pas refaire.** La première campagne a rendu des chiffres *identiques au centième sur les six graines*. Cause : le worktree de référence pointait vers le kernel courant, `packages/harness/vendor/flair/kernel` étant un lien **relatif** (`../../../kernel/`) qui, avec un `vendor` lui-même symlinké, résout dans l'arbre principal. Pour une comparaison avant/après réelle il faut **copier** les `vendor`, pas les lier — et un résultat trop parfaitement identique doit toujours faire soupçonner le dispositif de mesure avant la conclusion.
>
> **Limites assumées, à ne pas oublier :** le poste dérivé coïncide avec l'archétype dans 100 % des cas (la causalité « les compétences font le poste » est correcte mais inerte) ; une seule formation, aucune tactique ; et 1,39 % de club-années sans gardien qu'aucun mécanisme actuel ne peut rattraper — c'est le marché des transferts qui fermera ce trou, garde-fou en attendant : `Harness\Tests\Regression\FieldableSquadTest`.

> **Lot de perception — fait le 2026-08-05.** Livré : `Employment` + `Scout` (le premier rôle non-joueur du monde), `Ruleset\PerceptionBalance`, `Football\Support\PerceptionModel` (pure, sans transcendante — Irwin-Hall plutôt que Box-Muller), `Contract::$signedOn`, `Core\SystemContext::stableHash()`, et la bascule de `ContractSystem` sur une qualité **perçue**. Un club sans recruteur est le **pire** observateur du monde, jamais un omniscient. Détail classe par classe dans `packages/kernel/README.md` § « Perception ».
>
> **Trois écarts au plan, tous assumés et documentés là où ils comptent :**
>
> - **La formule de σ diffère de l'esquisse de `12-` §4** (report fait dans ce document-là). L'esquisse met la compétence du scout *dans* le facteur d'observation, donc à zéro observation le jugement n'a plus aucun effet : tous les clubs jugeraient un joueur extérieur aussi mal les uns que les autres, et un bon recruteur ne servirait qu'à apprendre plus vite sur les joueurs maison. C'est l'inverse du métier, et c'est précisément la capacité dont le lot marché a besoin.
> - **Le poste reste lu sur la vérité.** Seule la *note* d'un joueur est perçue. Se tromper de poste est une extension possible, pas un oubli — et `MatchSystem` doit continuer de lire la vérité, un match n'étant pas une opinion.
> - **La corrélation jugement ↔ classement n'est pas calculée**, seulement affichée côte à côte dans le rapport. Sur la seule saison finale la relation est trop bruitée pour conclure ; la mesurer proprement (corrélation de rang sur tout le run) appartient au lot marché, où « payer cher achète-t-il de la performance » est la question centrale. C'est la mesure qui manque le plus à ce lot.
>
> **Campagne à 6 graines appariées** (42, 7, 101, 2024, 31337, 5 — 40 saisons, 500 joueurs / 18 clubs), perception active contre `--set baseErrorPoints=0`. Attendus écrits **avant** l'implémentation, confrontés ici sans être réécrits :
>
> | Grandeur | Omniscience | Perception | Test du signe | Attendu ? |
> |---|---|---|---|---|
> | Gini des titres | 0,489 | 0,516 | **3 hausses / 3 baisses** — aucun effet | ❌ hausse prédite |
> | Rotation du top 5 | 53,1 % | 52,0 % | **3 hausses / 3 baisses** — aucun effet | ✅ ambigu prédit |
> | Champions distincts | 13,8 | 13,3 | 4 baisses sur 6 — non concluant | — |
> | Masse salariale mondiale | 8,415 M€ | 8,460 M€ | **5 hausses sur 6** (+0,54 %) | ✅ dérive prédite |
> | Victoires à domicile | 42,0 % | 42,2 % | 3 hausses / 2 baisses — aucun effet | ✅ inchangé prédit |
> | Population, conservation monétaire | — | — | `CalibrationRegressionTest` et `MonetaryConservationTest` verts | ✅ inchangé prédit |
>
> **La prédiction centrale était fausse, et le piège qui l'a rendue crédible mérite d'être noté** : sur la seule graine 42, le Gini passait de 0,446 à 0,509 — exactement la « sclérose par handicap permanent » attendue. Sur six graines, **3 hausses / 3 baisses**. C'est le même piège qu'au lot des contrats, à l'identique, sur la même grandeur : *un Gini de titres lu sur une graine unique ne veut rien dire*. Conclusion à retenir : un jugement de recruteur figé au genesis, à cette amplitude d'erreur, **ne suffit pas à créer une hiérarchie compétitive durable**.
>
> **Le seul effet consistant est plus intéressant que celui qu'on cherchait : la malédiction du vainqueur, gratuitement.** La masse salariale monte sur 5 graines sur 6 alors que l'erreur d'estimation est **symétrique** et devrait s'annuler en moyenne. Elle ne s'annule pas parce qu'un club ne signe pas au hasard : il signe les joueurs qu'il **surestime**. La population sous contrat est donc biaisée vers les joueurs surévalués, et le monde paie 0,5 % de trop. Effet faible en amplitude, mais c'est exactement le mécanisme que le lot marché va amplifier — enchérir sur une valeur perçue, c'est surpayer. Il valide au passage que la bascule mord réellement sur les décisions, là où Gini et rotation ne voient rien.
>
> Coût CPU quasi nul à la mesure : **72,5 s → 74,0 s** (+2 %) sur le même run de 40 saisons, arbre pré-lot contre arbre courant. Les évaluations supplémentaires sont en clubs × vivier une fois l'an, pas par tick.
>
> **Expérience de contrôle : « bruit » ou « hiérarchie de staff » ?** Puisque ni le Gini ni la rotation ne bougent quand on allume la perception, restait à savoir si c'est la *dispersion* du jugement qui ne fait rien, ou la perception tout entière. `--scout-judgement-spread=0` (tous les recruteurs à 50) contre la dispersion par défaut, sur les mêmes 6 graines. C'est une **vraie comparaison appariée** malgré l'absence de `--set` : le staff étant semé après les joueurs, ses tirages ne décalent aucun flux existant — même population, mêmes `EntityId`, seules changent les valeurs de jugement.
>
> | Grandeur | Recruteurs égaux | Dispersés | Test du signe |
> |---|---|---|---|
> | Gini des titres | 0,488 | 0,516 | 4 hausses / 2 baisses — non concluant |
> | Rotation du top 5 | 52,8 % | 52,0 % | 3 hausses / 3 baisses — aucun effet |
> | Champions distincts | 14,5 | 13,3 | 1 hausse / 3 baisses (2 nuls) — non concluant |
> | Masse salariale | 8,435 M€ | 8,460 M€ | 4 hausses / 2 baisses — non concluant |
>
> **Conclusion honnête : à cette amplitude d'erreur, la hiérarchie de staff ne produit pas d'effet mesurable sur l'équilibre non plus.** Les deux signaux qui pointent dans le sens attendu — Gini en hausse, champions distincts en baisse, donc une légère sclérose — sont à 4/6 et 3/6 sur 6 graines, c'est-à-dire indistinguables du hasard par le test du signe. Ce qu'il faut en retenir : `baseErrorPoints = 10` place le monde dans un régime où **la perception change les prix mais pas les classements**. Les deux pistes si l'on veut qu'elle compte davantage, à ne suivre que sur un besoin réel : monter l'amplitude d'erreur, ou rendre le jugement **endogène** (un club riche s'offre un meilleur recruteur) — ce second chemin est la gouvernance de club, hors Phase 2, et c'est lui qui transformerait un handicap tiré au sort en avantage cumulatif.
>
> **Le fait de méthode le plus réutilisable du lot : aucun lot qui ajoute une entité au genesis ne peut être une réduction bit-à-bit d'un monde antérieur.** À `baseErrorPoints = 0`, la perception est un no-op strict sur chaque décision — vérifié en comparant une empreinte complète (population par année, résultats, marché, revenus, installations, classement final) entre un arbre pré-lot et l'arbre courant. Les deux empreintes différaient pourtant, et l'unique cause a été isolée : les 18 scouts consomment 18 identifiants d'entité au genesis, ce qui décale l'allocateur pour toutes les entités créées ensuite à l'exécution (les jeunes promus), donc leurs flux RNG. En faisant consommer les mêmes identifiants à l'arbre pré-lot, les empreintes redeviennent **identiques au chiffre près**. Corollaire : une comparaison à graines appariées se fait toujours **à l'intérieur d'un même build**, jamais contre des nombres notés dans un document — ce qui vaut aussi pour les chiffres du présent document. Reporté dans `13-` §4.1.
>
> Effet secondaire du lot 3 qui vaut d'être noté : les indemnités rendent `MonetaryConservationTest` **non trivial pour la première fois**. Tant qu'aucun argent ne change de mains entre clubs, l'invariant ne peut pas casser sur le chemin qui, dans `14-` §6, est précisément celui qui doit conserver — la moitié « conservation » du critère de sortie n'est réellement éprouvée qu'à partir de là. **Fait le 2026-08-07** (point 4 de `17-`), avec un garde-fou qui exige qu'il ait réellement circulé des indemnités sur les 20 saisons : sans lui, le test resterait vert pour la mauvaise raison le jour où le marché se grippe.

> **Note de conception à ne pas perdre (2026-08-02), avant d'écrire la perception :** `observerId` (`12-` §4) doit être une **personne** (`Person` + composant de rôle — scout/coach/président/journaliste/supporter), jamais un attribut de `Club` — c'est le cas d'école qui justifie l'ECS (`12-` §1 : joueur → entraîneur → président, même entité). Aucun rôle non-joueur n'existe encore dans le monde : ni le composant de rôle, ni la relation d'emploi club↔personne, ni le mécanisme qui fait avancer `observationCount`. Pour cette phase, seul le rôle **scout employé par un club** est nécessaire (sert la valorisation du marché, `14-` §5). Le premier consommateur est déjà écrit et l'attend : `Football\ContractSystem::quality()` lit aujourd'hui les compétences vraies pour décider d'un renouvellement — **simplification de périmètre, pas une affirmation de conception**. Un club n'a pas d'yeux : c'est un staff qui perçoit, et un mauvais staff doit pouvoir se tromper sur son propre joueur. Quand `Person` + rôle existeront, c'est cette méthode qui passera de la vérité cachée à une estimation bruitée, et rien d'autre dans le système n'aura à changer — coach/président (gouvernance de club, `14-` §7) et journaliste/supporter (narration, Phase 6) peuvent attendre. Détail complet dans `12-modele-du-monde.md` §4.

### Phase 3 — Persistance et temps réel *(≈ 3 semaines)*

Event store, snapshots, boucle du Host, cadence temps réel, verrou mono-writer, un monde qui tourne en continu.

> **Critère de sortie :** tuer le processus au hasard, le relancer, et le monde reprend sans incohérence.

> ✅ **Atteint le 2026-08-08** (lot 3 ci-dessous), et vérifié au sens littéral : un vrai sous-processus, un vrai SIGKILL, trois fois de suite — `Host\Tests\CrashRecoveryTest`.

> **Lot 1 — sérialisation du `WorldState` : fait le 2026-08-08.** Premier lot de la phase, et délibérément celui-ci : tant que l'état ne se sérialise pas, il n'y a rien à écrire en base et l'event store, le verrou et la boucle du Host ne sont que de la plomberie autour du vide. Il se conçoit et se vérifie **sans aucune infrastructure** — c'est le bénéfice du noyau pur, et ça met le critère de sortie de la phase sous test avant même que Postgres existe.
>
> Livré dans `Core\Snapshot\` (kernel, donc pur et sans I/O — le format appartient au noyau, le Host ne stockera que des octets) : `TypeRegistry` (clé stable ↔ classe, aucun FQCN dans le payload, et le même registre alimentera la colonne `events.type`), `SnapshotCodec`, `ValueCodec` (réflexif sur les propriétés promues), `SnapshotContract`, l'attribut `SnapshotArrayOf`, l'enveloppe `WorldSnapshot`, `JsonSnapshotFormat`, plus `Kernel::VERSION` et `Football\FootballTypes`. Détail classe par classe dans `packages/kernel/README.md`, format dans `13-` §5.
>
> **Trois choses que le code a corrigées dans la conception écrite ici :**
>
> 1. **Le tick n'est pas dans le `WorldState`** — `13-` §8 écrivait `$state->tick + 1`, qui n'a jamais existé : le tick vit dans `TickContext`, comme la graine. D'où une **enveloppe**, sans quoi un monde rechargé ne sait ni quel jour on est ni comment tirer ses aléas.
> 2. **Le rejeu du delta est abandonné, mesures à l'appui** (`13-` §5, révisé) : snapshot **à chaque tick**, dans la transaction qui écrit les événements. Mesuré sur 500 joueurs / 18 clubs / 12 saisons : 0,38 Mo par snapshot (0,05 Mo gzippé), 6,9 ms pour encoder, 13,7 ms pour relire, contre 6,1 ms de coût moyen d'un tick. À 1 tick/h, rejouer n'achète rien et rouvre le piège du `13-` §6.
> 3. **La conformité est mécanisée, pas mémorisée.** Un type du domaine oublié serait de l'état perdu au redémarrage, en silence — le pire mode de panne possible. `Tests\Core\Snapshot\SnapshotConformanceTest` balaie `src/Football/{Components,Singletons,Events}` sur disque et exige que chaque type soit enregistré **ou atteignable** depuis un type enregistré (ce qui laisse leur place aux types de valeur : `Position` n'est jamais un composant, `StandingsEntry` n'existe qu'imbriquée). Une liste tenue à la main aurait eu exactement le défaut qu'on corrigeait.
>
> **Le critère de sortie de la phase est déjà sous test**, sans base de données : `Harness\Tests\Regression\SnapshotContinuityTest` interrompt un run au premier tick où chaque structure fragile est réellement occupée — OutQueue non vide, Scheduler non vide, `Negotiation` en cours (le seul état multi-tick du monde) — ne garde que la chaîne JSON, et vérifie que la suite est indiscernable d'un run jamais interrompu : même hash d'état **et** même hash de séquence d'événements. Le test échoue si l'une des trois structures n'a jamais été couverte, sur le modèle du garde-fou de `MonetaryConservationTest`. Éprouvé par trois sabotages du codec (Scheduler, OutQueue, compteur d'entités jetés tour à tour) : **les trois sont détectés**.
>
> **Trou fermé au passage** : `Harness\Support\WorldHasher` tenait sa propre liste de types, à laquelle il manquait `BoardPatience`, `Negotiation` et le singleton `MarketInflation` — tout le marché des transferts était hors du test de déterminisme depuis le lot 3, sans que rien ne le signale. Elle dérive désormais de `FootballTypes`, dont l'exhaustivité est garantie par le test de conformité. Deux listes du même monde finissent toujours par diverger ; il n'y en a plus qu'une.
>
> **Non-régression vérifiée dans un même build**, jamais contre des nombres notés dans un document (`13-` §4.1) : empreinte complète d'un run 500 joueurs / 18 clubs / 12 saisons, arbre pré-lot contre arbre courant, via un autoloader dédié pointant tour à tour sur les deux arbres — **état et séquence d'événements identiques au chiffre près** (5 697 événements). Attendu : le lot n'ajoute aucune entité au genesis et ne touche aucun flux RNG.
>
> **Lot 2 — `packages/worldgen` : fait le 2026-08-08.** Un déblocage, pas une fonctionnalité. `host` doit pouvoir **créer** un monde, et le graphe de `11-` §7 lui interdit d'importer `harness` : un outil de mesure ne peut pas devenir la source des mondes de production. La genèse a donc quitté `harness/src/Population/` pour son propre package — `WorldFactory` (ex-`PopulationFactory`), `ClubFactory`, `CompetitionFactory`, `StaffFactory`, plus un `WorldSpec` neuf.
>
> **La spec s'est scindée là où elle mélangeait deux choses.** `PopulationSpec` portait la forme du monde *et* une durée de run (`years`) qui n'a rien à faire dans un générateur de monde. `Worldgen\WorldSpec` ne garde que le monde ; `Harness\Population\PopulationSpec` survit avec `years` et **sa signature à plat inchangée**, en gagnant un `world()` — les ~20 sites de construction en arguments nommés n'ont pas bougé d'une ligne, seuls les 11 appels à `populate()` ont changé.
>
> **Le seul critère de réussite d'un lot pareil est l'exactitude bit-à-bit**, et les suites de tests ne peuvent pas la prouver : elles comparent des runs entre eux dans un même build, jamais contre le build précédent. Vérifié comme au lot 1 — `git worktree` sur la révision d'avant, un script d'empreinte à autoloader maison, deux exécutions : **état, séquence d'événements, compteur d'entités et liste des `EntityId` joueurs identiques** sur 500 joueurs / 18 clubs / 12 saisons (5 697 événements).
>
> Deux dettes soldées au passage, puisqu'on touchait aux README : `packages/harness/README.md` décrivait encore un `src/Simulation/PipelineFactory` supprimé depuis que l'ordre du pipeline est dérivé par `SystemGraph`, et une description de `WorldHasher` périmée depuis le lot snapshot.
>
> **Lot 3 — `packages/host` : fait le 2026-08-08. Le critère de sortie de la phase est atteint.** Trois tables (`worlds`, `events`, `snapshots`), `AdvanceWorld`, `CreateWorld`, verrou advisory mono-writer, et un CLI (`bin/host.php`). Stack : `illuminate/database` seul — **pas** de skeleton Laravel, parce que `api → host` fait de `host` une *dépendance* et que deux skeletons d'application ne se composent pas. La Phase 4 mettra Laravel complet dans `api`.
>
> **Tout tient dans une seule transaction** : verrou, lecture du snapshot, `step()`, écriture des Faits, écriture du snapshot, mise à jour du tick. C'est cette atomicité, et elle seule, qui rend le critère vrai — tuer le processus laisse la base avant ou après le tick, jamais au milieu.
>
> `Harness\Tests\Regression\SnapshotContinuityTest` mécanisait la propriété côté noyau ; `Host\Tests\CrashRecoveryTest` la vérifie **pour de vrai** : un sous-processus réel, un **SIGKILL** en plein vol, trois fois de suite, puis deux assertions distinctes — la base est cohérente (tick, snapshot et dernier Fait journalisé d'accord) et le monde repris est **identique** à un monde jamais interrompu. Éprouvé par sabotage : sans transaction du tout, détecté **4 fois sur 4** ; avec deux transactions successives, **2 fois sur 6** (la fenêtre vulnérable ne dure que quelques microsecondes). C'est un filet probabiliste, la garantie reste structurelle — dit tel quel dans le test.
>
> **Trois choses mesurées, dont deux imprévues.**
>
> 1. **Le coût d'écriture en base : 17,8 ms/tick contre 18,7 ms de simulation** (500 joueurs / 18 clubs). Première confirmation chiffrée de `13-` §7 — la base coûte autant que le noyau. À un tick par heure, les deux sont sans objet.
> 2. **`jsonb` réordonne les clés d'objet.** Un snapshot relu depuis `jsonb` n'est donc plus identique octet pour octet à ce que le noyau a produit, alors que `SnapshotCodec` garantit cette stabilité. La relecture restait correcte, mais la propriété se perdait en silence à la frontière de la base. `snapshots.state` est passé en `json` (texte conservé tel quel) ; `events.payload` reste en `jsonb`, les projections de la Phase 4 devront l'interroger.
> 3. **Le verrou advisory survit 0,7 à 3,4 ms au processus tué**, le temps que PostgreSQL constate la connexion perdue. Sans conséquence pour un cron horaire ; fatal pour un test qui enchaîne mise à mort et reprise immédiate — c'est ce qui rendait `CrashRecoveryTest` instable (4 échecs sur 8) avant qu'il n'attende la libération du verrou. 0 échec sur 10 depuis.
>
> Restent dans la phase, et ils appartiennent en réalité à la Phase 4 : projections, SSE. Le jour venu, **les projections devront rejoindre cette même transaction** (sinon un client voit un monde incohérent après un crash) et **la diffusion SSE devra rester dehors** (publier avant le commit annoncerait un tick qui peut encore être annulé).

### Phase 4 — API + admin *(≈ 4 semaines)*

Projections, API de requêtes, flux SSE, IHM d'administration pour explorer et éditer le monde.

**Livrable à part entière : le digest de retour d'absence** (`14-` §9). « Il s'est passé trois mois, qu'est-ce que j'ai raté ? » est *la* question d'un monde persistant, et c'est ce qui transforme l'absence en ellipse narrative plutôt qu'en punition. Quasi gratuit une fois l'event log et les seuils en place — et c'est aussi le meilleur contrôle qualité de tes seuils d'émission : un digest illisible signale des seuils mal réglés.

> **Critère de sortie :** naviguer dans dix ans d'histoire d'un club depuis un navigateur, et lire un digest de trois mois d'absence qui se comprend en trente secondes.

C'est la première fois que le monde devient *visible*. Ne repousse pas cette phase plus loin — psychologiquement, elle compte.

#### Découpage retenu (ouvert le 2026-08-08) — **première moitié du critère de sortie atteinte**

| Lot | Contenu | État |
|---|---|---|
| 0 | Le jeu de données : un monde de dix ans en base, et ce qu'il coûte | ✅ 2026-08-08 |
| 1 | `packages/api`, la couche de lecture, la fiche d'un club | ✅ 2026-08-08 |
| 2 | Dix ans d'histoire d'un club, en blocs par saison | ✅ 2026-08-08 |
| — | Les dettes de Faits que le lot 2 a mises à nu | ✅ 2026-08-08 |
| 3 | Le digest cadré **club** | à faire |
| 4 | SSE | à faire, **hors critère de sortie** |

SSE en dernier et hors critère : à un tick par heure, un flux temps réel ne vaut pas mieux qu'un rafraîchissement, et rien dans le critère ne l'exige.

#### Deux décisions structurelles, prises d'entrée

**1. Un seul paquet `api`, qui sert le JSON *et* les pages Blade de l'admin — pas de `packages/admin`.** C'est un **écart assumé au graphe de `11-` §7**, et il mérite d'être écrit noir sur blanc plutôt que de vivre dans un commentaire : l'admin est un outil interne mono-utilisateur, un SPA n'y achète rien, et deux paquets n'auraient pas empêché les deux présentations de diverger.

Ce qui les empêche de diverger, c'est qu'elles n'ont **qu'une source** — la couche de lecture `Api\Read\`, et un test (`Tests\Http\PagesMatchJsonTest`) qui prend les chiffres du JSON, les met sous la forme de la page et exige de les y retrouver. La frontière entre `src/` (lecture, PHP nu) et `app/` (adaptation Laravel) est tenue mécaniquement, par balayage du disque, pas par convention. **Si elle gêne un jour à l'usage, la bonne réponse est de tout ramener sous `App\` et de l'assumer — jamais de la laisser mentir.**

**2. Zéro table de projection**, jusqu'à ce qu'un écran soit *mesuré* trop lent. L'event log et le snapshot répondent aux deux moitiés du critère de sortie ; et `snapshot + PerceptionModel` à la lecture est de toute façon la seule forme conforme à `12-` §4, une projection stockée figeant une vérité qui devrait être dérivée par observateur.

Ce qui a été mesuré et rend l'attente raisonnable : fiche de club **28,7 ms**, histoire de dix ans **57,1 ms**, `Seq Scan` sur dix ans d'event log à **2,17 ms**. Le déclencheur est nommé (`ClubHistoryView::$factsRead`) et l'échappatoire aussi — dériver les prédicats SQL de la même déclaration que le filtre PHP, pour avoir une source et la vitesse du SQL.

> ⚠️ **Ce que le lot 2 a appris, et qui dépasse la phase.** Rendre le monde visible est aussi ce qui le rend *vérifiable* : le seul vrai bug du lot — 753 prolongations sur 819 signatures comptées comme des arrivées, une page annonçant « 7 arrivées » pour un club qui n'avait recruté personne — a été trouvé **en ouvrant la vraie page**, par aucun test. Et deux Faits se sont révélés incapables de dire qui ils concernaient, ce qui a donné la règle de contenu de `16-` §2.

### Phase 5 — Le jeu d'agent *(≈ 6 semaines, et le vrai inconnu)*

Client d'incarnation : recruter un client, le scouter, le placer, négocier, gérer sa carrière.

> **Critère de sortie :** trois personnes extérieures jouent une semaine et **reviennent** sans qu'on le leur demande.

⚠️ **À faire en prototype papier / CLI dès maintenant, en parallèle de la phase 0.** C'est la seule inconnue qui peut tuer le projet, et elle ne coûte que quelques heures à lever. Ne découvre pas en phase 5 que le métier d'agent n'est pas amusant.

### Phase 6 — Profondeur

Moteur L1 Markov, narration émergente, multi-pays, coupes continentales, médias.

> **Note de conception à reprendre plus tard (2026-08-04) — les centres de formation.** Le lot des postes a rendu `YouthIntakeSystem` partiellement **dirigé par le besoin** : un club dont il manque un poste promeut à ce poste. C'est mesuré efficace (club-années sans gardien 7,87 % → 1,39 % à graines appariées) mais **volontariste** : une académie ne produit pas à la commande.
>
> Direction retenue pour y revenir, à ne pas perdre :
>
> - Les centres de formation forment des joueurs **au hasard**, sans regarder le besoin du club.
> - Les meilleurs clubs ont les **premiers choix** — une draft inversée.
> - Pour que ça tienne, l'offre doit **excéder la demande** : les académies produisent nettement plus de joueurs que les effectifs n'en absorbent.
> - Les joueurs en trop restent **sans club** et partent à la retraite selon leur **personnalité**, ce qui donne enfin un rôle décisionnel aux attributs mentaux aujourd'hui dormants (`leadership`, `discipline`).
>
> Ça remplace deux bricolages du lot des postes : le pilotage par le besoin ci-dessus, et la distribution imposée des archétypes au genesis (`Worldgen\WorldFactory::archetypeDeal()`). Ça suppose aussi que le marché des transferts existe, sans quoi un joueur sans club n'a aucun chemin de retour.

---

## 5. Ce qu'il ne faut pas faire

> Le pendant de cette section est `18-dettes.md` : ce qu'on s'est **déjà** autorisé à remettre à plus tard, avec le déclencheur qui dit quand. Une dette sans déclencheur n'y entre pas, et ce qui peut être mécanisé en sort pour devenir un test.


| Tentation | Pourquoi c'est un piège |
|---|---|
| Moteur de match positionnel 2D | 80 % du temps de dev, valeur marginale. Le cimetière des clones de FM. |
| DSL de règles généraliste | Six mois pour un mauvais langage sans débogueur. Données + code, pas de langage. |
| Microservices | Un monde est un acteur mono-thread. Les microservices ajoutent de la latence et de la complexité pour zéro bénéfice ici. |
| Kafka / Redis / Mongo en plus de Postgres | Postgres seul suffit largement à cette charge. Chaque système en plus est du temps d'exploitation en moins pour le jeu. |
| 250 attributs par joueur | Inéquilibrable en solo. |
| Multi-mondes avant qu'un monde ne fonctionne | L'architecture le prévoit, ne l'implémente pas maintenant. |
| Optimiser le noyau | Le CPU n'est pas le facteur limitant (cf. `13-` §7). Mesure d'abord. |
| Réécrire le moteur en Rust « pour la perf » | Optimise un non-goulot. Deux langages en solo, pour un gain que le dimensionnement ne justifie pas (`11-` §6). |
| Émettre un événement à chaque changement d'état | 3 millions d'événements de bruit par saison. Seuils obligatoires (`16-` §2). |
| Bloquer les cascades sur un compteur de profondeur | Perte silencieuse d'événements, bugs indiagnosticables — et le seuil couperait du gameplay réel (`16-` §3). |

---

## 6. Les sept décisions à verrouiller avant la première ligne de code

1. **Noyau pur et déterministe**, sans I/O, séparé du Host. *(non négociable — tout en dépend)*
2. **ECS plutôt qu'agrégats DDD à comportement**, parce que les personnes changent de rôle au cours de leur vie — et **aucun sous-type** `Player`/`Club`.
3. **Vérité cachée vs perception bruitée** dans le modèle dès le départ — c'est ce qui fait exister le jeu d'agent, et c'est très coûteux à rajouter après coup.
4. **Moteur de match derrière une interface, LOD dès le premier jour**, avec test de calibration entre niveaux.
5. **Règles paramétriques en données versionnées**, monde épinglé à `(kernelVersion, rulesetVersion)`, migrations explicites.
6. **Propagation par files inter-ticks** — un événement n'est jamais traité dans le tick qui l'a produit — plus un Scheduler pour le différé.
7. **Taxonomie Fait / DecisionRequest / Intent** dès le premier événement écrit.

Les décisions 1 à 4, 6 et 7 sont chères à corriger plus tard. La cinquième est chère à corriger *très* tard, quand des mondes vivants existent déjà.

---

## 7. Documents de référence

| Doc | Contenu |
|---|---|
| `10-etat-de-l-art.md` | FM, Hattrick, CK3, Dwarf Fortress, modèles académiques, sources |
| `11-architecture-generale.md` | Macro-architecture, hexagonale, intentions, CQRS, stack, SOLID appliqué |
| `12-modele-du-monde.md` | ECS, entités et composants, singletons, perception, ruleset, worldgen, invariants |
| `13-moteur-de-simulation.md` | Tick hybride, scheduler, déterminisme, event sourcing, dimensionnement |
| `14-algorithmes.md` | Moteurs de match, développement, composition de facteurs, marché, économie, équilibre, narration |
| `16-evenements-et-cascades.md` | Taxonomie des messages, seuils d'émission, contrôle des cascades, Event Monitor |
| `17-marche-transferts.md` | Chantier du marché des transferts et de l'inflation (Phase 2, lot 3), en 5 points |
| `18-dettes.md` | Les dettes ouvertes, chacune avec son déclencheur — et ce qui n'y entre pas |
| `ressource.md`, `ressource2.md` | Sources internes (discussion avec un ami) — matière première, non normatives |
