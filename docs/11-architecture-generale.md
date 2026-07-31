# Architecture générale

## 1. La décision structurante

> **Le noyau de simulation est une fonction pure, déterministe, sans aucune I/O.**

```php
function step(WorldState $state, TickContext $ctx): StepResult;
// TickContext : readonly { int $tick, int $seed, Intent[] $intents, Ruleset $ruleset }
// StepResult  : readonly { WorldState $state, DomainEvent[] $events }
```

⚠️ **Déterministe ≠ prévisible.** Le monde reste plein d'aléatoire — blessures, résultats, éclosions de jeunes. Le déterminisme dit seulement : *mêmes entrées + même graine → mêmes sorties*. Deux mondes de graines différentes n'ont rien à voir, et aucun joueur ne perçoit la différence. C'est une propriété de l'outillage de développement, pas du gameplay.

Tout le reste de l'architecture découle de cette décision. Ce qu'elle débloque, par valeur réelle décroissante :

1. **Équilibrer à l'échelle** — faire tourner 1 000 saisons sans tête, avec **les mêmes graines** avant et après un changement de paramètre, pour isoler l'effet du bruit. C'est *le* superpouvoir d'un dev solo sur ce type de jeu, et le détail est en `13-` §4.0.
2. **Tester la simulation sans infrastructure** — pas de DB, pas de serveur, pas de mock. Et des tests de non-régression exacts (`hash(état) == X`) plutôt que statistiques et instables.
3. **Rejouer et déboguer dans le temps** — un bug de saison 12 se reproduit à partir d'une graine. Bénéfice réel, mais **déjà partiellement couvert par l'event sourcing seul** : le journal des faits montre la chaîne causale sans réexécution.

Ce que le déterminisme **n'apporte pas**, contrairement à une idée reçue : l'autorité serveur. Celle-ci découle du fait que **le client n'exécute rien** — c'est une propriété du découpage Host/Kernel, pas du déterminisme. Ce dernier est indispensable au *lockstep* multijoueur, qu'on ne fait pas.

Corollaire de cette même autorité serveur : puisque seul le serveur exécute le noyau, on n'a pas besoin de déterminisme flottant multi-plateforme. Une même machine doit être reproductible, c'est tout. Ça évite de partir sur de l'arithmétique en virgule fixe.

---

## 2. Vue macro

```
┌────────────────────────────────────────────────────────────┐
│  CLIENTS                                                   │
│  admin-web (exploration/édition)   game-web (agent joué)   │
└───────────────────────────┬────────────────────────────────┘
                            │  HTTP/JSON  +  SSE (flux d'événements)
┌───────────────────────────▼────────────────────────────────┐
│  API / BFF                                                 │
│  auth · commandes (intentions) · requêtes (read models)    │
│  · abonnement au flux · rate limiting                      │
└───────────────────────────┬────────────────────────────────┘
                            │
┌───────────────────────────▼────────────────────────────────┐
│  WORLD HOST                     (par monde : 1 acteur)     │
│  horloge · boucle de tick · transactions · jobs            │
│  ├── Intent inbox       ├── Projections (read models)      │
│  └── Event store        └── Snapshots                      │
└───────────────────────────┬────────────────────────────────┘
                            │  appelle step()
┌───────────────────────────▼────────────────────────────────┐
│  SIMULATION KERNEL          (pur · 0 dépendance · testable) │
│  ECS (entités · composants · singletons)                   │
│  pipeline de systèmes ordonné                              │
│  ├── Scheduler (événements datés)                          │
│  └── InQueue / OutQueue (propagation inter-ticks)          │
│  moteurs de match (LOD) · ruleset · PRNG déterministe      │
└────────────────────────────────────────────────────────────┘
```

Architecture **hexagonale** : les dépendances pointent vers l'intérieur. Le noyau ne connaît ni la DB, ni HTTP, ni l'horloge murale. Le Host connaît le noyau. L'API connaît le Host. Les clients ne connaissent que l'API.

### Séparation à ne pas rater : Host ≠ Kernel

C'est la confusion la plus courante dans ce genre de projet.

| | Simulation Kernel | World Host |
|---|---|---|
| Responsabilité | **Les règles du monde** | **La vie du monde** |
| Connaît | des données | la DB, l'horloge, la file d'intentions |
| Sait quoi | « voici l'état après un jour » | « il est temps de faire un jour », « voici comment on le persiste » |
| Testabilité | unitaire, instantanée | intégration |

