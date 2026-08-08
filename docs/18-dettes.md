# Dettes ouvertes

Ce document est le seul endroit où une dette **attend**. Il ne décrit aucune conception (`10-` à `14-`), ne fixe aucun critère de phase (`15-`), et ne suit aucun chantier en cours (`17-` en est le modèle de forme, pas de contenu).

Il existe parce que le tableau des dettes de la Phase 4 vivait dans un **fichier de plan de session, hors du dépôt** — donc à un `rm` près de disparaître, et invisible pour quiconque relit le projet. Une dette qui n'est pas versionnée n'est pas suivie, elle est oubliée avec un délai.

## La règle d'entrée

> **Pas de déclencheur, pas d'entrée.**

Une ligne sans déclencheur nommé n'est pas une dette, c'est un souhait — et un document de souhaits pourrit en un trimestre. Le déclencheur peut être une date, un seuil mesurable, ou l'arrivée d'un autre lot ; il ne peut pas être « un jour ».

## Ce qui n'entre pas ici

Trois natures de dette, et deux n'ont rien à faire dans un document :

1. **Ce qui peut être mécanisé → un test, jamais une ligne de texte.** C'est la seule chose qui ait jamais vraiment fermé une dette dans ce projet : `SnapshotConformanceTest`, `EveryFactIsPlacedOrExcludedTest`, `ReadLayerStaysFrameworkFreeTest`, `CalibrationFieldsTest`. Le README du harness nomme le mode de panne inverse : *« une liste à la main que rien ne confronte à sa source »*. Ce document **est** une telle liste ; tout ce qui peut en sortir pour devenir un test doit en sortir.
2. **Ce qui appartient à une phase → `15-roadmap.md`.** Le digest, SSE, l'inbox d'intentions du Host ne sont pas des dettes, ce sont des lots non commencés.
3. **Ce qui est une décision assumée → le docblock du code concerné.** Une limite connue et voulue se documente là où on la lira, pas dans un registre séparé.

Reste ce document : ce qui est **réellement en retard**, avec un déclencheur, et sans meilleur endroit où vivre.

---

## Ouvertes

### D2 — `MatchSystem` note la moyenne d'un effectif que le budget contraint en total

**Déclencheur : avant que le prix d'un joueur soit un prix d'équilibre** (donc avant que D3 puisse être traitée), ou au moteur de match L1 de la Phase 6.

L'incitation économique est **inversée** : la valeur marginale d'un joueur sous le niveau de l'effectif est négative, donc acheter un joueur de rotation fait *baisser* la note du club. Un marché tourne au-dessus de ce signal depuis la Phase 2.

Requalifiée le 2026-08-04 de « limite assumée » en « premier lot restant », puis non traitée : le lot des postes a livré le onze composé par poste, ce qui corrige le symptôme le plus visible sans corriger l'incitation. Un plancher d'effectif a été essayé et **mesuré nuisible** (rotation du top 5 retombée à 49,2 %, 4 graines sur 6) puis retiré. La correction appartient au moteur de match, pas au marché.

Détail et mesures : `15-roadmap.md` §4 Phase 2.

### D3 — L'inflation à 3 % effondre l'emploi

**Déclencheur : le jour où un prix de ce monde sera un prix d'équilibre** et non une formule du `Ruleset`.

`marketInflationTarget` par défaut à `0.0`, **no-op strict vérifié au centime**. À 3 %/an le monde reste stable mais le chômage tombe de ~35 à ~2, le coussin de trésorerie se stabilisant 43 % au-dessus de son niveau naturel. C'est mesuré, chiffré, et **non corrigé** — d'où le défaut à zéro.

Ce n'est pas un bug du régulateur : le monde n'a **aucune inflation endogène**, salaires et valeurs étant des formules et non des prix. Tant que c'est vrai, l'indice est une décision de politique monétaire dont le taux réalisé égale la cible par construction, et il n'y a rien à réguler.

Détail : `17-marche-transferts.md` point 5.

### D4 — `packages/ruleset` n'existe pas

**Déclencheur : un second `Ruleset` versionné, ou la première migration d'un monde vivant.**

Aucun des deux n'est arrivé, et le construire avant serait de l'anticipation — le critère du projet est « deux consommateurs réels ». `Host\Rules\RulesetForWorld` est le **site unique** à rebrancher le jour venu : tant qu'une seule version est acceptée, genèse et avancement lisent forcément les mêmes règles, et la classe de désaccord est inatteignable.

### D7 — Le journal est bavard là où rien ne se raconte, et muet là où tout se raconterait

