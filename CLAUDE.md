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

**Stade actuel : Phase 0 close et mesurée (`15-roadmap.md` §4).** Le critère de sortie a été vérifié empiriquement le 2026-08-02 via `packages/harness/bin/aggregate.php` (40 saisons, seeds 42 et 7, 500 joueurs/18 clubs) : effectif stationnaire ~313-329 dès l'année ~13, répartition domicile/nul/extérieur 41.8/29.6/28.6% (proche du réel), 11 champions différents sur 18 clubs en 19 saisons (deux clubs à 4 titres chacun, aucun quasi-monopole). La fenêtre "20 saisons" du critère initial était trop courte pour une population de départ pas encore à l'équilibre d'âge — voir la note empirique dans `15-roadmap.md` §4. Ce qui reste ouvert : plusieurs briques du critère de sortie Phase 1 (Gini des titres/rotation du top automatisés, métriques de graphe d'événements, test de déterminisme sur un run complet, CI) sont encore à construire avant d'ajouter la prochaine vague de systèmes.

Ce qui existe et tourne de bout en bout — **détail classe par classe dans `packages/kernel/README.md`, pas dupliqué ici** :
- Le socle générique (`src/Core/` : ECS, messagerie Fait/DecisionRequest/Intent, `Scheduler`/`OutQueue`, `Pipeline`, PRNG 32 bits, `Ruleset` versionné imbriqué par sous-domaine, `Simulation::step()`).
- Le domaine football (`src/Football/`) : vieillissement (retraite + progression des compétences), entraînement, intake de jeunes (ferme la boucle démographique), calendrier + match L0 (Dixon-Coles) + classement.
- Pipeline déclaré : `YouthIntakeSystem`, `TrainingSystem`, `RetirementSystem`, `PlayerDevelopmentSystem`, `CalendarSystem`, `MatchSystem`, `CompetitionSystem`.
- `packages/harness/` : population et clubs synthétiques, comparaison de runs (baseline vs `Ruleset` modifié, `RulesetOverride::withFields()`), métriques d'effectif/pyramide des âges — coupe transversale uniquement (la vue longitudinale par delta chaîné a été retirée, biaisée sous intake continu).

Décision à connaître avant de toucher au moteur de match : `exp()` est la première fonction transcendante autorisée dans le noyau (une seule machine exécute le noyau, donc pas de risque de portabilité cross-plateforme — cf. `docs/13-` §4.8 et `packages/kernel/README.md`). `PlayerFactory` reste volontairement sans fonction transcendante (`Beta(1,k)` plutôt qu'une log-normale) pour un besoin différent, la portabilité cross-plateforme de la loi de talent — à revisiter si une comparaison de hash inter-machines apparaît un jour.

`TickContext.intents` reste de la plomberie sans consommateur.

**Ne pas construire une brique hors du périmètre de la phase en cours** sauf demande explicite — voir `15-roadmap.md` §4 pour les critères de sortie de chaque phase, §5 pour ce qu'il ne faut explicitement pas faire.

En parallèle de la Phase 0 : le prototype papier/CLI de la boucle de jeu de l'agent (tension commission vs satisfaction du client vs réputation, `14-` §5) est un prérequis, pas un nice-to-have — c'est l'inconnue qui peut tuer le projet (`15-` §4, Phase 5).