Si tu te retrouves à écrire `await db.save()` dans un système de simulation, la frontière est violée.

---

## 3. Les intentions : le point d'entrée unique

Toute action extérieure au monde entre par une **intention** (`Intent`), jamais par une mutation directe.

```php
interface Intent {}   // marqueur

final readonly class OfferPlayerToClub implements Intent {
    public function __construct(
        public int $agentId,
        public int $playerId,
        public int $clubId,
        public Money $askingWage,
    ) {}
}

final readonly class BidForPlayer implements Intent { /* clubId, playerId, fee, terms */ }
final readonly class AcceptOffer  implements Intent { /* playerId, offerId */ }
```

Elles sont mises en file, validées, puis consommées au tick suivant par le système d'ingestion.

**Conséquence majeure de design** : un agent humain et un agent PNJ produisent le **même type d'intentions**, via la même interface.

```php
interface IntentSource
{
    /** @return list<Intent> */
    public function produce(WorldView $view, int $tick): array;
}
```

- `HumanIntentSource` → lit la file remplie par l'API.
- `NpcAgentIntentSource` → applique l'IA de décision.

C'est une application directe du principe de substitution de Liskov, et ça donne deux propriétés gratuites :
- un monde **100 % PNJ est jouable et testable** (indispensable : le monde doit être intéressant avec un seul humain dedans) ;
- si un joueur humain part, son agent peut être repris par l'IA sans rien casser.

### Les trois messages du monde

L'intention n'est qu'un des trois types de messages qui circulent, et les confondre est l'erreur de conception la plus coûteuse :

| | **Fait** | **DecisionRequest** | **Intent** |
|---|---|---|---|
| Sens | « ceci est arrivé » | « quelqu'un doit trancher » | « voici ce que je fais » |
| Émis par | les systèmes | les systèmes | les agents (humains **et** PNJ) |
| Journalisé | event log | ❌ transitoire | intent log |
| Exemple | `MatchFinished` | `ClubNeedsRecruitment` | `BidForPlayer` |

```
Fait  ──→  DecisionRequest  ──→  délibération d'un agent  ──→  Intent  ──→  Faits
```

Le `DecisionRequest` est le maillon qui manquait : il **pose une question sans déclencher d'action**, et c'est lui qui rend humains et PNJ réellement symétriques — les deux reçoivent les mêmes questions et répondent par les mêmes intentions. Détail complet dans `16-evenements-et-cascades.md` §1.

---

## 4. CQRS : écriture ≠ lecture