**Déclencheur : maintenant, et il a une date de péremption.** `events.payload` n'a **aucune colonne de version de format** et `Core\Snapshot\ValueCodec` est strict dans les deux sens : ajouter ou retirer un Fait est gratuit tant qu'aucun monde ne compte, et devient une migration ensuite. C'est le même déclencheur que la dette des Faits inattribuables, qui a été soldée pour cette raison exacte.

Le digest (lot 3 de la Phase 4) est le contrôle qualité des seuils d'émission que `14-` §9 promettait. Verdict, lu sur une vraie page :

- **Muet là où tout se raconterait.** Le digest sait écrire « large victoire à domicile contre X (5-1) » et **jamais pourquoi**. Il n'existe ni buteur, ni blessure, ni débuts, ni performance individuelle — le moteur L0 Dixon-Coles ne produit qu'un score. L'exemple de `14-` §9 (« Diallo a marqué 7 buts en 9 matchs — sa valeur a doublé ») décrit un monde qui n'existe pas.
- **Bavard là où rien ne se raconte.** `TransferCounterDemanded` pèse **10,6 %** des Faits d'une fenêtre représentative — de la procédure de négociation, inscrite dans `FactAmplitude::NEVER_NEWSWORTHY` parce qu'aucun lecteur n'en voudra jamais. `16-` §2 dit qu'un Fait mérite d'être émis s'il franchit un seuil comportemental, est irréversible, ou est racontable : ces trois-là ne sont ni l'un ni l'autre.

Ce qui empêche de trancher tout de suite, et pourquoi c'est une dette et non un lot : ajouter un Fait de performance suppose que le moteur en produise la matière, ce qui touche `MatchSystem` et rejoint **D2**. Retirer les Faits de négociation est en revanche isolé et bon marché.

### D8 — Les noms du monde sont des identifiants

**Déclencheur : le premier lecteur humain qui n'est pas l'exploitant** — donc le client de jeu (Phase 5), ou tout partage d'une page à un tiers.

