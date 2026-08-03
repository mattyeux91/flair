# 01 — Vue d'ensemble

## 1. Le problème

On veut un monde de football qui **vit tout seul**. Pas un jeu qui calcule une saison
quand on appuie sur un bouton : un monde où les joueurs vieillissent, les contrats
expirent, les clubs s'enrichissent et déclinent, que quelqu'un regarde ou non.

Ça pose trois contraintes qui, ensemble, dictent toute l'architecture :

1. **Ça doit tourner longtemps.** Des centaines de saisons simulées. Une erreur qui
   dérive de 0,1 % par tick est invisible au tick 10 et catastrophique au tick 10 000.
2. **Ça doit être reproductible.** Si on ne peut pas rejouer exactement une simulation,
   on ne peut ni déboguer, ni équilibrer, ni comparer deux versions des règles.
3. **Ça doit être compréhensible.** Un monde émergent produit des comportements qu'on
   n'a pas écrits. Si on ne peut pas remonter d'un effet à sa cause, on ne l'équilibre
   pas, on le subit.

La réponse tient en une phrase : **le noyau de simulation est une fonction pure.**

## 2. La fonction `step()`

> **Définition — fonction pure.** Une fonction dont le résultat ne dépend que de ses
> arguments, et qui ne fait rien d'autre que produire ce résultat : pas de lecture
> d'horloge, pas de fichier, pas de réseau, pas d'aléatoire non contrôlé. Appelée deux
> fois avec les mêmes entrées, elle rend deux fois la même chose.

Tout le noyau se ramène à ceci (`packages/kernel/src/Core/Simulation/Simulation.php`) :

```php
public function step(WorldState $state, TickContext $ctx): StepResult
{
    $this->pipeline->tick($state, $ctx->tick, $ctx->seed, $ctx->ruleset, $ctx->intents);

    return new StepResult($state, $state->outQueue()->pending());
}
```

- **`WorldState`** — l'état complet du monde : toutes les entités, tous leurs composants,
  les files d'événements en attente. Voir [chapitre 02](02-le-modele-de-donnees.md).
- **`TickContext`** — les entrées du tick : le numéro de tick, la graine du monde, les
  règles (`Ruleset`), et les intentions des agents. C'est *tout* ce qui entre en plus
  de l'état.
- **`StepResult`** — l'état (muté sur place) et la liste des **Faits** émis pendant ce
  tick. C'est ce qu'un futur `Host` journaliserait en base et diffuserait aux clients.

Une nuance qui compte : `step()` est **pure au sens du déterminisme**, pas au sens de
l'immuabilité. `$state` est muté sur place puis renvoyé. Copier tout le monde à chaque
tick coûterait cher pour un bénéfice nul — personne ne garde l'ancien état.

### Ce que « pure » interdit, concrètement

Dans `packages/kernel/`, ces choses sont bannies :

| Interdit | Pourquoi |
|---|---|
| `rand()`, `mt_rand()`, `random_int()` | Non reproductible. Le noyau a son propre PRNG, voir [ch. 05](05-determinisme-et-aleatoire.md) |
| `time()`, `new DateTime()` sans argument | L'horloge murale n'existe pas dans le monde. Le seul temps est `$ctx->tick` |
| `getenv()`, `file_get_contents()`, requêtes réseau | Une entrée cachée est une entrée non reproductible |
| Un framework (Symfony, Laravel), un ORM | Le noyau doit pouvoir tourner dans un test unitaire nu |

