# Flair — simulation de monde persistant football

## Pitch

Un monde persistant de football qui vit avec ou sans joueur humain (façon Football Manager × Dwarf Fortress × Hattrick), simulé par un **noyau pur, déterministe**. Le client de jeu incarne un **agent de joueurs** (scouter, placer, négocier, gérer une carrière) — c'est la seule boucle de jeu humaine, et elle n'est pas encore validée.

Ce fichier est un **index et un aide-mémoire des règles chères à violer par accident**. Il ne remplace pas `docs/` : en cas de doute sur une formule, un exemple de code ou un détail, lire le document source plutôt que deviner.

## Index de la documentation (`docs/`)

| Doc | Contenu |
|---|---|
| `10-etat-de-l-art.md` | Références (FM, Hattrick, CK3, Dwarf Fortress, EVE), modèles académiques, ce qu'on emprunte à qui |
| `11-architecture-generale.md` | Noyau pur, hexagonale, intentions, CQRS, multi-monde, stack PHP, SOLID appliqué |
| `12-modele-du-monde.md` | ECS, entités/composants, singletons, vérité cachée vs perception, `Ruleset`, worldgen, invariants |
| `13-moteur-de-simulation.md` | Tick hybride, pipeline de systèmes, Scheduler, déterminisme, event sourcing, dimensionnement, boucle du Host |
| `14-algorithmes.md` | Moteurs de match (LOD), développement des joueurs, composition de facteurs, marché, économie, équilibre compétitif, narration |
| `15-roadmap.md` | Phases, critères de sortie, décisions à verrouiller avant de coder |
| `16-evenements-et-cascades.md` | Taxonomie Fait/DecisionRequest/Intent, seuils d'émission, contrôle des cascades, Event Monitor |
| `archive/ressource.md`, `archive/ressource2.md` | Sources internes non normatives (brouillon d'origine, déjà digéré par `10-`–`16-`) — ne pas les traiter comme référence de conception |

## Règles non négociables

- **Le noyau est une fonction pure, déterministe, zéro I/O** : `step(WorldState, TickContext): StepResult`. Aucun `rand()`/`mt_rand()`/`random_int()`, `time()`/`new DateTime()` sans argument, `getenv()`, accès disque ou réseau dans `kernel`. (`11-` §1, §9)
- **ECS strict, aucun sous-type.** `Player`, `Club`, `City` n'existent **pas** comme classes. Une entité est un entier ; « joueur » est le nom informel d'une entité qui porte des composants de compétences + `Contract` + `Fitness`. Les composants sont `readonly`, en lecture seule hors de leur système propriétaire. (`12-` §1, §2)
- **Vérité cachée vs perception.** `PlayerPhysicalSkills`/`PlayerTechnicalSkills`/`PlayerMentalSkills`/`PlayerPotentials` (la vérité) ne sont jamais exposés à un client. Ce qu'un observateur croit est **dérivé** à la lecture (bruit déterministe, fonction de `observerId`/`subjectId`/`observationCount`), jamais stocké. (`12-` §4)
- **Trois messages, jamais confondus** : Fait (passé, journalisé) / DecisionRequest (question transitoire, jamais journalisée) / Intent (futur immédiat, humain et PNJ via la même interface `IntentSource`). (`11-` §3, `16-` §1)
- **Un événement n'est jamais traité dans le tick qui l'a produit.** Il part en OutQueue vers le tick suivant. C'est ce qui rend les cascades impossibles à boucler dans un même tick — structurel, pas une limite de profondeur. (`13-` §2, `16-` §3)
- **Un système n'appelle jamais un autre système**, directement ou indirectement. Il émet un événement ou écrit un composant lu plus loin dans le pipeline. (`13-` §2, `11-` §9)
- **Seuils d'émission** : une mutation de composant n'est pas un événement. Un fait mérite d'être émis s'il franchit un seuil comportemental, est irréversible, ou est racontable — sinon le système se tait. (`16-` §2)
- **Itération toujours triée par `EntityId` croissant.** Jamais l'ordre d'une `Map`/d'un `Set`. Idem pour l'OutQueue et le Scheduler : ordre total imposé, jamais l'ordre d'insertion. (`12-` §2, `13-` §4.2/4.5)
- **PRNG 32 bits obligatoire.** PHP bascule silencieusement un dépassement d'`int` en `float` : un PRNG 64 bits naïf casse le déterminisme sans erreur. PCG32/xoshiro128\*\*, masquage `& 0xFFFFFFFF` après chaque opération. Un flux dérivé par `(worldSeed, tick, systemId, entityId)` — jamais un PRNG global partagé. (`11-` §6, `13-` §4.1/4.3)
- **Les singletons sont adressés par type, une instance unique** (`MarketInflation`, `SeasonPhase`, `WorldClock`). Une donnée qui varie par pays/région (`EconomicClimate`, `Weather`) est un **composant** d'entité, pas un singleton. (`12-` §3 bis — corrigé le 2026-07-31, cf. note dans le doc)
- **Règles paramétriques dans le `Ruleset` versionné** (JSON, schéma validé), jamais codées en dur. Un monde est épinglé à `(kernelVersion, rulesetVersion)` ; changer les règles d'un monde vivant est une migration explicite, pas un hot reload. (`12-` §6, `13-` §6)
- **Graphe de dépendances du monorepo**, à faire respecter mécaniquement (`deptrac`/`phparkitect`) dès que le code existe :
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
  Si `kernel` importe quoi que ce soit d'autre, le build casse. (`11-` §7)

## Stack et conventions

- **PHP 8.3+** partout (kernel/host/api). Pas de Rust, pas de Node pour le noyau — le CPU n'est pas le facteur limitant (`13-` §7, `11-` §6).
- **PostgreSQL seul.** Pas de Redis/Kafka/Mongo. `jsonb` pour les événements, tables classiques pour les projections.
- **Aucun ORM dans `kernel`.** Doctrine autorisé uniquement côté projections/API.
- **Symfony ou Laravel** pour `host`/`api`/`admin` — **jamais** dans `kernel`, qui doit rester exécutable dans un test unitaire nu.
- **SSE**, pas WebSocket, pour le flux temps réel.
- Namespace racine `Flair\`, PSR-4, PSR-12. **PHPStan niveau max obligatoire sur `kernel`.**
- Monorepo Composer (path repositories) : `packages/{kernel,ruleset,worldgen,host,api,admin,game-web,harness}/`. Pas encore créé — voir « Où en est le projet ».

## Où en est le projet

**Stade actuel : Phase 0 en cours (`15-roadmap.md`).** `packages/kernel/` existe (`composer.json`, PHPUnit, PHPStan niveau max — tous verts). `src/Core/` (générique, zéro dépendance football) est maintenant rangé par famille :
  - `Core/Ecs/` — `EntityIdAllocator`, `ComponentStore<T>`, `WorldState` (agrège les deux précédents + les singletons, `12-` §2/§3 bis, **et désormais `Scheduler`/`OutQueue`** — voir plus bas pourquoi).
  - `Core/Messaging/` — taxonomie `DomainEvent`/`DecisionRequest`/`Intent` (marqueurs vides, `11-` §3, `16-` §1), `Scheduler`/`ScheduledEntry` (échéance arbitraire, tri `(tick, systemIndex, entityId, seq)`, `13-` §3/§4.7), `OutQueue`/`OutQueueEntry` (canal par défaut inter-ticks, une seule classe joue les deux rôles "OutQueue"/"InQueue" de la doc, tri `(systemIndex, entityId, seq)`, `13-` §2/§4.5 ; `pending()` lit sans vider — ce qui a été émis *pendant* le tick courant, pour `StepResult.events`).
  - `Core/Pipeline/` — `SeqCounter` (compteur d'émission partagé par tick), `System` (interface, `13-` §2 — `reads()`/`writes()` (mutation via `set()`) /`removes()` (retrait d'archétype via `remove()`), deux méthodes distinctes pour qu'un writer de valeur et un remover d'archétype puissent coexister sur un même composant sans violer l'invariant « un seul writer » ; vérifié mécaniquement côté football par `Football\PipelineInvariantsTest`, pas encore par un outil générique dans `Core`), `SystemContext` (façade unique d'accès pour un système : `components()`/`createEntity()`/`singleton()` délèguent à `WorldState`, `schedule()`/`emit()` délèguent à `Scheduler`/`OutQueue` en fournissant `systemIndex`/`seq`, `rng(entityId)` délègue à `Rng::forStream` en fournissant `worldSeed`/`systemId`, `ruleset()`/`intents()` exposent ce que le `TickContext` a fourni — aucun système ne les consomme encore), `Pipeline` (exécute un tick sur une liste de systèmes déclarée : Scheduler et OutQueue sont concaténés en deux lots déjà ordonnés — pas de fusion inventée entre les deux — puis chaque système traite ses `handle()` avant son `update()`, dans l'ordre déclaré ; `tick(WorldState, tick, worldSeed, Ruleset, intents)` tire désormais `Scheduler`/`OutQueue` de `$world`, ne les prend plus en paramètres séparés).
  - `Core/Support/` — `Math32` (multiplication 32×32→32 masquée, seul point de vérité pour le piège int→float, `13-` §4.3), `Hash` (`mix32(worldSeed, tick, systemIdHash, entityId)`, `13-` §4.1), `Rng` (PRNG 32 bits xoshiro128\*\*, plus le constructeur nommé `Rng::forStream(worldSeed, tick, systemId, entityId)` — l'implémentation du `rngFor()` documenté, un flux isolé par système/entité/tick, jamais un PRNG global partagé), `SimDate` (le seul temps connu du noyau, `13-` §1 — wrapper autour d'un compteur de jours, `$ctx->tick` utilisable directement comme `epochDay`, pas de `WorldClock`/epoch réel pour l'instant).
  - `Core/Ruleset/` — famille (comme `Pipeline`/`Simulation`), imbriquée par sous-domaine plutôt qu'une liste plate de scalaires (`12-` §6 : `competitions`, `transferWindows`, `contracts`, `finance`, `balance`). `Ruleset` (racine : `version` + une propriété par groupe), `Balance` (`developmentRate`, premier champ réellement lu — par `Football\PlayerDevelopmentSystem` via `ruleset()->balance->developmentRate` — et `aging: AgingBalance`) et `AgingBalance` (tous les leviers de calibration du vieillissement — âge d'éligibilité à la retraite et poids âge/fragilité dans la probabilité de retraite, lus par `RetirementSystem` ; forme de g(age) et un multiplicateur de déclin post-pic **par catégorie de compétence**, lus par `PlayerDevelopmentSystem` — jamais en dur dans le code des systèmes, chaque champ documenté individuellement) ; ni `Balance`/`AgingBalance` dans `Pipeline` ni dans `Simulation` pour ne pas créer de dépendance entre les deux, qui en ont besoin tous les deux.
  - `Core/Simulation/` — `TickContext`/`StepResult` (readonly, `11-` §1), `Simulation::step(WorldState, TickContext): StepResult` — la fonction pure documentée, assemble `Pipeline::tick()` et lit `$state->outQueue()->pending()` pour `StepResult.events`. Dépend de `Pipeline`/`Ecs`, jamais l'inverse.

**`src/Football/` existe désormais** (namespace `Flair\Kernel\Football`) : premier système concret, le vieillissement (`15-` §4, le plus autonome des cinq systèmes de la Phase 0). `Person` (identité minimale, `birthDate: SimDate`), les compétences d'un joueur réparties en trois composants orthogonaux plutôt qu'un seul (`12-` §5, groupés par **comportement de vieillissement**, pas par domaine métier) — `PlayerPhysicalSkills` (`pace`/`stamina`/`strength`/`reflexes`), `PlayerTechnicalSkills` (`technique`/`passing`/`finishing`/`defending`/`positioning`/`handling`/`distribution`), `PlayerMentalSkills` (`vision`/`composure`/`leadership`/`discipline`/`command`) — les attributs de gardien (`reflexes`/`handling`/`distribution`/`command`) sont **répartis dans ces trois catégories** plutôt qu'isolés dans un quatrième composant, et **tous portés par tout joueur, gardien ou non** : un joueur de champ appelé à garder les buts joue avec ces attributs (généralement bas) au même titre que les autres ; pas d'archétype exclusif comme la retraite. `PlayerPotentials` (`ceiling`/`physicalPeakAge`/`technicalPeakAge`/`mentalPeakAge`/`growthRate`/`fragility`, `14-` §2 — `ceiling`/`growthRate`/`fragility` partagés par les trois catégories, mais **un âge de pic individuel par catégorie**, seule façon d'exprimer qu'un joueur culmine physiquement avant de culminer mentalement), `PlayerRetired` (le seul Fait émis — irréversible, `16-` §2).

Le vieillissement est scindé en **deux systèmes à responsabilité unique** plutôt qu'un seul, pour que la progression future (entraînement) n'ait jamais deux writers sur les mêmes composants (`13-` §2 — l'invariant qu'un test mécanique vérifie désormais, `Football\PipelineInvariantsTest`) :
  - `RetirementSystem` — purement périodique, **seul système qui `remove()`** `PlayerPotentials` et les trois composants de compétences : retraite probabiliste au-delà d'un âge d'éligibilité (poids âge/fragilité, `AgingBalance`), émet `PlayerRetired`.
  - `PlayerDevelopmentSystem` — purement périodique, **seul writer** (`set()`) des trois composants de compétences : progression/déclin de chaque attribut via un taux annuel converti en probabilité quotidienne d'un pas de ±1 — évite qu'un taux journalier fractionnaire ne s'arrondisse toujours à zéro ; un `ageFactor` calculé séparément par catégorie à partir de son propre pic, et une pente de déclin post-pic différente par catégorie via `AgingBalance` — le physique décline plus vite que le technique, lui-même plus vite que le mental. Lit `TrainingEffect` (composant, `Football/Components/`) comme point d'accroche pour un futur `TrainingSystem` — un multiplicateur `[0.5, 2.0]` déjà borné par son producteur, défaut neutre (1.0) tant que rien ne l'écrit encore (plomberie sans consommateur, comme `TickContext.intents`) ; post-pic, le modificateur s'applique par sa réciproque (`1/x`, bijection de `[0.5, 2.0]` sur lui-même) pour qu'un bon encadrement ralentisse le déclin au lieu de l'accélérer.

Ordre dans le pipeline : `RetirementSystem` avant `PlayerDevelopmentSystem` (un joueur retraité ce tick est déjà absent de l'itération du second). Simplifications assumées et documentées dans les classes : `ceiling`/`growthRate`/`fragility` partagés entre les trois catégories (seul le pic est distinct), pas de potentiel révélé progressivement (dépend du moteur de match), aucun `TrainingSystem` réel construit dans ce lot (hors périmètre de la phase en cours), `growthPrimeAgeThreshold` uniforme pour tous les joueurs et toutes les catégories (pas d'éclosion précoce/tardive individuelle sur l'entrée en formation) — premier jet à calibrer en Phase 1, pas des valeurs équilibrées.

Le socle générique du noyau (ECS, messagerie, pipeline, `step()`) est posé au complet et exécutable de bout en bout, et a maintenant un premier consommateur réel du domaine football. `TickContext.intents` reste de la plomberie sans consommateur.

**Ne pas construire une brique hors du périmètre de la phase en cours** sauf demande explicite — ex. pas de CQRS/event store/API avant la Phase 3-4, pas de moteur L1/L2 avant que L0 tourne. Voir `15-roadmap.md` §4 pour les critères de sortie de chaque phase, et §5 pour ce qu'il ne faut explicitement pas faire.

En parallèle de la Phase 0 : le prototype papier/CLI de la boucle de jeu de l'agent (tension commission vs satisfaction du client vs réputation, `14-` §5) est un prérequis, pas un nice-to-have — c'est l'inconnue qui peut tuer le projet (`15-` §4, Phase 5).
