# 99 — Ressources externes et glossaire

## Partie 1 — Ressources externes

Regroupées par chapitre. Les références marquées **★** sont celles qui apportent le plus
pour comprendre ce projet en particulier ; les autres sont là si tu veux creuser.

### ECS et conception orientée données → [ch. 02](02-le-modele-de-donnees.md)

- ★ **ECS FAQ** — Sander Mertens. La meilleure introduction courte, orientée « pourquoi »
  plutôt que « comment ». <https://github.com/SanderMertens/ecs-faq>
- **ECS Back and Forth** — Michele Caini (auteur d'EnTT). Série d'articles sur les
  différentes implémentations d'ECS et leurs compromis (archétypes, sparse sets).
  <https://skypjack.github.io/2019-02-14-ecs-baf-part-1/>
- **Data-Oriented Design** — Richard Fabian. Le livre de fond sur la pensée « colonnes »
  plutôt que « objets ». Gratuit en ligne. <https://www.dataorienteddesign.com/dodbook/>
- **Unity DOTS / Bevy ECS** — les documentations de ces deux moteurs sont utiles pour voir
  le même modèle à une autre échelle (millions d'entités, contrainte temps réel — que ce
  projet n'a pas).

> À garder en tête : la littérature ECS est écrite pour des moteurs temps réel où la
> performance mémoire dicte tout (cache, SoA, archétypes). **Ici, la motivation est
> différente** : c'est la composition des rôles et la lisibilité de l'ordre de traitement.
> Les optimisations mémoire décrites dans ces ressources ne s'appliquent pas.

### Simulation, tick, déterminisme → [ch. 03](03-le-tick-et-le-pipeline.md), [ch. 05](05-determinisme-et-aleatoire.md)

- ★ **1500 Archers on a 28.8: Network Programming in Age of Empires and Beyond** — Paul
  Bettner & Mark Terrano, GDC 2001. Le texte fondateur sur la simulation déterministe en
  lockstep. Le contexte (réseau) diffère, mais les règles de discipline sont exactement
  celles de ce projet.
- **Fix Your Timestep!** — Glenn Fiedler. Sur la séparation entre le temps de simulation et
  le temps réel. <https://gafferongames.com/post/fix_your_timestep/>
- **What Every Computer Scientist Should Know About Floating-Point Arithmetic** — David
  Goldberg (1991). La référence sur la non-associativité et les pièges IEEE 754.

### Générateurs pseudo-aléatoires → [ch. 05](05-determinisme-et-aleatoire.md)

- ★ **xoshiro / xoroshiro generators** — David Blackman & Sebastiano Vigna. Le site inclut
  le code de référence, les résultats de tests statistiques et l'explication du choix des
  constantes. C'est la source directe de `Core\Support\Rng`. <https://prng.di.unimi.it/>
- **Fast Splittable Pseudorandom Number Generators** — Steele, Lea & Flood, OOPSLA 2014.
  L'article de SplitMix, dont dérive le `splitMix32` utilisé pour dérouler l'état initial.
- **PCG, A Family of Better Random Number Generators** — Melissa O'Neill. L'alternative
  envisagée dans la conception ; excellente lecture sur ce qui fait un bon PRNG.
  <https://www.pcg-random.org/>
- **MurmurHash3** — Austin Appleby. Le finisseur d'avalanche (`0x85EBCA6B`, `0xC2B2AE35`)
  utilisé dans `Hash::mix32()` et `Rng::splitMix32()`.

### Tri topologique → [ch. 03 §5](03-le-tick-et-le-pipeline.md#5-lordre-du-pipeline-est-dérivé-pas-écrit)

- ★ **Kahn, A. B. (1962), « Topological sorting of large networks »**, CACM 5(11).
  L'algorithme implémenté dans `SystemGraph::kahn()`.
- **Introduction to Algorithms** (CLRS), §22.4. Le traitement classique, avec l'alternative
  par DFS.
- Pour l'intuition rapide : l'article Wikipédia *Topological sorting* est suffisant.

### Modélisation des scores de football → [ch. 07 §B.2](07-algorithmes-football.md#b2-matchsystem--jouer-un-match)

- ★ **Dixon, M. J. & Coles, S. G. (1997), « Modelling Association Football Scores and
  Inefficiencies in the Football Betting Market »**, Journal of the Royal Statistical
  Society Series C, 46(2), 265-280. **La** référence du moteur de match L0 : le modèle de
  Poisson bivarié et la fonction de correction τ sur les scores faibles.
- **Maher, M. J. (1982), « Modelling association football scores »**, Statistica
  Neerlandica 36(3). Le modèle de Poisson d'origine, que Dixon-Coles corrige.
- **Karlis & Ntzoufras (2003)** sur les Poisson bivariées et les modèles à inflation de
  nuls — l'alternative à la correction τ.
- Pour l'intuition sans les maths : chercher « Dixon-Coles football model » sur des blogs
  de data science sportive ; beaucoup d'implémentations commentées en Python.

### Échantillonnage → [ch. 05 §7](05-determinisme-et-aleatoire.md#7-le-tirage-sans-rejet--inverse-de-la-cdf)

- **Inverse transform sampling** (Wikipédia) — la technique exacte utilisée par
  `PoissonMatchEngine`.
- **Order statistics** (Wikipédia) — pour comprendre pourquoi `min(U₁…U_k)` suit une
  `Beta(1, k)`.
- **Stochastic rounding** — le motif utilisé pour les cohortes et la progression. Cherché
  aussi sous le nom de *dithering* en traitement du signal.

### Event sourcing et messages → [ch. 04](04-messages-et-files.md)

- ★ **Event Sourcing** — Martin Fowler. L'article de référence, notamment sur le piège du
  rejeu quand les règles changent. <https://martinfowler.com/eaaDev/EventSourcing.html>
- **Greg Young — CQRS Documents.** La séparation lecture/écriture, dont ce projet applique
  la forme (le noyau écrit, les projections lisent).
- **Versioning in an Event Sourced System** — Greg Young. Directement pertinent pour le
  verrou `(kernelVersion, rulesetVersion)` du [ch. 06](06-le-ruleset.md).

### Boucles de rétroaction et équilibre → [ch. 08](08-boucles-et-retroactions.md)

- ★ **Thinking in Systems** — Donella Meadows. Le livre le plus utile pour raisonner sur
  les boucles positives/négatives, les délais, et pourquoi une contre-réaction retardée
  oscille. Accessible, sans maths.
- **Stefan Szymanski**, travaux sur l'équilibre compétitif en économie du sport — d'où
  vient l'usage du Gini pour mesurer la domination d'un championnat.
- **Coefficient de Gini** (Wikipédia) — la définition et ses limites (notamment sur des
  valeurs négatives, cas rencontré dans ce projet, voir [ch. 09](09-mesurer-le-monde.md)).

### Simulations de monde persistant — les références du genre

- **Tarn Adams, Dwarf Fortress** — les conférences GDC (« Villains », « Procedural
  Storytelling ») sur la narration émergente et le contrôle du volume d'événements.
- **Football Manager** — le modèle de référence pour la profondeur de simulation et la
  gestion de la perception (scouts qui se trompent).
- **Crusader Kings III** — pour la génération de récit à partir de traits et d'événements.
- **EVE Online** — l'économie persistante à grande échelle, et les rapports économiques
  publiés par CCP.
- **Hattrick** — le modèle de simulation asynchrone à faible fréquence.

Ces références sont analysées en détail dans `docs/10-etat-de-l-art.md`.

---

## Partie 2 — Glossaire

**Archétype** — L'ensemble des types de composants portés par une entité. Changer de rôle
(joueur → entraîneur) = changer d'archétype, en ajoutant et retirant des composants.
Jamais en détruisant l'entité.

**Arrondi stochastique** — Convertir un nombre fractionnaire en entier en tirant la partie
décimale : 1,2 donne 1 avec 80 % de chances, 2 avec 20 %. Préserve l'espérance exacte
malgré des résultats entiers. Utilisé pour la taille des cohortes de jeunes et pour la
progression des compétences.

**Canal 1 / Canal 2** — Les deux moyens qu'a un système de se faire entendre. Canal 1 :
écrire un composant lu plus loin dans le même tick (latence 0, crée une contrainte
d'ordre). Canal 2 : émettre un événement traité au tick suivant (latence 1 tick, aucune
contrainte).

**Cascade** — Un événement qui en déclenche un autre, qui en déclenche un autre. Bornée
structurellement ici : un événement émis au tick N n'est jamais traité avant le tick N+1.

**Composant** — Un paquet de données pures et immuables (`final readonly`) attaché à une
entité. Sans comportement. Stocké dans une colonne (`ComponentStore`) indexée par entité.

**DecisionRequest** — « Quelqu'un doit trancher. » Message transitoire, jamais journalisé,
qui se résout ou expire. Aucune implémentation à ce jour.

**Déterminisme** — Mêmes entrées + même graine ⇒ mêmes sorties, bit pour bit.

**Dixon-Coles** — Modèle de score de football : deux lois de Poisson (une par équipe)
corrigées par une fonction τ sur les quatre scores faibles, pour ne pas sous-estimer les
nuls serrés.

**Entité** — Un entier opaque, stable, jamais réutilisé. Aucune donnée, aucun comportement.

**Fait (`DomainEvent`)** — « Ceci est arrivé. » Passé, immuable, journalisé pour toujours.
À ne jamais confondre avec un `Intent` (futur) ou une `DecisionRequest` (question).

**Gini (coefficient de)** — Mesure d'inégalité entre 0 (égalité parfaite) et 1
(concentration totale). Appliqué ici aux titres de champion et aux revenus.

**Graines appariées** — Rejouer exactement le même jeu de graines avec deux `Ruleset`
différents, pour isoler l'effet d'un paramètre du bruit stochastique.

**Idempotence** — Traiter deux fois le même message produit le même résultat qu'une fois.
Obtenue ici en écrivant des valeurs absolues plutôt que des deltas.

**Intent** — « Voici ce que je fais. » Futur immédiat, consommé une fois. Produit
indifféremment par un humain ou un PNJ, via la même interface.

**Kahn (algorithme de)** — Tri topologique par retrait répété des sommets sans
prédécesseur. Utilisé pour dériver l'ordre du pipeline depuis les déclarations des
systèmes.

**LOD (niveau de détail)** — Simuler avec plus ou moins de finesse selon l'importance. Le
moteur de match L0 (Dixon-Coles, un tirage) est le niveau grossier ; un L1 (chaîne de
Markov de possession) reste à écrire.

**OutQueue** — La file de propagation d'un tick au suivant. Remplie par `emit()` pendant le
tick N, vidée en début de tick N+1. « InQueue » désigne la même file lue à l'autre bout.

**Perception (vs vérité cachée)** — Les compétences réelles d'un joueur ne sont jamais
exposées à un client ; ce qu'un observateur croit est **dérivé à la lecture**, avec un
bruit déterministe fonction de l'observateur. Modèle visé, pas encore implémenté.

**Pipeline** — La liste ordonnée des systèmes exécutés à chaque tick. Déclarée une fois
dans `FootballPipeline`, ordonnée par `SystemGraph`.

**PRNG** — Générateur pseudo-aléatoire : suite déterministe issue d'un état interne, mais
statistiquement indiscernable du hasard. Ici : xoshiro128\*\*.

**Ruleset** — Le paquet versionné de toutes les valeurs numériques qui paramètrent le
monde. Un monde est épinglé à `(kernelVersion, rulesetVersion)`.

**Scheduler** — La file des événements datés : « déclenche ceci au tick 8 432 ». Utilisé
pour les coups d'envoi et la fin de saison.

**Seuil d'émission** — Le test qui décide si une mutation mérite un Fait : franchit-elle
un seuil comportemental, est-elle irréversible, est-elle racontable ? Sinon, le système
se tait.

**Singleton** — Un état global du monde, adressé par type, en un seul exemplaire
(`MonetaryMass`). Une donnée qui peut exister en deux exemplaires est un composant, pas un
singleton.

**Stationnarité** — Propriété d'une population dont la taille et la structure d'âge se
stabilisent au lieu de croître ou de s'effondrer. Premier critère de santé du monde.

**Système** — Une unité de comportement sans état propre, qui traite le monde en masse.
Réactif (`handle()`), périodique (`update()`), ou les deux.

**Tick** — Une itération de la simulation. Ici, un jour simulé.

**Tri topologique** — Ordonner les sommets d'un graphe orienté sans cycle de sorte que
chaque arête aille de la gauche vers la droite.

**Vérité cachée** — Voir *Perception*.

---

**Retour au [sommaire](README.md).**
</content>
</invoke>
