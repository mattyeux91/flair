# État de l'art — simulations de monde persistant & modèles de football

Objectif : savoir ce qui a déjà été résolu, et surtout **ce qui a déjà tué des projets similaires**, avant de choisir une architecture.

---

## 1. Les jeux de référence

### Football Manager (Sports Interactive)

Ce qu'il faut en retenir, pas pour copier mais pour comprendre les arbitrages :

| Élément | Choix de FM | Ce qu'on en tire |
|---|---|---|
| Force du joueur | `CA` (Current Ability) / `PA` (Potential Ability), 0-200, PA fixé à vie (parfois en fourchette pour les -21 ans) | L'idée de **potentiel caché** est bonne. Le **plafond dur** est mauvais : il rend la progression binaire et frustrante (« ce joueur a un PA de 120, il est mort »). On préférera une **asymptote souple**. |
| Attributs | ~250 attributs par joueur, `CA` = somme pondérée selon le poste | Ingérable en solo. Personne ne peut équilibrer 250 dimensions. On visera **8 à 12 attributs orthogonaux**. |
| Moteur de match | Simulation positionnelle 2D quasi-physique, très coûteuse | C'est le piège n°1 des clones indés. Voir `14-algorithmes.md` : on fait du **LOD** (niveau de détail variable). |
| Traitement | Mono-thread par ligue, base de données géante chargée en RAM | Confirme qu'un monde peut vivre dans un seul processus. Bonne nouvelle pour nous. |
| Modding | Éditeur officiel + fichiers de règles de compétition externalisés | **Les formats de compétition sont de la donnée, pas du code.** À reprendre tel quel. |

Le vrai enseignement FM : le jeu ne tient pas grâce au réalisme de son moteur, il tient grâce à la **densité narrative émergente** (un régen qui perce, un club qui coule, une rivalité). Le moteur n'est qu'un générateur d'anecdotes.

### Hattrick / Blogfoot (navigateur, monde persistant, ~2000)

- Tick hebdomadaire, économie fermée, des dizaines de milliers de joueurs humains dans le même monde.
- Ce qui a marché : **temps réel lent**, respectueux du temps du joueur ; économie où l'action d'un joueur affecte les autres.
- Ce qui a mal vieilli : inflation monétaire non maîtrisée, clubs historiques indéboulonnables (pas de régulation compétitive), interface datée.

**Leçon centrale : un monde persistant multi-joueurs meurt d'inflation et de sclérose, pas de bugs.** Ces deux risques doivent être mesurés dès le premier prototype.

### Crusader Kings / Dwarf Fortress

Pas du football, mais ce sont **les deux meilleures références d'architecture** pour ce projet.

- **Dwarf Fortress** : génération de monde avec plusieurs siècles d'histoire simulée avant que le joueur n'arrive. Entités décrites dans des fichiers de données (`raws`) — créatures, matériaux, réactions — interprétés par le moteur. Ajouter du contenu ≠ recompiler.
- **Crusader Kings 3** : simulation centrée personnages (traits, ambitions, relations, opinions), avec un **système d'événements scripté et data-driven** (déclencheur → conditions → effets pondérés). La narration n'est pas écrite à la main, elle est *détectée* et *habillée*.

C'est exactement le modèle à viser pour « administrer les règles » : **un noyau qui interprète des règles décrites en données versionnées.**

### EVE Online

Économie persistante à grande échelle, avec un économiste salarié et des rapports mensuels publics. À retenir : **on ne pilote pas une économie simulée sans instrumentation.** Les métriques économiques font partie du produit, pas du debug.

### Blaseball

Simulation absurde, presque sans interaction, mais avec une communauté énorme. Preuve que **le spectacle d'une simulation qui tourne peut suffire** si le flux d'événements est lisible et partageable. Pertinent pour le client « spectateur / administrateur ».

---

## 2. Les modèles académiques utilisables

### Résultat de match