Le noyau n'a **aucune dépendance runtime**. Le seul appel à une fonction mathématique
non triviale est `exp()`, dans le moteur de match — décision assumée, détaillée au
[chapitre 05 §6](05-determinisme-et-aleatoire.md#6-les-flottants-et-la-fonction-exp).

## 3. Le temps

> **Définition — tick.** Une itération de la simulation. Ici, **1 tick = 1 jour simulé**.

Il n'y a pas d'horloge, pas de calendrier grégorien, pas d'epoch réelle. Le seul temps
connu du noyau est un compteur de jours, `$ctx->tick`, qui commence à 0.

`Core\Support\SimDate` enveloppe ce compteur :

```php
final readonly class SimDate
{
    public function __construct(public int $epochDay) {}

    public function yearsSince(self $earlier): float
    {
        return ($this->epochDay - $earlier->epochDay) / 365.0;
    }
}
```

C'est tout. Une année vaut 365 jours, sans exception ni année bissextile. Conséquence
pratique : « le 1er juillet » n'est pas exprimable, donc les rendez-vous annuels du
monde s'écrivent `tick % 365 === jour` :

| Rendez-vous | Jour de l'année | Système |
|---|---|---|
| Génération du calendrier de la saison | 0 | `CalendarSystem` |
| Arrivée des jeunes (intake) | 180 | `YouthIntakeSystem` |
| Renouvellement des contrats (mercato) | 180 | `ContractSystem` |
| Versement des salaires | `tick % 7 === 0` | `FinanceSystem` |

Ce n'est pas définitif : le jour où une vraie notion de phase de saison existera, ces
modulos disparaîtront. Ils sont documentés comme provisoires dans le code
(`YouthIntakeBalance::$intakeDayOfYear`).

### Pourquoi un jour, et pas une heure ou une semaine ?

Un tick trop fin coûte du CPU pour rien : entre deux jours, rien d'intéressant ne se
passe dans la vie d'un joueur de football. Un tick trop gros (la semaine) rendrait
impossible de dater un match à la journée. Le jour est le grain naturel du domaine.

L'ordre de grandeur : une saison = 365 ticks, un monde de 40 saisons = ~14 600 appels à
`step()`. C'est peu. **Le CPU n'est pas le facteur limitant de ce projet** — c'est la
raison pour laquelle PHP est un choix acceptable.

## 4. La carte

```
   ┌──────────────────────────────────────────────────────────────────────┐
   │  HOST  (n'existe pas encore — Phase 3)                               │
   │  boucle temps réel · persistance · diffusion SSE · snapshots         │
   └────────────────────────────┬─────────────────────────────────────────┘
                                │  appelle step(state, ctx) en boucle
   ┌────────────────────────────▼─────────────────────────────────────────┐
   │  KERNEL   packages/kernel/                                           │
   │                                                                      │
   │  ┌─ Core/ ────────────────────────────────────────────────────────┐  │
   │  │  Ecs/         WorldState, ComponentStore, EntityIdAllocator     │  │
   │  │  Messaging/   DomainEvent · Intent · DecisionRequest            │  │
   │  │               OutQueue · Scheduler                              │  │
   │  │  Pipeline/    System, Pipeline, SystemGraph, SystemContext      │  │
   │  │  Ruleset/     Ruleset + Balance (leviers d'équilibrage)         │  │
   │  │  Support/     Rng (xoshiro128**), Hash, Math32, SimDate         │  │
   │  │  Simulation/  Simulation::step()                                │  │
   │  └────────────────────────────────────────────────────────────────┘  │
   │                            ▲ générique, ne sait rien du football     │
   │  ┌─ Football/ ─────────────┴──────────────────────────────────────┐  │
   │  │  Components/  Person, Contract, Finances, Facilities, ...       │  │
   │  │  Events/      MatchPlayed, ContractSigned, SeasonEnded, ...     │  │
   │  │  Systems/     les 11 systèmes du monde                          │  │
   │  │  Generation/  PlayerFactory, PoissonMatchEngine                 │  │
   │  │  Support/     WageModel (fonction pure partagée)                │  │
   │  │  FootballPipeline  ← le registre des systèmes                   │  │
   │  └────────────────────────────────────────────────────────────────┘  │
   └────────────────────────────┬─────────────────────────────────────────┘
                                │  dépend de (jamais l'inverse)
   ┌────────────────────────────▼─────────────────────────────────────────┐
   │  HARNESS   packages/harness/                                         │
   │  population synthétique · métriques · comparaison à graines          │
   │  appariées · tests de régression et de déterminisme                  │
   └──────────────────────────────────────────────────────────────────────┘
```

La séparation `Core/` ↔ `Football/` est stricte et à sens unique : `Core\Pipeline\Pipeline`
ne connaît aucun composant football, aucun événement football, aucun système nommé. Il
sait exécuter *une liste de systèmes*, point. Tout ce qui est spécifique au football vit
dans `Football/`, et le lien entre les deux est **une seule liste**, dans
`Football\FootballPipeline`.

## 5. Le tick en une image

Voici ce qui se passe dans un appel à `step()`. Les détails sont au
[chapitre 03](03-le-tick-et-le-pipeline.md), mais la forme générale se retient tout de
suite :

```
  ENTRÉE                            TICK N                          SORTIE
                    ┌───────────────────────────────────────┐
  WorldState  ─────►│ 1. On draine les files                │
                    │    Scheduler (échéances ≤ N)          │
  TickContext ─────►│    + OutQueue (émis au tick N-1)      │
   tick, seed,      │    = $incoming, figé une fois pour    │
   ruleset,         │      toutes                           │
   intents          │                                       │
                    │ 2. Pour chaque système, dans l'ordre  │
                    │    a) handle() sur chaque événement   │
                    │       de $incoming qui l'intéresse    │
                    │    b) update() une fois               │
                    │                                       │
                    │    Chaque système lit et écrit des    │
                    │    composants, émet des Faits, ou     │
                    │    planifie des échéances futures     │
                    └───────────────┬───────────────────────┘
                                    │
                                    ▼
                    WorldState muté  +  StepResult.events
                                        (ce qui a été émis
                                         pendant ce tick →
                                         traité au tick N+1)
```

**La règle qui rend le tout stable :** un événement émis pendant le tick N n'est jamais
traité pendant le tick N. Il attend le tick N+1. C'est ce qui rend une boucle infinie
intra-tick **structurellement impossible**, pas seulement improbable. Voir
[chapitre 04 §4](04-messages-et-files.md#4-le-contrôle-des-cascades).

## 6. Les cinq règles qu'on ne négocie pas

Elles reviendront chapitre après chapitre. Les avoir en tête dès maintenant aide à lire
le reste :

1. **Le noyau est pur et déterministe.** Zéro I/O, zéro horloge, zéro `rand()`.
2. **Aucun sous-type.** Il n'existe pas de classe `Player`. Une entité est un entier ;
   « joueur » est le nom informel d'un entier qui porte certains composants.
   → [ch. 02](02-le-modele-de-donnees.md)
3. **Un système n'appelle jamais un autre système.** Il écrit un composant lu plus loin,
   ou il émet un événement. → [ch. 03](03-le-tick-et-le-pipeline.md)
4. **L'itération est toujours triée par identifiant croissant.** Jamais l'ordre d'insertion
   d'un tableau associatif. → [ch. 05](05-determinisme-et-aleatoire.md)
5. **Les règles chiffrées vivent dans le `Ruleset`,** jamais en dur dans un système.
   → [ch. 06](06-le-ruleset.md)

---

**Suite :** [02 — Le modèle de données (ECS)](02-le-modele-de-donnees.md)
</content>
</invoke>
