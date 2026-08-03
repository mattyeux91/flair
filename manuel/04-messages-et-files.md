# 04 — Messages et files

## 1. Trois messages, jamais confondus

Un monde persistant fait circuler trois choses de nature complètement différente. Les
mélanger est l'erreur classique — et c'est ce qui transforme un journal d'événements en
poubelle illisible.

```
   ┌──────────────────────────────────────────────────────────────────────┐
   │  FAIT  (DomainEvent)          « ceci est arrivé »                    │
   │  passé · immuable · journalisé pour toujours                         │
   │  MatchPlayed, PlayerRetired, ContractSigned, SeasonEnded             │
   ├──────────────────────────────────────────────────────────────────────┤
   │  DECISION REQUEST             « quelqu'un doit trancher »            │
   │  présent · transitoire · JAMAIS journalisée · se résout ou expire    │
   │  (aucune implémentation à ce jour)                                   │
   ├──────────────────────────────────────────────────────────────────────┤
   │  INTENT                       « voici ce que je fais »               │
   │  futur immédiat · consommé une fois · journalisé à part              │
   │  produit indifféremment par un humain ou un PNJ                      │
   │  (plomberie en place, aucun consommateur)                            │
   └──────────────────────────────────────────────────────────────────────┘
```

Dans le code, ce sont trois interfaces marqueurs vides
(`Core\Messaging\DomainEvent`, `DecisionRequest`, `Intent`). Vides à dessein : elles
n'imposent aucune structure, elles imposent une **classification**. Un développeur qui
implémente `DomainEvent` déclare « ceci est un fait passé et sera journalisé à jamais » —
et c'est exactement la question qu'on veut lui faire se poser.

Le cycle attendu, une fois la boucle de jeu branchée :

```
   Fait ──► le monde change ──► une DecisionRequest naît
                                       │
                          ┌────────────┴────────────┐
                          ▼                         ▼
                  un humain répond           un PNJ répond
                  (via l'API)                (via une politique)
                          │                         │
                          └────────► Intent ◄───────┘
                                       │
                                       ▼
                               le noyau l'exécute
                                       │
                                       ▼
                                   nouveau Fait
```

Le point qui rend le monde vivable sans joueur : **humain et PNJ passent par la même
interface**. Le noyau ne sait pas qui a produit un `Intent`.

## 2. Le seuil d'émission : une mutation n'est pas un événement

La question qu'un développeur se pose à chaque écriture de composant : *dois-je aussi
émettre un Fait ?* La réponse par défaut est **non**.

Un fait mérite d'être émis s'il satisfait au moins un des trois critères :

| Critère | Exemple qui passe | Exemple qui ne passe pas |
|---|---|---|
| **Franchit un seuil comportemental** | un club agrandit son centre de formation | sa trésorerie passe de 4,1 à 4,2 M€ |
| **Est irréversible** | un joueur prend sa retraite | ses compétences baissent d'un point |
| **Est racontable** | un champion est sacré | le classement se met à jour à la 12ᵉ journée |

Ce que ça donne dans le code, sur les deux systèmes les plus bavards en écritures :

- `PlayerDevelopmentSystem` écrit trois composants **par joueur et par tick** et n'émet
  **jamais** rien. Un point de `pace` en plus n'est ni un seuil, ni irréversible, ni
  racontable.
- `FinanceSystem` verse les salaires de toute la population chaque semaine et n'émet rien
  non plus. En revanche il émet `ClubInvestedInFacilities` : un par club et par saison,
  décision engageante, racontable.

**L'ordre de grandeur qu'on évite.** Un Fait par joueur et par semaine sur 20 saisons avec
500 joueurs = ~520 000 événements de bruit pur. À l'échelle cible du monde, on parle de
millions. Le journal d'événements devient inexploitable, et les snapshots aussi.

## 3. Les deux files

### `OutQueue` — la propagation d'un tick au suivant

Une seule classe pour ce que la conception appelle « OutQueue » et « InQueue ». Ce ne sont
pas deux structures : c'est **la même file observée à deux moments de son cycle de vie**.

```
   ── tick N ────────────────────────────────────────────────────────────
     début : drain()  ──► ce qui a été émis au tick N-1  = $incoming
                          (la file est maintenant vide)

     pendant : emit(), emit(), emit()  ──► la file se remplit

     fin : pending()  ──► lecture NON destructive, pour StepResult
   ── tick N+1 ──────────────────────────────────────────────────────────
     début : drain()  ──► exactement ce qui vient d'être émis
```

`pending()` est ce que `StepResult` expose au `Host` : puisque la file a été vidée en
début de tick, tout ce qu'elle contient à la fin vient forcément des `emit()` de ce tick.