- **Côté écriture** : intentions → noyau → événements → event store. Le `WorldState` en mémoire est la vérité de travail.
- **Côté lecture** : les événements alimentent des **projections** dénormalisées (classement, fiche joueur, marché, historique d'un club) dans PostgreSQL.
- Les clients ne lisent **jamais** l'état du monde directement. Ils lisent des projections taillées pour leur écran.

Bénéfice concret : ajouter un écran ne touche pas au noyau. Ça devient une projection de plus.

Bénéfice secondaire : les projections encodent naturellement la **visibilité**. Un agent ne voit pas les attributs réels d'un joueur, il voit *sa perception*. La projection est le lieu naturel du filtrage d'information (voir `12-modele-du-monde.md` §4).

---

## 5. Multi-monde

Un monde = **un acteur logique mono-thread**, identifié par :

```
worldId · rulesetVersion · seed · kernelVersion
```

Règles :
- **Aucune transaction inter-mondes.** Jamais. C'est ce qui rend le passage à l'échelle trivial : plus de mondes = plus de processus.
- Un monde tient en mémoire (voir le dimensionnement dans `13-moteur-de-simulation.md` §6) ; la DB est un journal, pas la mémoire de travail.
- Chaque monde a son **mapping temps simulé ↔ temps réel** configurable : `1 jour simulé = 1 h réelle` en production, `= 0 s` pour les mondes de test et le harness d'équilibrage.

Types de mondes prévus dès le départ :

| Type | Vitesse | Usage |
|---|---|---|
| `live` | 1 jour / 1 h | monde de production, joueurs humains |
| `sandbox` | accéléré | test manuel, démo, replay |
| `harness` | max | équilibrage massif sans persistance |

---

## 6. Stack — PHP 8.3+

**Décision (2026-07-31) : PHP pour le noyau, le Host et l'API.**

L'architecture décrite ici est **agnostique du langage** — c'est précisément l'intérêt d'un noyau pur fait de fonctions sur données plates. PHP la porte sans compromis :

- **La performance n'est pas le facteur limitant** (dimensionnement §6 de `13-`). PHP 8 avec JIT est du même ordre de grandeur que Node sur de la logique métier, sensiblement plus lent seulement sur les boucles numériques serrées. Concrètement : le harness passe de « 40 min » à « quelques heures en tâche de nuit ». Ça ne change aucune décision d'architecture.
- **Le modèle de processus de PHP colle au besoin.** À `1 tick = 1 h réelle`, le monde `live` n'a besoin d'aucun démon : `charger le snapshot → step() → persister → sortir`, piloté par cron. C'est le grain naturel de PHP. Le harness est un script CLI, donc long-vivant par nature. Un worker persistant (Swoole / FrankenPHP / RoadRunner) ne devient utile que si l'on veut des ticks rapides plus tard — la porte reste ouverte.
- **PHP 8 tient le code métier dense** : propriétés typées, `enum`, `readonly`, + PHPStan au niveau max ≈ sécurité proche du statique.
- Le noyau restant sans dépendance et en données plates, il reste **portable** vers un autre langage si un jour c'est justifié.

### ⚠️ Le piège n°1 : le PRNG

PHP n'a pas d'entiers non signés, et **un dépassement d'`int` bascule silencieusement en `float`**. Un PRNG 64 bits naïf produira des résultats non reproductibles sans qu'aucune erreur ne soit levée — et tout le déterminisme du projet (§1) meurt là, silencieusement.

> Implémenter un **PRNG 32 bits** (PCG32 ou xoshiro128\*\*) avec masquage explicite `& 0xFFFFFFFF` après chaque opération. Ne jamais utiliser `mt_rand` / `random_int`. ~40 lignes, à écrire et à tester **en tout premier**, avec des vecteurs de test.

### Le reste

| Brique | Choix | Pourquoi pas autre chose |
|---|---|---|
| Stockage | **PostgreSQL seul** — `jsonb` pour les événements, tables classiques pour les projections | Pas de Kafka, pas de Redis, pas de Mongo. Un seul système à exploiter en solo. |
| ORM | **Aucun dans le noyau.** Doctrine autorisé côté projections/API uniquement | Un ORM sur l'état du monde est un désastre : hydratation, identity map, lazy loading. Le noyau manipule des données plates ; la persistance c'est snapshot sérialisé + append d'événements. |
| Framework | Symfony (ou Laravel) pour `host` / `api` / `admin` — **jamais dans `kernel`** | Le noyau doit rester exécutable dans un test unitaire nu. |
| Temps réel | **SSE** (pas WebSocket) | Flux unidirectionnel serveur→client. Les actions passent par HTTP. Plus simple, traverse tout, se reconnecte seul. |
| Admin | Symfony + EasyAdmin, ou Laravel + Filament | Très productif pour de l'exploration/édition de données — un avantage net de l'écosystème PHP ici. |
| Client de jeu | Web d'abord ; JS inévitable (Livewire/Inertia pour en limiter la quantité) | Un seul front à maintenir tant que le jeu n'est pas validé. |
| Qualité | PHPStan niveau max sur `kernel`, obligatoire | C'est ce qui remplace le typage de compilation. |

### Points de vigilance PHP

- **Mémoire** : les tableaux PHP sont des hashmaps, coûteux. Préférer des classes à propriétés typées ou du structure-of-arrays pour les composants. Monter `memory_limit` pour le harness.
- **JIT** : activer `opcache.jit=tracing` pour le harness (gain réel sur les boucles), inutile pour le tick `live`.
- **Parallélisme du harness** : N processus CLI indépendants (`xargs -P`, GNU parallel) — pas besoin de `pcntl_fork`, chaque graine est indépendante.
- **Le harness tourne en L0 uniquement.** 1 000 saisons en L1 Markov seraient déraisonnables dans n'importe quel langage (cf. `14-algorithmes.md` §1).

### Arbitrage sur la stack proposée dans `ressource2.md` §9

La proposition issue de la discussion avec l'ami était : `Laravel → Message Queue → Rust (moteur) → PostgreSQL / Redis`.

**La forme est validée et converge** avec l'architecture décrite ici : couche API, file d'intentions, moteur isolé, stockage. C'est le même découpage.

Les technologies, en revanche, sont écartées — décision tracée ici pour qu'elle ne se rejoue pas :

| Proposition | Arbitrage |
|---|---|
| **Rust pour le moteur** | ❌ Optimise un non-goulot : le CPU n'est pas le facteur limitant (§7 de `13-`, une saison en L0 de l'ordre de la seconde). Coût réel : deux langages et une frontière à maintenir pour un dev solo, plus une courbe d'apprentissage cumulée à la conception d'une simulation originale. Argument honnête *pour* Rust : il élimine le piège du dépassement d'`int` PHP et donne de vraies garanties de déterminisme — mais ça se règle en ~40 lignes testées une fois. |
| **Message Queue dédiée** | ❌ Juste sur le principe (c'est l'*intent inbox*), surdimensionné en pratique. Une table Postgres + advisory lock fait le travail à cette échelle. |
| **Redis** | ⚠️ D'accord pour du cache ou des sessions. **Jamais pour l'état du monde** : la base est un journal, pas la mémoire de travail. |
| **Laravel pour l'API/IHM** | ✅ Cohérent, et Filament rend la phase 4 nettement moins chère. |

