# `flair/kernel`

Le noyau de simulation : une fonction pure et déterministe, sans I/O, qui fait avancer un `WorldState` d'un tick (`docs/11-architecture-generale.md` §1). Zéro dépendance runtime — aucun framework, aucun ORM, aucun accès disque ou réseau.

Ce fichier documente **l'implémentation telle qu'elle existe** : les classes, leur rôle, comment elles s'articulent. `docs/10-16` à la racine du repo restent la référence de conception ("ce qu'on veut construire" — modèle du monde, algorithmes, roadmap) ; en cas de divergence entre ce README et le code, **le code a raison** — ce fichier décrit un instantané, pas une spec.

## Arborescence

```
src/Core/
  Ecs/          entités, composants, l'état du monde
  Messaging/    les trois messages (Fait/DecisionRequest/Intent), les deux files inter-ticks
  Pipeline/     l'exécution d'un tick sur une liste de systèmes
  Support/      PRNG déterministe, arithmétique 32 bits, calendrier simulé
  Ruleset/      règles paramétriques versionnées, imbriquées par sous-domaine
  Simulation/   step() — assemble tout ce qui précède
src/Football/   le domaine football, au-dessus du kernel générique
```

### `Ecs/`

- **`EntityIdAllocator`** — compteur monotone, jamais de réutilisation d'id, `0` réservé comme sentinelle "aucune entité".
- **`ComponentStore<T>`** — une colonne par type de composant : `set()`/`get()`/`remove()`, et `entities()` qui renvoie **toujours** les ids triés, jamais l'ordre d'insertion (`docs/12-` §2 — "la source n°1 de non-reproductibilité silencieuse").
- **`WorldState`** — agrège l'allocateur, un `ComponentStore` par type de composant, les singletons (adressés par type via `singleton()`/`setSingleton()`), **et** le `Scheduler`/`OutQueue` du monde. Ces deux derniers ont rejoint `WorldState` précisément parce que `step()` ne prend que `WorldState` + `TickContext` — rien d'autre ne pourrait les faire survivre d'un appel à l'autre (voir `docs/13-` §5, note sur les snapshots).

### `Messaging/`

- **`DomainEvent`/`DecisionRequest`/`Intent`** — trois interfaces marqueurs vides. Jamais confondues : un Fait est passé et journalisé, une `DecisionRequest` est transitoire et jamais journalisée, un `Intent` est un futur immédiat consommé une fois. `TaxonomyTest` encode cette non-confusion en assertion exécutable.
- **`Scheduler`/`ScheduledEntry`** — file d'événements datés. `schedule($event, $atTick, $systemIndex, $entityId, $seq)`, `drainDueBy($tick)` retire et renvoie tout ce qui est échu, trié `(tick, systemIndex, entityId, seq)` — jamais l'ordre d'un tas binaire.
- **`OutQueue`/`OutQueueEntry`** — canal par défaut entre deux ticks. `emit()` écrit, `drain()` vide et renvoie trié `(systemIndex, entityId, seq)` — c'est ce retour qui devient l'`$incoming` du tick suivant. `pending()` fait la même lecture triée **sans vider** la file : sert à capturer ce qui vient d'être émis pendant le tick courant (voir `Simulation::step()` plus bas).

### `Pipeline/`

