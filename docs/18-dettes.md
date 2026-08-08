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

### D1 — La CI ne couvre ni `host` ni `api`

**Déclencheur : maintenant.** C'est la seule entrée sans aucun autre foyer.

`.github/workflows/ci.yml` a deux jobs, `kernel` et `harness`. Les deux paquets qui touchent PostgreSQL n'y sont pas — donc **37 tests et 1 177 assertions ne tournent qu'en local**, dont `CrashRecoveryTest`, `PersistedWorldMatchesMemoryTest` et toute la couche de lecture. Un test qui ne tourne qu'à la main est un test dont on découvre l'échec au pire moment.

Ce qu'il faut : un `services: postgres` dans le job, `bin/host.php install`, et les variables `FLAIR_DB_*` (défauts dans `docker-compose.yml`, port 54329). Coût réel : la moitié d'une soirée, l'essentiel étant de faire attendre le conteneur avant de lancer la suite.

> ⚠️ **Et pendant qu'on y est** : le job `harness` doit appeler `composer analyse`, pas `vendor/bin/phpstan analyse` — depuis que `public/` est analysé, 128 Mo ne suffisent plus. Corrigé le 2026-08-08 ; à ne pas défaire.

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

### D5 — Les club-années sans gardien n'ont pas été re-mesurées depuis que le marché existe

**Déclencheur : maintenant — c'est une mesure, pas un développement.**

Le lot des postes a fait tomber les club-années sans gardien de 7,87 % à 1,39 %, et a laissé le reste au marché des transferts, qui n'existait pas encore. Il existe depuis. **Personne n'a vérifié qu'il a refermé l'écart**, et il y a une raison sérieuse d'en douter : le PNJ maximise `qualité perçue / prix`, ce qui le pousse vers les fins de contrat bradées — pas nécessairement vers le poste qui manque.

Une campagne à graines appariées répond en une exécution du harness. Tant qu'elle n'est pas faite, « le marché fermera l'écart » est une hypothèse, pas un résultat.

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
| Un lot qui ajoute une entité au genesis **ne peut pas** être une réduction bit-à-bit d'un monde antérieur : les `EntityId` consommés décalent l'allocateur, donc les flux RNG | rien à tester — c'est une règle de **méthode** de comparaison, documentée dans `packages/worldgen/README.md` |

---

## Journal des dettes soldées

Gardé court, et uniquement pour ce qui a appris quelque chose.

| Date | Dette | Ce qu'elle a appris |
|---|---|---|
| 2026-08-08 | `PlayerRetired` et `TransferCounterDemanded` inattribuables à un club ; `SeasonConcluded` sans ses points | **Un Fait porte de quoi l'attribuer à ses sujets** — `16-` §2. L'état courant ne rattrape jamais ce qu'un Fait a omis, et le format des payloads n'a pas de version : la correction est gratuite tant qu'aucun monde ne compte, migration ensuite |
| 2026-08-08 | `harness/public/index.php` cassé depuis le lot worldgen, 43 champs décrits sur 82 | Un fichier **ni analysé ni exécuté** pourrit sans bruit. Réparer sans mécaniser remet le compteur à zéro : la liste est sortie du script pour qu'un test la confronte à sa source |
| 2026-08-08 | `AdvanceWorld` sous-estimait son coût de 29 % | L'écart avait un visage : charger le snapshot coûte ~6 ms, le verrou et le `COMMIT` 6,8. **Les trois cinquièmes d'un tick sont de la base**, pas seulement son écriture comme l'annonçait `13-` §7 |