`Worldgen\WorldFactory` nomme les joueurs `"Joueur {$entity}"` et les clubs `"Club synthetique {$n}"`. Tant que la seule surface est l'administration, c'est sans conséquence et même pratique (le nom porte l'`EntityId`). Dès qu'un digest est censé se lire comme un récit, c'est le plus gros obstacle qui reste — et **le moins cher à lever de tous ceux inscrits ici**.

Noté en lisant le digest, pas cherché.

### D6 — Un club vise 22 joueurs, en a 16,5, et n'en veut que 20

**Déclencheur : le prochain travail sur la démographie ou sur `ContractBalance::$targetSquadSize`** — et obligatoirement avant de conclure quoi que ce soit d'une mesure de « déficit d'effectif ».

Trois nombres qui ne s'accordent pas :

| Grandeur | Valeur |
|---|---|
| `ContractBalance::$targetSquadSize` | 20 |
| Somme de `SquadComposition::targets()` (4-4-2 mis à l'échelle) | **22** |
| Effectif réel moyen d'un club | **~16,5** |

`targets()` arrondit chaque poste vers le haut, ce qui est assumé et documenté (« une cible **par poste**, pas une répartition d'un total »). La conséquence ne l'est pas : **un club est en déficit à chaque poste, en permanence**, donc « ce poste est en déficit » ne distingue rien du tout. C'est ce qui a rendu le classement lexicographique de l'acheteur PNJ silencieusement catastrophique jusqu'au 2026-08-08 (0,5 % de transferts d'attaquants) — corrigé en classant par **ampleur relative** du déficit, ce qui reste informatif même quand tout est déficitaire.

Le déficit permanent, lui, n'est pas corrigé. Il n'est pas forcément à corriger — un monde où les effectifs sont maigres est un monde qui a un marché — mais tant qu'il tient, toute décision fondée sur un booléen « il me manque quelqu'un ici » est une décision fondée sur `true`.

Découvert en creusant la distorsion des postes, pas cherché.

---

## Décidées, à revisiter — pas des dettes

### R1 — `Person` s'accumule sans fin

**Déclencheur : un monde qui vit un siècle.** Et jamais sans un remplaçant pour les noms.

732 `Person` pour 373 entités vivantes à dix ans, l'écart valant exactement le nombre de `PlayerRetired`. C'était noté comme une dette au motif que rien ne le documentait ; le lot de l'histoire d'un club lui a trouvé un **usage réel** — c'est ce qui garde lisible le nom d'un joueur parti ou retraité. La rétention et son prix sont écrits dans le docblock de `Football\RetirementSystem`. L'état d'un monde croît donc linéairement avec son histoire, en connaissance de cause.

### R2 — Une fenêtre de dix ans ment sur l'équilibre compétitif

**Déclencheur : aucun — c'est un piège de lecture, pas un défaut du monde.**

Le club 17 gagne 7 des 9 saisons du monde `dix-ans`, quand le harness sur le **même build** et la même graine donne 12 champions distincts en 39 saisons (Gini 0,608, rotation 48,9 %). Aucune régression n'en est conclue : comparer un monde en base aux chiffres notés dans un document est précisément la comparaison interdite. Tout arbitrage d'équilibre se fait à **six graines appariées, dans un même build**, et au test du signe avant la moyenne.

---

## Candidates à la mécanisation

À sortir de ce document dès qu'un test les remplace. C'est la trajectoire normale d'une entrée, pas une punition.

| Piège | Ce qu'un test devrait exiger |
|---|---|
| `RulesetOverride::withFields()` reconstruit `Balance` **en entier** : tout groupe omis repart silencieusement à ses défauts — `PositionBalance` l'a été jusqu'au 2026-08-05 | qu'une reconstruction sans override rende un `Balance` **égal** au `Balance` de départ, groupe par groupe |
| Une métrique qui n'existe que comme **plafond muet** dans une assertion (`FieldableSquadTest` : « ≤ 5 % de club-années sans gardien ») laisse circuler pendant des jours un chiffre que plus personne ne recalcule | rien à tester — la réponse n'est pas une assertion de plus mais **l'impression de la valeur** : `Metrics\Sampler` la compte et `Report\TextReport` l'affiche depuis le 2026-08-08, donc chaque campagne la remet à jour |
| Un lot qui ajoute une entité au genesis **ne peut pas** être une réduction bit-à-bit d'un monde antérieur : les `EntityId` consommés décalent l'allocateur, donc les flux RNG | rien à tester — c'est une règle de **méthode** de comparaison, documentée dans `packages/worldgen/README.md` |

---

## Journal des dettes soldées

Gardé court, et uniquement pour ce qui a appris quelque chose.

| Date | Dette | Ce qu'elle a appris |
|---|---|---|
| 2026-08-08 | D5 — les club-années sans gardien jamais re-mesurées depuis que le marché existe | **Trois choses, et la dette avait tort sur les trois.** (1) Le marché n'a pas fermé l'écart : 2,41 % sur 6 graines, mais l'écart est **transitoire** — aucun club ne reste sans gardien deux années consécutives, ce que ni la dette ni le docblock ne disaient. (2) Le 1,39 % de référence était une lecture **mono-graine**, et c'était le bas d'une fourchette 1,39-3,61 % ; le même piège que les deux Gini avant lui, sur une métrique de plus. (3) Le doute inscrit dans la dette (« le PNJ chasse les fins de contrat, pas le poste qui manque ») était **faux** : les gardiens étaient 2,5× sur-représentés dans les transferts. Ce que la mesure a réellement trouvé était ailleurs et plus gros — **zéro attaquant transféré sur 261**, sur six graines et 120 saisons |
| 2026-08-08 | D1 — la CI ne couvrait ni `worldgen`, ni `host`, ni `api` | **Une suite qui se skippe proprement devient, en CI, un job vert qui n'a rien exécuté.** Le mécanisme de confort local (base injoignable ⇒ skip) et le mécanisme de garantie sont opposés, et il faut nommer le second : `--fail-on-skipped`, mesuré par sabotage (base arrêtée, `exit=0` sans le drapeau, `exit=1` avec). Deux silences trouvés au passage, de la même famille : le déclencheur `push` pointait sur `main` quand la branche est `master`, donc **il n'avait jamais tourné**, et quatre `phpunit.xml` validaient contre un schéma déprécié |
| 2026-08-08 | `PlayerRetired` et `TransferCounterDemanded` inattribuables à un club ; `SeasonConcluded` sans ses points | **Un Fait porte de quoi l'attribuer à ses sujets** — `16-` §2. L'état courant ne rattrape jamais ce qu'un Fait a omis, et le format des payloads n'a pas de version : la correction est gratuite tant qu'aucun monde ne compte, migration ensuite |
| 2026-08-08 | `harness/public/index.php` cassé depuis le lot worldgen, 43 champs décrits sur 82 | Un fichier **ni analysé ni exécuté** pourrit sans bruit. Réparer sans mécaniser remet le compteur à zéro : la liste est sortie du script pour qu'un test la confronte à sa source |
| 2026-08-08 | `AdvanceWorld` sous-estimait son coût de 29 % | L'écart avait un visage : charger le snapshot coûte ~6 ms, le verrou et le `COMMIT` 6,8. **Les trois cinquièmes d'un tick sont de la base**, pas seulement son écriture comme l'annonçait `13-` §7 |