---

## 7. Organisation du dépôt

Monorepo Composer (path repositories, un `composer.json` par paquet) :

```
packages/
  kernel/      # PUR. 0 dépendance runtime. ECS, systèmes, moteurs de match, PRNG.
  ruleset/     # schémas + rulesets versionnés (JSON) + validation
  worldgen/    # génération du monde initial (pays, clubs, joueurs, historique)
  host/        # boucle de tick, event store, snapshots, projections
  api/         # HTTP + SSE, auth, commandes/requêtes
  admin/       # IHM d'administration et d'exploration
  game-web/    # client de jeu (incarnation d'agent)
  harness/     # simulations massives sans tête + rapports d'équilibrage
docs/
```

Namespace racine `Flair\`, PSR-4, PSR-12.

**Règle de dépendance à faire respecter par outil (`deptrac` ou `phparkitect`) :**

```
kernel   → (rien)
ruleset  → (rien)
worldgen → kernel, ruleset
host     → kernel, ruleset, worldgen
api      → host
harness  → kernel, ruleset, worldgen
admin    → api
game-web → api (HTTP uniquement)
```

Si `kernel` importe quoi que ce soit d'autre, le build casse. C'est la garantie mécanique de l'architecture — la discipline seule ne tient pas sur 18 mois.

---

## 8. Application concrète de SOLID

Pas de la récitation : voici où chaque principe mord dans *ce* projet.

**SRP** — Un système = une préoccupation, une étape du tick. Un système qui entraîne les joueurs *et* met à jour les finances est un bug d'architecture : il devient impossible à réordonner et à tester.

**OCP** — Ajouter une mécanique = ajouter un composant + un système + une ligne dans le pipeline. **Zéro modification des systèmes existants.** Et les règles paramétriques (formats de compétition, fenêtres de transfert, barèmes) vivent dans le `Ruleset` en données : on étend le monde sans recompiler.

**LSP** — Les implémentations de `MatchEngine` doivent être interchangeables, y compris **statistiquement** : le contrat de substitution est un test de calibration (les distributions de scores de L0 et L1 doivent coïncider). Idem pour `IntentSource` : humain et PNJ doivent être indiscernables du point de vue du noyau.

**ISP** — Un système déclare les composants dont il a besoin via une requête (`query(Contract, Wage)`), pas « le monde entier ». Les clients consomment des read models étroits, pas l'état global. Ça rend les impacts d'un changement lisibles.

**DIP** — Le noyau ne dépend de rien ; ce sont Host et API qui dépendent de lui. Le noyau ne définit même pas de ports : tout ce dont il a besoin (graine, intentions, ruleset) lui est **passé en données**. C'est plus fort qu'une interface, et ça élimine toute possibilité de fuite d'infrastructure.

---

## 9. Ce que cette architecture refuse explicitement

- ❌ Pas de logique de règles dans l'API ou les clients.
- ❌ Pas de mutation du monde hors du noyau.
- ❌ Pas de lecture directe du `WorldState` par un client.
- ❌ Pas de dépendance croisée entre mondes.
- ❌ Pas de `rand()`/`mt_rand()`/`random_int()`, `time()`/`new DateTime()`, `getenv()`, ni d'accès disque ou réseau dans le noyau.
- ❌ **Un système n'appelle jamais un autre système**, directement ou indirectement. Il émet un événement.
- ❌ **Un événement n'est jamais traité dans le tick qui l'a produit.** Il part en OutQueue.

Ces sept interdits sont testables automatiquement. Ils doivent l'être.