### `Scheduler` — les échéances datées

Pour ce qui doit arriver **à un tick précis, connu à l'avance**. Deux usages aujourd'hui,
tous deux posés par `CalendarSystem` au moment où il génère la saison :

- un `FixtureKickoff` par match, à sa date de coup d'envoi (jusqu'à ~240 jours plus tard) ;
- un `SeasonEnded`, au lendemain de la dernière journée.

```php
$due = [];
foreach ($this->entries as $entry) {
    if ($entry->atTick <= $tick) { $due[] = $entry; } else { $remaining[] = $entry; }
}
```

Noter le `<=`, et pas `===` : une échéance ratée (un tick sauté par un `Host` qui rattrape
son retard) est déclenchée au premier tick suivant, jamais perdue.

## 4. L'ordre total, ou comment on ne perd pas le déterminisme ici

C'est l'endroit le plus insidieux du moteur. Deux événements traités dans un ordre
différent produisent un monde différent — et rien ne le signale.

**Aucune des deux files n'utilise `SplPriorityQueue`.** Une file de priorité PHP départage
mal les ex æquo (l'ordre entre éléments de même priorité n'est pas spécifié). Les deux
files stockent dans un tableau simple et **trient explicitement à la lecture**, sur une
clé qui rend l'ordre **total** — c'est-à-dire où l'égalité parfaite est impossible :

| File | Clé de tri | Pourquoi ce nombre de composantes |
|---|---|---|
| `OutQueue` | `(systemIndex, entityId, seq)` | Pas de dimension temporelle : elle ne contient que le tick courant |
| `Scheduler` | `(atTick, systemIndex, entityId, seq)` | Même chose, plus la date d'échéance |

- **`systemIndex`** — la position du système émetteur dans le pipeline. Les Faits d'un
  système précoce passent avant.
- **`entityId`** — croissant, jamais l'ordre d'insertion.
- **`seq`** — un compteur monotone **par tick**, partagé par tous les systèmes du tick
  (`Core\Pipeline\SeqCounter`, une instance créée au début de `Pipeline::tick()`).
  C'est le départage de dernier recours : deux événements émis par le même système sur
  la même entité gardent leur ordre d'émission.

`SeqCounter` est volontairement une classe distincte de `EntityIdAllocator` malgré un
mécanisme identique (`$this->next++`). Ce sont deux concepts sans rapport — identité
d'entité contre ordre d'émission — et les fusionner pour économiser dix lignes rendrait
le code trompeur.

## 5. Le contrôle des cascades