- **`System`** (interface) — `id()`, `reads()`/`writes()`/`removes()`/`creates()`, `subscribesTo()` (types d'événements écoutés), `handle(DomainEvent, SystemContext)`, `update(SystemContext)`. Les quatre verbes d'accès aux composants sont distincts parce qu'ils portent des contraintes différentes :

  | Verbe | Opération | Contrainte |
  |---|---|---|
  | `reads()` | `get()`/`entities()` | ne doit pas lire un composant écrit ou retiré **plus loin** dans le pipeline |
  | `writes()` | `set()` sur une entité existante | **un seul writer** par composant |
  | `removes()` | `remove()` (retrait d'archétype) | **un seul remover** par composant |
  | `creates()` | `set()` sur une entité créée par ce système dans ce tick | **un seul créateur** par composant |

  Séparer `creates()` de `writes()` répond au même besoin qui avait déjà séparé `removes()` : un writer de valeur et un créateur d'entité peuvent coexister sur un même composant sans se marcher dessus, puisqu'ils ne touchent jamais la même entité. `YouthIntakeSystem` crée les composants de compétences dont `PlayerDevelopmentSystem` est seul writer — les deux cohabitent légitimement. `creates()` est aussi le seul des quatre exclu du contrôle de dépendance inversée : un créateur ne peut pas invalider une lecture déjà faite, puisque l'entité n'existait pas quand le lecteur a itéré.
- **`SystemContext`** — façade unique par laquelle un système accède à tout : `components()`/`createEntity()`/`singleton()`/`setSingleton()` délèguent au `WorldState` ; `schedule()`/`emit()` délèguent au `Scheduler`/`OutQueue` en fournissant `systemIndex`/`seq` ; `rng(entityId)` délègue à `Rng::forStream` ; `ruleset()`/`intents()` exposent ce que le `TickContext` a fourni.
- **`SeqCounter`** — compteur monotone d'émission, une instance par tick, partagée par tous les `SystemContext` de ce tick (garantit l'ordre total même quand deux systèmes émettent avec le même `systemIndex`).
- **`Pipeline`** — construit avec une `list<System>` **déclarée et ordonnée** (l'ordre est une donnée d'architecture versionnée avec le noyau, pas un détail). `tick(WorldState, tick, worldSeed, Ruleset, intents)` calcule l'`$incoming` une seule fois (`Scheduler::drainDueBy()` + `OutQueue::drain()`, concaténés — deux lots distincts, pas de tri unifié entre les deux), puis pour chaque système dans l'ordre déclaré : traite les événements de `$incoming` qui matchent `subscribesTo()` via `handle()`, puis appelle `update()`.

### `Support/`

- **`Math32::mul32(a, b)`** — multiplication 32×32→32 bits, par blocs de 16 bits. Seul point de vérité pour un piège précis : PHP fait basculer silencieusement un dépassement d'`int` en `float`, sans erreur — une multiplication naïve `(a * b) & 0xFFFFFFFF` peut déjà avoir perdu en float avant que le masque ne s'applique.
- **`Hash::mix32(worldSeed, tick, systemIdHash, entityId)`** — dérive une graine 32 bits à partir des 4 clés d'un flux. XOR-fold séquentiel + avalanche murmur3 entre chaque étape.
- **`Rng`** — PRNG xoshiro128\*\*, 32 bits, masqué partout. `Rng::forStream($worldSeed, $tick, $systemId, $entityId)` est le constructeur nommé à utiliser en pratique (voir Déterminisme ci-dessous) ; le constructeur nu `new Rng($seed)` reste utilisable directement (c'est ce que fait `forStream` en interne).
- **`SimDate`** — le seul temps connu du noyau (`docs/13-` §1 : "1 tick = 1 jour simulé"). Wrapper minimal autour d'un compteur de jours (`epochDay`), `yearsSince()` pour les écarts. `$ctx->tick` est directement utilisable comme `epochDay` : pas besoin d'un `WorldClock`/epoch réel tant que seule la différence entre deux dates compte.

### `Ruleset/`

Structure imbriquée par sous-domaine (`docs/12-` §6 : `competitions`, `transferWindows`, `contracts`, `finance`, `balance`), pas une liste plate de scalaires — pensée pour rester lisible au fur et à mesure que le nombre de champs grandit.

- **`Ruleset`** — la racine : `version`, et une propriété nommée par groupe (`balance` aujourd'hui). Chaque futur groupe suit le même pattern : une classe dédiée dans ce même namespace, une propriété avec une valeur par défaut sur `Ruleset` — jamais un sac générique/associatif.
- **`Balance`** — les leviers d'équilibrage. Deux multiplicateurs globaux (`developmentRate`, `trainingRate`), et une classe dédiée par système pour les leviers plus fins : `RetirementBalance`, `PlayerDevelopmentBalance`, `YouthIntakeBalance`. Une classe par système plutôt qu'un sac partagé, pour qu'un système ne dépende jamais des leviers d'un autre — même principe que `reads()`/`writes()`. `injuryBaseHazard`/`marketInflationTarget` (`12-` §6) rejoindront cette classe quand un système les lira réellement.

Famille à part entière plutôt qu'un fichier isolé à la racine de `Core/` : ni `Pipeline` ni `Simulation` ne doivent dépendre l'un de l'autre pour ce type, et les deux en ont besoin — même raisonnement qu'avant, la famille grandit simplement au même titre que `Pipeline`/`Simulation`.

### `Simulation/`

- **`TickContext`** — readonly : `tick`, `seed`, `intents` (`list<Intent>`), `ruleset`. Les entrées d'un tick, façon `11-` §1.
- **`StepResult`** — readonly : `state` (le `WorldState`, muté en place), `events` (`list<DomainEvent>`, ce qui a été émis *pendant* ce tick).
- **`Simulation::step(WorldState $state, TickContext $ctx): StepResult`** — la fonction pure documentée. Implémentation complète :

  ```php
  public function step(WorldState $state, TickContext $ctx): StepResult
  {
      $this->pipeline->tick($state, $ctx->tick, $ctx->seed, $ctx->ruleset, $ctx->intents);

      return new StepResult($state, $state->outQueue()->pending());
  }
  ```

## Le tick, de bout en bout

```mermaid
sequenceDiagram
    participant Host
    participant Simulation
    participant Pipeline
    participant SystemA as Système A
    participant SystemB as Système B

    Host->>Simulation: step(state, ctx)
    Simulation->>Pipeline: tick(state, tick, seed, ruleset, intents)
    Pipeline->>Pipeline: incoming = scheduler.drainDueBy(tick) + outQueue.drain()
    Pipeline->>SystemA: handle(event) pour chaque event matché, puis update(ctx)
    SystemA-->>Pipeline: ctx.emit()/ctx.schedule() (OutQueue/Scheduler du WorldState)
    Pipeline->>SystemB: handle(event), update(ctx)
    Simulation->>Simulation: events = state.outQueue().pending()
    Simulation-->>Host: StepResult(state, events)
```

Points structurants :

- **`$incoming` est figé avant qu'aucun système ne s'exécute.** Ce qu'un système émet pendant le tick part dans `OutQueue`/`Scheduler`, jamais dans `$incoming` — une boucle infinie intra-tick est donc **structurellement** impossible, pas juste évitée par convention (`docs/16-` §3).
- **Un événement émis ce tick est traité au tick suivant, jamais celui-ci.** `PipelineTest::testAnEventEmittedDuringThisTickIsNeverHandledInTheSameTick` verrouille ce comportement.
- **`StepResult.events` ne contient que les `emit()` de ce tick**, pas les événements déjà traités (`$incoming`), et rien du `Scheduler` : un événement seulement planifié n'est pas encore un Fait tant qu'il n'a pas été déclenché.
- **L'ordre de souscription = l'ordre du pipeline.** Si deux systèmes écoutent le même type d'événement, ils le traitent dans l'ordre déclaré à la construction de `Pipeline`, jamais dans un ordre d'enregistrement de handlers.

## Déterminisme en pratique

Un système qui a besoin d'aléatoire ne crée **jamais** de PRNG lui-même : il appelle `$ctx->rng($entityId)`.

```php
$rng = $ctx->rng(entityId: $playerId);
$roll = $rng->nextUint32();
```

Ça délègue à `Rng::forStream($worldSeed, $tick, $systemId, $entityId)`, qui dérive la graine via `Hash::mix32(...)`. Conséquence directe : deux systèmes différents, ou la même entité à deux ticks différents, tirent dans des flux totalement isolés — jamais un PRNG global partagé. Ajouter un système ou en réordonner un autre ne décale le tirage d'aucun flux existant.

Trois vecteurs de régression figent ce comportement : `RngTest::testRegressionVectorForSeed42` (cross-vérifié contre une implémentation Python indépendante), `HashTest::testRegressionVector` et `RngTest::testForStreamRegressionVector` (internes, pas cross-vérifiés — `Hash::mix32` n'est pas un algorithme publié).

## Écrire un `System` — exemple minimal

```php
final readonly class Fatigue
{
    public function __construct(public int $value) {}
}

final class FatigueRecovered implements DomainEvent {}

final class FatigueRecoverySystem implements System
{
    public function id(): string { return 'fatigue-recovery'; }
    public function reads(): array { return [Fatigue::class]; }
    public function writes(): array { return [Fatigue::class]; }
    public function removes(): array { return []; }
    public function creates(): array { return []; }
    public function subscribesTo(): array { return []; } // purement periodique

    public function handle(DomainEvent $event, SystemContext $ctx): void {}

    public function update(SystemContext $ctx): void
    {
        foreach ($ctx->components(Fatigue::class)->entities() as $entityId) {
            $fatigue = $ctx->components(Fatigue::class)->get($entityId);
            $recovery = $ctx->rng($entityId)->nextUint32() % 3; // 0-2 points recuperes

            $next = max(0, $fatigue->value - $recovery);
            $ctx->components(Fatigue::class)->set($entityId, new Fatigue($next));

            if ($next === 0 && $fatigue->value > 0) {
                $ctx->emit(new FatigueRecovered(), entityId: $entityId);
            }
        }
    }
}
```

Points à retenir de cet exemple :
- Les composants sont **readonly** : on ne modifie jamais en place, on écrit un nouveau composant via `set()`.
- L'itération se fait via `entities()`, toujours triée par id — jamais un `foreach` sur un tableau associatif construit ailleurs.
- Un fait n'est émis que s'il franchit un seuil comportemental (`docs/16-` §2) — ici, "la fatigue vient de tomber à zéro", pas "la fatigue a changé".

## `Ruleset` et `TickContext.intents`

`Ruleset` a son premier vrai consommateur : `Football\PlayerDevelopmentSystem` lit `ruleset()->balance->developmentRate` pour moduler la vitesse de progression naturelle, `Football\TrainingSystem` lit `ruleset()->balance->trainingRate` pour calibrer la qualité d'entraînement (voir « Le domaine football » ci-dessous). `TickContext.intents` reste de la plomberie sans consommateur — rien dans le domaine football n'a encore besoin de traiter une intention humaine/PNJ.

## Le domaine football (`src/Football/`)

Premier code hors du kernel générique : le vieillissement, le plus autonome des cinq systèmes de la Phase 0 (`docs/15-` §4 — pas besoin de calendrier ni de moteur de match), scindé en deux systèmes à responsabilité unique plutôt qu'un seul `AgingSystem` — la retraite (retrait d'archétype, irréversible) et la progression des compétences (mutation de valeur) n'ont pas la même forme, et ne doivent jamais avoir deux writers sur les mêmes composants (voir « Un système déclare `reads()`/`writes()`/`removes()` » ci-dessous).

- **`Person`** — identité minimale (`name`, `birthDate: SimDate`). Persiste à travers les changements de rôle (`docs/12-` §1).
- **`PlayerPhysicalSkills`/`PlayerTechnicalSkills`/`PlayerMentalSkills`** — les attributs de champ de `docs/12-` §5, regroupés par comportement de vieillissement plutôt que par domaine métier, readonly.
- **`PlayerPotentials`** — `ceiling`/`*PeakAge` (un par catégorie)/`growthRate`/`fragility` (`docs/14-` §2) : une trajectoire souple, pas un plafond dur.
- **`TrainingEffect`** — la qualité d'environnement d'entraînement d'un joueur, `h(entraînement)` uniquement (`docs/14-` §2 : `modif = clamp(h × i(temps de jeu) × j(moral), 0.5, 2.0)`, pas le produit complet) : un multiplicateur `[0.5, 2.0]` déjà borné par son producteur (`TrainingSystem`), lu par `PlayerDevelopmentSystem` avec un défaut neutre (1.0) quand absent (joueur sans club). `i`/`j` seront, le jour où `MatchSystem`/`Morale` existeront, des composants-facteurs **séparés** — jamais fusionnés ici (un seul writer par composant).
- **`Club`** — identité minimale (`name`) sur une entité club, réduite face au catalogue complet de `docs/12-` §3 (pas de `Finances`/`Squad`/`Reputation`/`FanBase`/`BoardExpectations`, pas de worldgen — clubs créés à la main).
- **`Facilities`** — qualité des installations d'un club, sur l'entité club, exprimée directement sur l'échelle `[0.5, 2.0]` du multiplicateur final.
- **`SquadMembership`** — affectation d'un joueur à un club (`clubId`), sur l'entité **joueur**. Pas de composant `Squad` réciproque côté club dans ce lot.
- **`PlayerRetired`** / **`YouthPlayerPromoted`** — les deux Faits du cycle de vie d'un joueur, symétriques : la sortie de la population (irréversible, `docs/16-` §2) et l'entrée dedans (racontable). La dérive quotidienne des attributs, elle, ne franchit aucun seuil décisionnel et n'émet rien.
- **`Generation/PlayerFactory`** + **`Generation/PlayerBlueprint`** — la loi de talent du monde, en un seul endroit. `drawPotentials()` tire un `PlayerPotentials`, `drawRookie()` un `PlayerBlueprint` complet (les 5 composants d'un joueur neuf). **Pure** : aucun accès au monde, l'appelant écrit lui-même — c'est ce qui permet à `YouthIntakeSystem` (via `SystemContext`) et au harness (via `WorldState`) de partager la factory alors qu'ils n'ont aucun type d'accès commun. Ce partage n'est pas cosmétique : si la population initiale et les promotions annuelles suivaient deux lois de talent différentes, le monde convergerait mécaniquement vers celle de l'intake et la pyramide des âges ne pourrait pas être stationnaire.

  Le `ceiling` suit une **Beta(1, k)** (`min` de `k` uniformes) : beaucoup de joueurs ordinaires, une longue queue de rares talents. `docs/12-` §7 demande une log-normale ; on lui substitue cette loi parce qu'une vraie log-normale exige `exp`/`log`/`sqrt`/`cos` (Box-Muller), qui viennent de la libm et peuvent différer d'un ulp d'une plateforme à l'autre — contrairement à `+`/`-`/`*`/`/`, exacts au bit près en IEEE 754. **Le noyau n'utilise à ce jour aucune fonction transcendante**, et c'est une propriété du déterminisme à ne pas casser par inadvertance. Si la Phase 1 montre que la forme exacte de la queue compte, la remplacer par une table de quantiles interpolée linéairement — pas par Box-Muller.
- **`YouthIntakeSystem`** — purement périodique. **Ferme la boucle démographique** : sans lui, `RetirementSystem` ne fait que vider la population, et le premier critère de sortie de la Phase 0 ("pyramide des âges stationnaire sur 20 saisons", `docs/15-` §4) est structurellement inatteignable. Un jour précis de l'année simulée (`tick % 365 === intakeDayOfYear`), chaque club promeut ~1 à 3 joueurs neufs de 17 ans dans son effectif, en nombre modulé par `Facilities` et arrondi **stochastiquement** (l'espérance reste exacte malgré des cohortes entières — un `round()` sec écraserait la calibration). Seul système qui `creates()` des joueurs à l'exécution.

  Trois choix de conception qui méritent d'être compris avant d'y toucher :
  - **Par club, pas par monde.** Le flux RNG est dérivé de `(worldSeed, tick, systemId, entityId)` — or le système crée des entités qui n'ont pas encore d'identifiant. La clé ne peut donc pas être le joueur produit : c'est le club producteur. Ce qui tombe juste côté domaine, et permet la modulation par `Facilities`.
  - **Aucun régulateur de population.** L'alternative — un intake asservi à une cible — *garantirait* la stationnarité par construction : on mesurerait son propre thermostat, et le critère de sortie de la Phase 0 serait vide de sens. La stationnarité doit rester une propriété **émergente**. (`bin/demo.php` la montre : 4 joueurs au départ, palier à ~48 dès la 30ᵉ année, cohérent avec ~2,9 promus/an × ~16 ans de carrière.)
  - **"Entre dans la population professionnelle", pas "entre au centre de formation".** Un vrai centre accueille 8-12 jeunes par promotion ; les modéliser ferait ~180 entrées/an contre ~28 sorties, soit un monde ×5 en quelques saisons, parce que le vrai football compense par une libération massive qu'on ne modélise pas (il faudrait un `PlayerReleased` et un système d'échec). On modélise donc directement les 1-2 qui percent.
- **`TrainingSystem`** — purement périodique, aucun RNG (fonction déterministe, pas de tirage). Seul writer de `TrainingEffect`, ne lit jamais `PlayerPotentials`/les compétences — aveugle à ce qui consomme son résultat, symétrique de `PlayerDevelopmentSystem` qui est aveugle à sa provenance (cause vs mécanisme). Pour chaque joueur affecté à un club (`SquadMembership`) : `clamp(ruleset()->balance->trainingRate × Facilities::$quality, 0.5, 2.0)` écrit dans `TrainingEffect`. Un joueur sans club ne reçoit aucune écriture — le défaut neutre de `PlayerDevelopmentSystem` s'applique tel quel.
- **`RetirementSystem`** — purement périodique, seul système qui `remove()` `PlayerPotentials`+les trois composants de compétences. Chaque tick, pour chaque entité `Person`+`PlayerPotentials` : au-delà d'un âge d'éligibilité, une probabilité de retraite croissante (âge, `fragility`) est tirée ; si elle tombe, les composants sont retirés et `PlayerRetired` est émis.
- **`PlayerDevelopmentSystem`** — purement périodique, seul système qui `set()` les trois composants de compétences. Chaque attribut progresse ou décline via un taux annuel (`growthRate × écart au plafond × g(âge)`, `balance->developmentRate` du `Ruleset` en multiplicateur, modulé par `TrainingEffect->quality`) converti en probabilité **quotidienne** d'un pas de ±1 — nécessaire pour éviter qu'un taux journalier fractionnaire ne s'arrondisse toujours à zéro, et ça donne une progression par à-coups plutôt qu'une interpolation lisse. Chaque catégorie a son propre âge de pic et sa propre pente de déclin post-pic (`Ruleset\PlayerDevelopmentBalance`).

### Calendrier, match L0 et classement

Dernière brique de la Phase 0 (`docs/15-` §4) : calendrier, match, classement — livrée comme une seule tranche (un calendrier seul n'a pas de consommateur).

- **`Competition`** — identité minimale (`name`) sur une entité compétition, porte `Standings`. **Une seule compétition en Phase 0** : aucun `CompetitionMembership` côté club n'existe, donc `CalendarSystem` associe tous les `Club::entities()` du monde à chaque `Competition` trouvée — juste tant qu'il n'en existe qu'une.
- **`Fixture`** — un match programmé (`competitionId`/`homeClubId`/`awayClubId`/`matchday`), créé une fois par `CalendarSystem` (`creates()`), jamais modifié ensuite.
- **`MatchResult`** — le score, sur la même entité que `Fixture` mais un composant distinct (seul writer `MatchSystem`) : un système qui ne veut qu'ajouter un score n'a pas besoin de connaître la forme complète de `Fixture`.
- **`Standings`/`StandingsEntry`** — le classement, sur l'entité compétition, seul writer `CompetitionSystem`. `entries` (`array<clubId, StandingsEntry>`) est peuplé **paresseusement** : un club n'y apparaît qu'après son premier match, pour éviter à `CompetitionSystem` de devoir lire `Club::class`.
- **`FixtureKickoff`** (planifié via `Scheduler`), **`SeasonStarted`**, **`MatchPlayed`** (Fait) — les trois événements du lot, tous self-suffisants (mêmes précédent que `YouthPlayerPromoted` : un événement se comprend seul, sans recroiser un composant).
- **`Generation/PoissonMatchEngine`** + **`MatchScore`** — le moteur L0 (`docs/14-` §1, Dixon-Coles) : pure comme `PlayerFactory`, `(Rng, ratings, MatchBalance) → MatchScore`. `λ_home = exp((attackHome − defenseAway) / strengthScale + homeAdvantage)`, `λ_away` symétrique sans l'avantage du terrain ; pmf de Poisson par récurrence (`p(0) = exp(-λ)`, `p(k) = p(k-1) × λ / k`, un seul `exp()` par λ) ; correction de Dixon-Coles `τ(x, y, λ, μ, ρ)` sur les scores 0-0/1-0/0-1/1-1 ; tirage par **grille normalisée + inversion de la fonction de répartition cumulée** (un seul `nextUint32()`), plutôt que par rejet — borné, déterministe, pas de boucle non bornée.

  **`exp()` est la première fonction transcendante du noyau — décision consciente, pas une entorse au paragraphe `PlayerFactory` ci-dessus.** `PlayerFactory` évite `exp`/`log`/`sqrt`/`cos` pour la loi de talent à cause d'un risque de **portabilité cross-plateforme/cross-libc** (un `ulp` de différence changerait la forme de la queue de distribution). Mais `docs/13-` §4.8 précise que le noyau n'a besoin que d'une reproductibilité **même machine, même version de PHP** — pas cross-plateforme, puisque seul le serveur exécute le noyau (pas de lockstep multijoueur). Sur une même machine, `exp()` de la libm rend toujours le même résultat pour les mêmes entrées : le déterminisme n'est donc pas menacé ici, et le monde tourne aujourd'hui sur une seule machine. À revisiter si la Phase 1 introduit une comparaison de hash entre machines différentes (harness sur CI, monde en prod sur un autre hôte).
- **`CalendarSystem`** — périodique, `creates() = [Fixture::class]`. À `tick % 365 === seasonStartDayOfYear`, génère pour chaque `Competition` un calendrier aller-retour complet par la **méthode du cercle** (déterministe, aucun RNG), crée une `Fixture` par match et programme un `FixtureKickoff` via `ctx->schedule()`. Invariant testable indépendamment du détail d'alternance de la méthode : chaque club joue exactement `N-1` fois à domicile et `N-1` fois à l'extérieur sur la saison (la manche retour est la manche aller, domicile/extérieur inversés).
- **`MatchSystem`** — réactif sur `FixtureKickoff`, `writes() = [MatchResult::class]`. Calcule un rating attaque/défense par club à partir de l'effectif (`SquadMembership` + skills) — pas de `Position` ni de sélection d'onze (`docs/15-` déjà arrêté) : `attackRating` moyenne `finishing`/`passing`/`technique`/`pace`, `defenseRating` moyenne `defending`/`positioning`/`strength`/`reflexes` sur tout l'effectif. Club sans joueur → rating neutre (50.0). Écrit `MatchResult` **et** émet le Fait `MatchPlayed`.
- **`CompetitionSystem`** — réactif sur `FixtureKickoff` et `SeasonStarted`, `writes() = [Standings::class]`. Sur `SeasonStarted` (émis par `CalendarSystem` à la génération de la saison), remet `Standings` à vide. Sur `FixtureKickoff` : lit `MatchResult`.

  **Le point d'architecture à retenir : `MatchSystem` et `CompetitionSystem` réagissent au même `FixtureKickoff`.** `Pipeline::tick()` calcule `$incoming` une seule fois puis le rejoue pour chaque système dans l'ordre déclaré (voir "Le tick, de bout en bout" plus haut) — `MatchSystem`, déclaré avant, a donc déjà écrit `MatchResult` quand `CompetitionSystem` traite le même événement. C'est exactement l'exemple documenté par `docs/13-` §2 : *"un match joué doit alimenter le classement du jour"* — canal 1 (composant lu le jour même), pas canal 2 (`MatchPlayed`, qui n'arriverait qu'au tick suivant et serait trop tard pour le classement du jour).

```mermaid
sequenceDiagram
    participant CalendarSystem
    participant Scheduler
    participant MatchSystem
    participant CompetitionSystem

    CalendarSystem->>Scheduler: schedule(FixtureKickoff, atTick: jour du match)
    Note over Scheduler: ... tick suivants ...
    Scheduler->>MatchSystem: FixtureKickoff (incoming du tick)
    MatchSystem->>MatchSystem: écrit MatchResult (composant)
    MatchSystem-->>Scheduler: emit(MatchPlayed) → OutQueue (tick+1)
    Scheduler->>CompetitionSystem: FixtureKickoff (même incoming, même tick)
    CompetitionSystem->>CompetitionSystem: lit MatchResult, écrit Standings
```

Pipeline déclaré (`bin/demo.php`, `Football\PipelineInvariantsTest`) : `YouthIntakeSystem`, `TrainingSystem`, `RetirementSystem`, `PlayerDevelopmentSystem`, `CalendarSystem`, `MatchSystem`, `CompetitionSystem` — les facteurs avant les effets (`TrainingSystem` avant `PlayerDevelopmentSystem`), `YouthIntakeSystem` en tête (canal 1 pour les joueurs promus), puis le trio calendrier/match/classement en fin de pipeline (`MatchSystem` avant `CompetitionSystem`, obligatoire pour le canal 1 ci-dessus).

Simplifications assumées (voir les docblocks des classes pour le détail) : `ceiling`/`growthRate`/`fragility` partagés entre les trois catégories plutôt qu'un plafond par attribut, pas de "queue épaisse" sur le bruit, pas de potentiel révélé progressivement (dépend du comptage de matchs, donc du moteur de match), domaine `Club` réduit à l'identité + une qualité scalaire, une seule compétition (pas de `CompetitionMembership`), force de club = agrégat de tout l'effectif (pas de `Position`). Premier jet à calibrer via le harness d'équilibrage (Phase 1), pas des valeurs équilibrées.

- **`Core/Support/SimDate`** — le seul temps connu du noyau (`docs/13-` §1, "1 tick = 1 jour simulé") : un compteur de jours, `yearsSince()` pour les écarts. Générique, pas spécifique football — réutilisable par tout futur composant daté (`Contract.expiresOn`...).

## Dépendances et invariants

Graphe réel des `use` entre familles (aucun cycle) :

```
Ecs         → Messaging
Pipeline    → Ecs, Messaging, Support, Ruleset (racine)
Simulation  → Ecs, Pipeline
Messaging, Support, Ruleset → (rien)
```

Parmi les sept interdits structurants de `docs/11-` §9, ce que le code garantit aujourd'hui :

| Interdit | Statut |
|---|---|
| Un système n'appelle jamais un autre système | **Garanti par construction** : `SystemContext` n'expose aucune référence vers un `System` ou vers `Pipeline` — un système ne peut physiquement pas en atteindre un autre. |
| Un événement n'est jamais traité dans le tick qui l'a produit | **Garanti par construction** : `$incoming` est calculé avant la boucle sur les systèmes, `emit()`/`schedule()` n'écrivent que dans `OutQueue`/`Scheduler`. |
| Itération toujours triée par `EntityId` | **Garanti par construction** pour `ComponentStore`/`Scheduler`/`OutQueue` (tri à la lecture, jamais d'ordre de `Map`). |
| `rand()`/`mt_rand()`/`time()`/accès disque ou réseau interdits dans le noyau | **Conventionnel** : rien ne l'empêche mécaniquement pour l'instant (pas de règle PHPStan/CI dédiée). |
| Un système déclare `reads()`/`writes()`/`removes()`/`creates()`, pas "le monde entier" | **Vérifié mécaniquement pour le domaine football** : `Football\PipelineInvariantsTest` détecte deux systèmes qui écrivent/retirent/créent le même composant, et toute lecture d'un composant écrit/retiré plus loin dans le pipeline déclaré. Toujours pas croisé avec les composants *réellement* lus/écrits dans le corps des méthodes (les déclarations peuvent mentir), et la liste vérifiée est maintenue à la main tant qu'aucun registre canonique `Pipeline::SYSTEMS` n'existe. |
| Dépendance de package `kernel → (rien)` | **Vrai aujourd'hui** (`composer.json` du kernel n'a aucune dépendance runtime), mais pas vérifié par `deptrac`/`phparkitect` — outil pas encore en place. |

## Commandes de dev

```bash
cd packages/kernel
vendor/bin/phpunit          # tests
vendor/bin/phpstan analyse  # niveau max (src, tests, bin)
php bin/demo.php            # observer un tick reel : quelques joueurs, plusieurs annees simulees
```

`bin/demo.php` n'est pas le harness d'équilibrage (`packages/harness/`, Phase 1) : juste un point d'entrée rapide pour voir un système tourner sans repasser par la suite de tests. Gagnera des systèmes au même rythme qu'ils seront écrits.

Conventions de test observées dans le code existant, à reproduire :
- **Collaborateurs réels, jamais de mocks** — un test construit un vrai `WorldState`/`Scheduler`/`OutQueue` et vérifie le comportement observable, pas des appels attendus sur un double.
- **Vecteurs de régression figés** pour tout ce qui touche au déterminisme (`Rng`, `Hash`) — toute modification volontaire ou non de l'algorithme se voit immédiatement.
- **Data providers plutôt que des valeurs concrètes en dur** quand PHPStan risque de prouver statiquement qu'une assertion est toujours vraie sur un type déjà connu (voir `TaxonomyTest` : les messages transitent par un provider typé `object`/`class-string` pour que le test reste une vraie vérification runtime, pas un `alreadyNarrowedType`).

## État actuel et prochaine étape

Voir la section « Où en est le projet » de `CLAUDE.md` à la racine du repo pour l'avancement à jour — pas dupliqué ici pour ne pas avoir deux sources de vérité.