| Modèle | Principe | Coût | Usage chez nous |
|---|---|---|---|
| **Maher (1982)** — Poisson indépendant | Chaque équipe a un paramètre attaque/défense, buts ~ Poisson(λ) | Négligeable | Base |
| **Dixon-Coles (1997)** — Poisson bivarié | Ajoute un paramètre `ρ` corrigeant la sous-estimation des scores faibles (0-0, 1-1) + pondération temporelle des matchs récents | Négligeable | **Moteur L0** (matchs non observés, runs d'équilibrage massifs) |
| **Karlis & Ntzoufras** — Poisson bivarié dynamique | Corrélation explicite entre les deux scores, paramètres qui évoluent dans le temps | Faible | Raffinement de L0 si besoin |
| **Chaînes de Markov de possession** (Rudd 2011, *expected threat*) | Le terrain est découpé en zones ; une possession est une suite d'états ; chaque état a une probabilité de mener à un but | Faible à moyen | **Moteur L1 — notre défaut.** Produit un flux d'événements (passes, tirs, xG) donc de la narration et des stats |
| **VAEP / xT** (Decroos et al.) | Valeur d'une action = variation de la probabilité de marquer/encaisser | Gratuit si on a L1 | **Notes des joueurs** dérivées du match, sans code dédié |
| Simulation multi-agents positionnelle | Chaque joueur est un agent avec pathfinding et décision | Élevé | Hors périmètre v1. C'est le piège FM. |

Le point clé : **les modèles L0 et L1 doivent être calibrés pour produire les mêmes distributions agrégées.** Sinon, promouvoir une ligue de L0 vers L1 change l'histoire du monde.

### Marché des transferts

- La littérature économétrique (valorisation par régression multi-niveaux : âge, poste, performance, réputation, temps de contrat restant) donne de bonnes **fonctions de valorisation**, mais pas de dynamique.
- Les travaux **multi-agents** (ABM appliqué au fair-play financier et à l'équilibre compétitif des cinq grands championnats) montrent le résultat qui nous intéresse : **les grands clubs restent dominants malgré la régulation**. C'est précisément le comportement qu'il faudra contrer *volontairement* dans notre monde, sous peine de sclérose.
- Le marché ne doit **pas** être modélisé comme une enchère qui converge instantanément : ça produit un marché « résolu » et sans histoire. On veut de la négociation séquentielle multi-tours, avec information imparfaite.

### Équilibre compétitif

Littérature en économie du sport (Szymanski) : indices de concentration (Gini, HHI) appliqués aux titres et aux revenus. Directement réutilisable comme **test automatisé de santé du monde** : on simule 20 saisons, on mesure, on refuse la régression.

---

## 3. Les patterns d'ingénierie applicables

- **Event sourcing** : le journal d'événements immuable et ordonné permet de reconstruire n'importe quel état passé, avec des *snapshots* périodiques pour éviter de rejouer depuis l'origine. Donne aussi le *time-travel debugging*.
- **Rejeu déterministe** : on journalise les sources de non-déterminisme (graine aléatoire, entrées) pour reproduire une exécution à l'identique. C'est ce qui rend une simulation testable et équilibrable.
- **Serveur autoritaire** : le monde n'est calculé qu'au serveur ; les clients n'envoient que des intentions. Élimine tout besoin de déterminisme multi-plateforme (voir `13-moteur-de-simulation.md`).

⚠️ **Piège documenté** : event sourcing + règles qui évoluent = rejeu cassé. Un même journal rejoué par une version plus récente du noyau produit un état différent. Traité explicitement dans `13-moteur-de-simulation.md` §6.

---

## 4. Synthèse — ce qu'on emprunte à qui

| Source | Ce qu'on prend |
|---|---|
| FM | Potentiel caché, formats de compétition en données, monde mono-processus |
| Hattrick | Tick lent, économie fermée — **et ses deux causes de mort à instrumenter** |
| Dwarf Fortress | Règles et entités décrites en données interprétées |
| Crusader Kings | Système d'événements narratifs data-driven, simulation centrée personnages |
| EVE | L'instrumentation économique comme partie du produit |
| Dixon-Coles | Moteur de match L0 |
| Markov / xT | Moteur de match L1 + notation des joueurs gratuite |
| ABM sport | Le comportement à contrer : la domination des riches |
| Event sourcing / rejeu déterministe | Le socle technique du noyau |
| **`ressource.md`** (interne) | Files inter-ticks, seuils d'émission, taxonomie Faits/Décisions, Event Monitor |
| **`ressource2.md`** (interne) | État global en singletons, digest de retour d'absence, boucles de rétroaction nommées |

### Les deux sources internes

`docs/archive/ressource.md` et `docs/archive/ressource2.md` sont issus d'une discussion entre Matt et un ami. Ils décrivent un moteur **événementiel** là où l'architecture retenue part d'un **pipeline ordonné** — la synthèse des deux est le tick hybride de `13-` §2, et le détail des emprunts est dans `16-evenements-et-cascades.md`.

Ce qu'ils apportent de meilleur : une bien meilleure intuition de la propagation, des cascades et de l'émergence. Ce qu'ils ne traitent pas : déterminisme, persistance, versionnage des règles, mesure de l'équilibre — c'est-à-dire ce qui décide si un monde persistant survit à sa deuxième année. Les deux points explicitement écartés (le limiteur de profondeur en production, la stack Rust) sont argumentés respectivement en `16-` §3 et `11-` §6.

---

## Sources

- [Dixon-Coles, modèle bivarié — synthèse](https://www.emergentmind.com/topics/bivariate-dixon-and-coles-model)
- [Predicting Football Results With Statistical Modelling: Dixon-Coles and Time-Weighting](https://dashee87.github.io/football/python/predicting-football-results-with-statistical-modelling-dixon-coles-and-time-weighting/)
- [A Dynamic Bivariate Poisson Model (Tinbergen Institute)](https://papers.tinbergen.nl/12099.pdf)
- [Extending the Dixon and Coles model: women's football data (arXiv)](https://arxiv.org/pdf/2307.02139)
- [Attacking Contributions: Markov Models for Football (StatsBomb)](https://blogarchive.statsbomb.com/articles/soccer/attacking-contributions-markov-models-for-football/)
- [Markov chains — Soccermatics](https://soccermatics.readthedocs.io/en/latest/gallery/lesson4/plot_MarkovChain.html)
- [Modelling Football as a Markov Process (DiVA)](https://www.diva-portal.org/smash/get/diva2:828101/FULLTEXT01.pdf)
- [ABM Model for Football Transfer Market](https://www.academia.edu/127088349/ABM_Model_for_Football_Transfer_Market)
- [Beyond crowd judgments: data-driven estimation of market value](https://www.sciencedirect.com/science/article/pii/S0377221717304332)
- [Econometric Approach to Assessing Transfer Fees (MDPI)](https://www.mdpi.com/2227-7099/10/1/4)
- [Current Ability & Potential Ability — FM Scout](https://www.fmscout.com/a-guide-to-current-ability-in-football-manager.html)
- [Potential Ability — Football Manager Wiki](https://footballmanager.fandom.com/wiki/Potential_Ability)
- [How the event sourcing design pattern works](https://www.theserverside.com/tip/How-the-event-sourcing-design-pattern-works-with-example)