> **Définition — cascade.** Un événement en déclenche un autre, qui en déclenche un
> autre. Dans une simulation de monde, c'est souhaitable (c'est ce qui produit des
> histoires) et dangereux (ça peut ne jamais s'arrêter).

Le mécanisme de protection tient en une ligne, déjà vue au chapitre 03 :

```php
$incoming = [...$scheduler->drainDueBy($tick), ...$outQueue->drain()];
```

Le lot est calculé **avant** que le premier système ne s'exécute. Tout ce qui est émis
pendant le tick part dans une file vide, traitée au tick suivant.

```
   tick N       A émet e1
                    └──────► OutQueue

   tick N+1     e1 traité par B, qui émet e2
                    └──────► OutQueue

   tick N+2     e2 traité par C, qui émet e3
                ...

   Une cascade de profondeur 50 met 50 jours simulés à se dérouler.
   Elle ne peut PAS boucler à l'intérieur d'un tick.
```

Ce n'est **pas** une limite de profondeur, pas un compteur, pas un disjoncteur : c'est
structurel. Une cascade infinie reste possible en théorie (A émet un événement qui fait
émettre le même événement à B, indéfiniment), mais elle se déroule alors à un pas par
jour simulé, elle est visible dans les métriques, et elle ne gèle jamais un tick.

C'est précisément ce que mesure le harness avec le **backlog annuel du `Scheduler`** :
une file qui grossit sans jamais redescendre est le signe d'une cascade non amortie. Voir
[chapitre 09](09-mesurer-le-monde.md).

## 6. Le cas d'école : `SeasonEnded` → `SeasonConcluded`

Pourquoi deux événements pour dire « la saison est finie » ? Parce qu'aucun système ne
sait les deux moitiés de l'information.

```
   CalendarSystem                    CompetitionSystem              FinanceSystem
   ──────────────                    ─────────────────              ─────────────
   sait QUAND une saison finit       sait QUEL est le classement
   (il a planifié toutes             (il possède Standings)
    les journées)
   ne lit pas Standings              ne connaît pas le calendrier

         │ schedule(SeasonEnded, au lendemain de la dernière journée)
         └──────────────────────────► tick J
                                      handle(SeasonEnded)
                                      lit Standings, trie
                                      emit(SeasonConcluded{finalRanking})
                                              │
                                              └────────────────► tick J+1
                                                                 handle()
                                                                 répartit les
                                                                 droits TV
```

Deux détails qui valent d'être compris :

**Pourquoi le classement voyage dans le payload.** `FinanceSystem` a besoin du classement,
mais il ne *peut pas* lire `Standings` : ce composant est écrit par `CompetitionSystem`,
placé plus loin dans le pipeline. Lire un composant écrit plus loin serait une dépendance
inversée, interdite. Le classement voyage donc dans l'événement — avec l'avantage
collatéral que `FinanceSystem` devient indifférent à la forme de `Standings`.

**Pourquoi `SeasonEnded` existe.** Avant lui, le monde n'avait aucune fin de saison :
`CompetitionSystem` sacrait le champion au démarrage de la saison suivante, soit **120
jours après son dernier match** au calibrage de référence. Sans conséquence tant que seul
`FinanceSystem` écoutait — mais le journal d'événements est ce qu'on persistera et
rejouera. Une date fausse s'y serait gravée définitivement.

## 7. Le catalogue des événements

| Événement | Émis par | Comment | Consommé par |
|---|---|---|---|
| `YouthPlayerPromoted` | `YouthIntakeSystem` | `emit` | — (narration future) |
| `PlayerRetired` | `RetirementSystem` | `emit` | `SquadSystem` |
| `SeasonStarted` | `CalendarSystem` | `emit` | `CompetitionSystem` (RAZ du classement) |
| `FixtureKickoff` | `CalendarSystem` | `schedule` | `MatchSystem`, puis `CompetitionSystem` |
| `MatchPlayed` | `MatchSystem` | `emit` | — (narration future) |
| `SeasonEnded` | `CalendarSystem` | `schedule` | `CompetitionSystem` |
| `SeasonConcluded` | `CompetitionSystem` | `emit` | `FinanceSystem`, `FacilitiesSystem` |
| `ClubInvestedInFacilities` | `FinanceSystem` | `emit` | `FacilitiesSystem` |
| `ContractSigned` | `ContractSystem` | `emit` | `SquadSystem` |
| `ContractExpired` | `ContractSystem` | `emit` | `SquadSystem` |

Deux événements n'ont aucun consommateur (`YouthPlayerPromoted`, `MatchPlayed`) et c'est
volontaire : ils passent le test de pertinence (racontables), ils alimenteront le digest
narratif, et ils sont déjà utilisés par le harness pour ses métriques.

### Une règle de conception visible dans ce tableau

`ContractExpired` n'est émis **que pour les joueurs que personne n'a repris**. Un joueur
libéré par son club et signé ailleurs le même jour n'émet que `ContractSigned`.

Pourquoi : `SquadSystem` n'a alors jamais deux Faits contradictoires à appliquer sur la
même entité au même tick. L'ordre de traitement étant total et déterministe, le résultat
serait reproductible — mais il dépendrait d'un détail d'ordonnancement plutôt que d'une
intention, et un lecteur du journal y verrait un licenciement suivi d'un réembauchage qui
n'a jamais eu lieu.

## 8. L'idempotence, et pourquoi elle est presque gratuite ici

> **Définition — idempotence.** Traiter deux fois le même message produit le même
> résultat que le traiter une fois.

Elle compte au moment du rejeu (reconstruire un monde depuis son journal) et de la
reprise après incident. Elle est presque gratuite dans ce moteur pour une raison
structurelle : **un `handle()` écrit des composants en valeur absolue, pas en delta.**

```php
// SquadSystem — idempotent : rejouer donne le même Contract
$ctx->write(Contract::class)->set($event->playerId, new Contract(
    $event->clubId, $event->wagePerWeekCents, new SimDate($event->expiresOnEpochDay)
));
```

Le contre-exemple est dans `FinanceSystem`, qui fait `$finances->balanceCents - $wage` :
un delta, donc non idempotent. C'est acceptable parce que ce système est déclenché par le
calendrier (`tick % 7`), pas par un message rejouable — mais c'est le genre d'endroit
qu'il faudra regarder quand le rejeu existera.

Un `SquadSystem::release()` illustre la précaution correspondante :

```php
if ($contract !== null && $expectedClubId !== null && $contract->clubId !== $expectedClubId) {
    return;   // Fait périmé : ne pas défaire un engagement plus récent
}
```

Aucun chemin ne produit ce cas aujourd'hui. Mais l'écart d'un tick entre décision et
application est structurel, et la garde coûte une comparaison.

---

**Suite :** [05 — Déterminisme et aléatoire](05-determinisme-et-aleatoire.md)
</content>
</invoke>
