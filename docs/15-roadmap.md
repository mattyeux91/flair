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

### Phase 1 — Le harness d'équilibrage *(≈ 2 semaines)*

1 000 saisons sans tête, métriques de santé du monde (Gini, inflation, rotation du sommet), rapport automatique, test de régression en CI. Test de déterminisme (même graine → même hash de l'état **et** de la séquence d'événements).

Deux ajouts qui font la valeur de cette phase :

- **Comparaison à graines appariées** — le mode par défaut. On rejoue le *même* jeu de graines avant et après un changement de `ruleset.balance`, ce qui isole l'effet du bruit (`13-` §4.0). C'est ce qui rend le critère de sortie ci-dessous réellement atteignable : sans appariement, il faudrait 5 à 20 fois plus de runs pour la même confiance.
- **Métriques de graphe d'événements** — volume par type, profondeur, entités sur-modifiées, croissance des files (`16-` §6). Une boucle non amortie ne se voit pas dans les métriques métier ; elle se voit ici.

> **Critère de sortie :** modifier une valeur de `ruleset.balance` et **voir l'effet chiffré** sur la santé du monde en moins de 5 minutes.

À partir d'ici tu pilotes au lieu de deviner. C'est ton avantage sur un studio.

### Phase 2 — Économie et marché *(≈ 4 semaines)*

Finances des clubs, grand livre monétaire, contrats, marché des transferts multi-tours, perception/scouting, agents PNJ.

> **Critère de sortie :** invariant de conservation monétaire vert sur 20 saisons, et inflation dans la cible du ruleset.

### Phase 3 — Persistance et temps réel *(≈ 3 semaines)*

Event store, snapshots, boucle du Host, cadence temps réel, verrou mono-writer, un monde qui tourne en continu.

> **Critère de sortie :** tuer le processus au hasard, le relancer, et le monde reprend sans incohérence.

### Phase 4 — API + admin *(≈ 4 semaines)*

Projections, API de requêtes, flux SSE, IHM d'administration pour explorer et éditer le monde.

**Livrable à part entière : le digest de retour d'absence** (`14-` §9). « Il s'est passé trois mois, qu'est-ce que j'ai raté ? » est *la* question d'un monde persistant, et c'est ce qui transforme l'absence en ellipse narrative plutôt qu'en punition. Quasi gratuit une fois l'event log et les seuils en place — et c'est aussi le meilleur contrôle qualité de tes seuils d'émission : un digest illisible signale des seuils mal réglés.

> **Critère de sortie :** naviguer dans dix ans d'histoire d'un club depuis un navigateur, et lire un digest de trois mois d'absence qui se comprend en trente secondes.

C'est la première fois que le monde devient *visible*. Ne repousse pas cette phase plus loin — psychologiquement, elle compte.

### Phase 5 — Le jeu d'agent *(≈ 6 semaines, et le vrai inconnu)*

Client d'incarnation : recruter un client, le scouter, le placer, négocier, gérer sa carrière.

> **Critère de sortie :** trois personnes extérieures jouent une semaine et **reviennent** sans qu'on le leur demande.

⚠️ **À faire en prototype papier / CLI dès maintenant, en parallèle de la phase 0.** C'est la seule inconnue qui peut tuer le projet, et elle ne coûte que quelques heures à lever. Ne découvre pas en phase 5 que le métier d'agent n'est pas amusant.

### Phase 6 — Profondeur

Moteur L1 Markov, narration émergente, multi-pays, coupes continentales, médias.

---

## 5. Ce qu'il ne faut pas faire

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
| `ressource.md`, `ressource2.md` | Sources internes (discussion avec un ami) — matière première, non normatives |
