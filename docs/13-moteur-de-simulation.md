# Moteur de simulation — tick, systèmes, scheduler, déterminisme, persistance

## 1. Le temps

| Notion | Définition |
|---|---|
| **Tick** | La plus petite unité d'avancement. **1 tick = 1 jour simulé.** |
| **Temps simulé** | Le calendrier du monde (`SimDate`). Seul temps connu du noyau. |
| **Temps réel** | L'horloge murale. Connu du Host uniquement. |
| **Cadence** | Le mapping entre les deux, propre à chaque monde (`1 jour = 1 h` en live, `= 0 s` dans le harness). |

Le jour est le bon grain : assez fin pour placer matchs, entraînements, négociations et blessures ; assez gros pour qu'une saison coûte 365 ticks et pas 500 000.

Le noyau **ne connaît pas** le temps réel. Il reçoit un numéro de tick. C'est ce qui permet de faire tourner 1 000 saisons en quelques minutes dans le harness avec exactement le même code qu'en production.

---

## 2. Le tick hybride : pipeline + files d'événements

Deux modèles de contrôle existent pour un moteur de simulation :

| | *Pull* — pipeline | *Push* — événementiel |
|---|---|---|
| Déclenchement | chaque système tourne à chaque tick et requête ce qui l'intéresse | les systèmes sont réveillés par les événements |
| Ordre | statique, déclaré | émergent |
| Déterminisme | gratuit | difficile |
| Traçabilité causale | faible | forte |
| Cascades | impossibles | possibles, à dompter |

**On prend les deux** : le pipeline ordonné comme colonne vertébrale (il donne le déterminisme), les files d'événements pour la propagation (elles donnent la causalité et l'émergence).

### Anatomie d'un tick

```
TICK N
 1. Scheduler          → les événements arrivés à échéance entrent
 2. InQueue (tick N-1) → fusionnée, ordonnée déterministement
 3. PIPELINE — ordre statique, pour chaque système dans l'ordre déclaré :
      handle(événements pertinents)   ← réactif
      update(requête sur composants)  ← périodique
      émet : Faits (avec seuils) · DecisionRequests · événements planifiés
      ⚠ tout nouvel événement part en OutQueue — JAMAIS traité dans ce tick
 4. Phase de décision  → agents humains et PNJ consomment les DecisionRequests
                         et produisent des Intents pour le tick N+1
 5. Journalisation des Faits + application des projections
 6. OutQueue → InQueue du tick N+1
```

La règle qui fait tout tenir, à l'étape 3 :

> **Un événement n'est jamais traité dans le tick qui l'a produit.**

C'est ce qui transforme un graphe de propagation potentiellement explosif en un monde simulable : la cascade ne peut plus boucler dans le même tick, elle s'étale dans le temps — ce qui est aussi plus naturel. Une blessure ne fait pas réagir la presse dans la même seconde.

Le détail de la propagation, des seuils d'émission et du contrôle des cascades vit dans `16-evenements-et-cascades.md`.

### L'interface `System`

Un système peut être **réactif**, **périodique**, ou les deux. Forcer tout dans `handle(event)` ne marche pas : le vieillissement, la dérive d'entraînement et les charges financières sont périodiques par nature, pas événementiels.

```php
interface System
{
    public function id(): string;

    /** @return list<class-string> déclaré → opposable (voir plus bas) */
    public function reads(): array;

    /** @return list<class-string> composants mutés en place via ComponentStore::set() */
    public function writes(): array;

    /** @return list<class-string> composants retirés via ComponentStore::remove() (archétype-strip) — distinct de writes(), qui ne couvre que set() */
    public function removes(): array;

    /** @return list<class-string> types d'événements écoutés — vide si purement périodique */
    public function subscribesTo(): array;

    /** Réactif — appelé une fois par événement pertinent, dans l'ordre de la file */
    public function handle(DomainEvent $event, SystemContext $ctx): void;

    /** Périodique — appelé une fois par tick, après les handle() du système */
    public function update(SystemContext $ctx): void;
}

// Ordre du pipeline — donnée d'architecture, versionnée avec le noyau
final class Pipeline
{
    public const SYSTEMS = [
        TimeSystem::class,            // avance le calendrier, déclenche les événements datés
        IntentIngestionSystem::class, // valide et applique les intentions (humaines et PNJ)
        ContractSystem::class,        // échéances, clauses, renouvellements
        TransferSystem::class,        // tours de marché (uniquement en mercato)
        TrainingSystem::class,        // progression vers le potentiel
        InjurySystem::class,          // survenue et guérison
        MatchSystem::class,           // joue les rencontres du jour
        CompetitionSystem::class,     // classements, qualifications, éliminations
        FinanceSystem::class,         // recettes, salaires, amortissements
        BoardSystem::class,           // attentes, recrutement/licenciement
        FanSystem::class,             // humeur, affluence, abonnements
        NarrativeSystem::class,       // détecte les histoires dans le flux d'événements
        NpcDecisionSystem::class,     // les PNJ produisent leurs intentions du tick suivant
    ];
}
```

**L'ordre est une donnée d'architecture, pas un détail.** Il est déclaré, versionné, et fait partie de la définition du noyau. Le changer change le monde.

Les déclarations `reads`/`writes` permettent un test automatique : détecter deux systèmes qui écrivent le même composant, ou un système qui lit un composant écrit plus tard dans le pipeline (dépendance inversée).

### ⚠️ Les déclarations sont opposables, pas documentaires

Ce test automatique a un angle mort qu'il faut nommer, parce qu'il est invisible : **il compare des déclarations à des déclarations.** Il ne peut structurellement pas détecter un système qui touche un composant qu'il n'a pas déclaré. Tant que rien ne contraint le corps des méthodes, les quatre listes sont des commentaires — et tout ce qui se déduit d'elles se déduit d'un mensonge possible.

D'où la règle : **`SystemContext` refuse tout accès non déclaré** (`UndeclaredAccessException`). L'accès au monde est scindé en deux handles dont le type porte la permission :

```php
$ctx->read(Facilities::class)->get($clubId);         // exige reads()
$ctx->write(Facilities::class)->set($clubId, $new);  // exige writes()/creates()/removes()
```

- `read()` renvoie un `ComponentReader` — il n'a **physiquement pas** de `set()`, donc la faute est une erreur d'analyse statique et pas seulement une exception au premier tick.
- `write()` renvoie un `GuardedComponentWriter`, qui n'expose **pas** `get()` : lire passe obligatoirement par `read()`, sinon `reads()` recommencerait à pourrir. Ce n'est pas cosmétique — `reads()` est ce dont se déduisent les dépendances entre systèmes. Les deux handles ne forment pas une paire symétrique : le lecteur est une capacité de l'ECS, l'écrivain un garde qui ne vaut que pour un système et un tick — d'où le préfixe, et d'où le fait qu'ils ne vivent pas dans le même dossier.
- Un **singleton** se déclare comme un composant : `MonetaryMass` figure dans les `reads()`/`writes()` de `FinanceSystem`.
- `creates()` devient vérifiable plutôt que déclaratif : `createEntity()` retient les entités qu'il rend, et `set()` sur un composant déclaré en `creates()` seul exige que l'entité soit de celles-là. La portée « ce système, ce tick » tombe juste sans effort, puisque le pipeline construit un `SystemContext` par système et par tick.

La restriction porte sur `SystemContext`, **jamais sur l'ECS** : `WorldState`/`ComponentStore` gardent un accès libre, parce que worldgen et le harness écrivent le monde initial sans être des systèmes.

### Communication entre systèmes

Trois canaux, et trois seulement :

1. **Les composants** — un système écrit, un système plus loin dans le pipeline lit. Propagation *dans le tick*.
2. **L'OutQueue** — un système émet un événement, les systèmes le traiteront *au tick suivant*. Propagation *entre les ticks*.
3. **Le Scheduler** — un système programme un événement pour un tick futur. Propagation *différée*.

> **Un système écoute, il ne commande pas.** Aucun système n'appelle jamais un autre système, directement ou indirectement. Ça détruirait l'ordonnancement, la testabilité et le déterminisme d'un coup.

Le canal 1 sert ce qui doit se résoudre le même jour (un match joué doit alimenter le classement du jour). Les canaux 2 et 3 servent tout le reste — et par défaut, **on utilise le canal 2**. Si tu hésites, c'est le canal 2.

---

## 3. Le Scheduler

Le pipeline seul oblige à balayer le monde entier chaque tick pour trouver les rares entités concernées : scanner 8 000 joueurs chaque jour pour identifier les 40 dont la blessure se termine. C'est absurde, et surtout **ça ne sait pas exprimer la temporalité longue**.

Le Scheduler est une file de priorité d'événements datés :

```php
$ctx->schedule(new RecoveryCompleted($playerId), atTick: $ctx->tick + 30);
```

Il porte les enchaînements qui font qu'un monde paraît naturel plutôt que mécanique :

| Situation | Échelonnement |
|---|---|
| Blessure | `PlayerInjured` (immédiat) → `RecoveryUpdate` (+7 j) → `MedicalAssessment` (+30 j) |
| Transfert | `ScoutInterest` (J1) → `OfferSubmitted` (J15) → `NegotiationFinished` (J30) |
| Contrat | `ContractExpiringSoon` (−180 j) → `RenewalWindowOpen` (−90 j) → `ContractExpired` (J0) |

### Ordonnancement déterministe — obligatoire

Une file de priorité départage mal les ex æquo, et c'est exactement là que le déterminisme se perd sans bruit. Tri **total** imposé :

```
(tick, systemIndex, entityId, seq)
```

Jamais l'ordre d'insertion, jamais l'ordre d'un tas binaire non stabilisé. `seq` est un compteur monotone par tick, attribué à l'émission.

---

## 4. Déterminisme — les règles non négociables

### 4.0 Pourquoi — parce que ça se décrète mal

D'abord, lever le malentendu le plus probable :

> **Déterministe ≠ prévisible.** Le monde reste plein d'aléatoire — blessures, résultats, éclosions. Le déterminisme dit seulement : *mêmes entrées + même graine → mêmes sorties*. Deux mondes de graines différentes n'ont rien à voir, et aucun joueur ne perçoit la différence. C'est une propriété de l'outillage, pas du gameplay.

Ce que ça achète, par valeur réelle décroissante :

**1. L'équilibrage — décisif.** Tu passes `injuryBaseHazard` de 0.004 à 0.005 et tu relances 1 000 saisons. Sans déterminisme, l'écart observé mélange l'effet de ton changement et le bruit de tirages différents, et le bruit domine. Avec déterminisme, tu rejoues **les mêmes graines** avant et après : les tirages sont tenus fixes, il ne reste que ton effet. C'est la technique des *common random numbers* — la variance de l'estimateur d'écart chute d'un facteur 5 à 20, soit ~200 runs au lieu de ~4 000 pour détecter le même effet.

⚠️ Ce bénéfice **dépend entièrement de la règle 4.1**. Avec un PRNG global, ajouter un système décale tous les tirages et détruit l'appariement des graines.

**2. Les tests de non-régression.** Sans déterminisme, on ne peut écrire que des tests statistiques (« la moyenne de buts est entre 2,4 et 2,9 ») — faibles et instables, ils passent au vert pendant qu'un bug se cache dans la tolérance. Avec, un seul `hash(état) == X` attrape n'importe quelle modification non intentionnelle. Contrepartie : ce test casse à chaque changement volontaire et doit être régénéré. C'est un fil de détente, pas un test de correction.

**3. Reproduire un bug.** « Saison 12, le marché a inflaté de 400 % » : graine + tick, on rejoue jusqu'au tick fautif et on pose un point d'arrêt. Bénéfice réel mais **partiellement couvert par l'event sourcing seul** — le journal des faits montre déjà la chaîne causale. Le déterminisme n'ajoute que les cas où les calculs intermédiaires ne sont pas journalisés.

Ce que le déterminisme **n'apporte pas**, contrairement à une idée reçue : l'autorité serveur. Celle-ci découle du fait que le client n'exécute rien (`11-` §1). Le déterminisme est indispensable au *lockstep* multijoueur — qu'on ne fait pas.

**L'asymétrie qui tranche** : garder le déterminisme aujourd'hui coûte de la discipline et une règle de lint. L'ajouter dans dix-huit mois, c'est auditer chaque appel aléatoire et chaque boucle sur trente systèmes existants.

### 4.1 Un flux aléatoire par (monde, tick, système, entité)

```php
// JAMAIS un PRNG global partagé
function rngFor(int $worldSeed, int $tick, string $systemId, int $entityId): Rng
{
    return new Rng(Hash::mix32($worldSeed, $tick, crc32($systemId), $entityId));
}
```

**Pourquoi c'est essentiel** : avec un PRNG global, ajouter un système décale le flux aléatoire de tous les suivants. Deux runs d'équilibrage ne sont plus comparables, et une correction de bug ailleurs change tout l'historique. Avec des flux dérivés par hachage, chaque système et chaque entité a sa propre séquence, isolée et stable.

### 4.2 Itération triée

Toute boucle sur des entités itère par `EntityId` croissant. Jamais l'ordre d'une `Map` ou d'un `Set`.

### 4.3 ⚠️ Spécifique PHP : arithmétique 32 bits obligatoire dans le PRNG

**Le piège le plus dangereux du projet.** PHP n'a pas d'entiers non signés, et un dépassement d'`int` **bascule silencieusement en `float`** — sans erreur, sans warning. Un PRNG 64 bits écrit naïvement produira des séquences non reproductibles, et le déterminisme mourra sans que rien ne le signale.

Règles :

- **PRNG 32 bits** (PCG32 ou xoshiro128\*\*), jamais 64.
- **Masquage explicite `& 0xFFFFFFFF` après chaque opération** arithmétique.
- Vecteurs de test issus de l'implémentation de référence, vérifiés en CI.
- Interdits : `rand()`, `mt_rand()`, `random_int()`, `shuffle()`, `array_rand()` — tous non reproductibles entre versions de PHP.

```php
final class Rng
{
    private const MASK = 0xFFFFFFFF;

    public function nextUint32(): int
    {
        $this->state = ($this->state * 1664525 + 1013904223) & self::MASK;
        return $this->state;
    }
}
```

C'est ~40 lignes, et c'est **la première chose à écrire et à tester** dans le projet.

### 4.4 Aucune source externe dans le noyau

Interdits, vérifiés statiquement : `time()`, `microtime()`, `new DateTime()` sans argument, `getenv()`, `$_SERVER`, tout accès réseau, disque ou base.

### 4.5 ⚠️ Ordre total de l'OutQueue

**C'est le risque introduit par le passage à l'événementiel.** Une file remplie par treize systèmes n'a pas d'ordre naturel. Tri imposé à la fusion, avant de devenir l'InQueue du tick suivant :

```
(systemIndex de l'émetteur, entityId sujet, seq d'émission)
```

`systemIndex` est la position dans `Pipeline::SYSTEMS`, pas le nom de la classe — un tri alphabétique changerait l'ordre au moindre renommage.

### 4.6 Ordre de souscription = ordre du pipeline

Quand plusieurs systèmes écoutent le même type d'événement, ils le traitent dans **l'ordre du pipeline**, jamais dans l'ordre d'enregistrement des handlers. Un `EventBus` classique qui appelle ses abonnés dans l'ordre d'inscription est non déterministe dès que l'inscription dépend d'un conteneur d'injection.

### 4.7 Départage des ex æquo du Scheduler

Voir §3 : tri par `(tick, systemIndex, entityId, seq)`. Deux événements programmés pour le même tick doivent avoir un ordre défini, sinon le tas binaire décide — et il décide différemment selon l'ordre d'insertion.

### 4.8 Pas besoin de déterminisme flottant multi-plateforme

Puisque **seul le serveur exécute le noyau**, on n'a besoin que de reproductibilité *même machine, même version de PHP*. Les doubles IEEE-754 suffisent. Pas d'arithmétique en virgule fixe — c'est une complexité qu'on s'épargne, contrairement aux jeux à simulation lockstep côté client.

> Nuance : épingle la version de PHP d'un monde `live` au même titre que `kernelVersion` (§6). Une montée de version majeure de PHP est une migration, pas une mise à jour.

### 4.9 Test de reproductibilité en CI

```
même graine + même ruleset + mêmes intentions
  → hash du WorldState identique après N ticks
  → ET hash de la séquence d'événements identique
```

Le second hash est nécessaire depuis le passage à l'événementiel : deux exécutions peuvent converger vers le même état final en ayant produit les événements dans un ordre différent. L'ordre compte, parce que c'est lui que lisent la narration et les projections.

Ce test tourne à chaque commit. C'est le filet de sécurité qui rend tout le reste possible.

---

## 5. Persistance : que journalise-t-on ?

Deux journaux, et c'est volontaire.

| Journal | Contenu | Sert à |
|---|---|---|
| **Intent log** (entrées) | graine + ruleset + toutes les intentions, horodatées en ticks | rejeu déterministe, tests, audit des actions joueurs |
| **Event log** (sorties) | les **Faits** produits par le noyau (`GoalScored`, `PlayerSigned`, `ManagerSacked`) | projections, flux temps réel client, narration, **histoire du monde** |

Beaucoup de projets ne journalisent que l'un des deux. Les deux sont nécessaires, pour la raison exposée en §6.

### On ne journalise que les Faits

Tout ce qui circule dans les files n'a pas vocation à devenir de l'histoire :

| Message | Journalisé ? |
|---|---|
| **Fait** (`MatchFinished`, `PlayerInjured`) | ✅ event store, immuable |
| **DecisionRequest** (`ClubNeedsRecruitment`) | ❌ transitoire — c'est une question, pas un fait |
| **Intent** (`BidForPlayer`) | ✅ mais dans l'intent log, pas l'event log |
| Signal interne entre systèmes | ❌ |

Et parmi les Faits eux-mêmes, **seuls ceux qui passent le seuil de pertinence** sont émis. Sans cette discipline, `FatigueChanged` sur 8 000 joueurs × 365 ticks inonde l'event store de 3 millions d'entrées de bruit par saison. Voir `16-evenements-et-cascades.md` §2.

**Snapshots** : sérialisation complète du `WorldState` à intervalle régulier (fin de saison, et toutes les N ticks). Le démarrage charge le dernier snapshot et rejoue le delta. Sans snapshot, redémarrer un monde de 10 ans coûte des minutes.

> ⚠️ Le `WorldState` inclut le `Scheduler` et l'`OutQueue`, pas seulement les entités/composants/singletons. Raison : `step(WorldState, TickContext): StepResult` (`11-`§1) ne prend que ces deux paramètres — rien d'autre ne pourrait faire survivre le `Scheduler`/l'`OutQueue` d'un appel à l'autre. C'est aussi ce qui ferme un trou de durabilité réel : un événement seulement *planifié* (`schedule()`) n'émet aucun Fait tant qu'il n'est pas déclenché, donc un snapshot qui l'ignorerait le perdrait silencieusement à un redémarrage du Host.

Stockage : PostgreSQL. `events (world_id, tick, seq, type, payload jsonb)` en append-only, index sur `(world_id, tick, seq)`. Les projections sont des tables normales, reconstructibles depuis zéro.

---

## 6. ⚠️ Le piège : event sourcing + règles qui évoluent

C'est **le problème que ce type de projet découvre trop tard.**

> Rejouer un journal d'intentions avec une version plus récente du noyau produit un état **différent**. Corriger un bug de calcul de fatigue réécrit rétroactivement dix ans d'histoire du monde.

La solution n'est pas technique, elle est de politique :

1. **L'event log est la vérité du passé.** Ce qui s'est produit s'est produit. Les projections se reconstruisent depuis les événements, jamais depuis un rejeu des intentions.
2. **Le rejeu des intentions n'est utilisé qu'en test**, contre des fixtures épinglées à une version de noyau.
3. **Un monde est épinglé à `(kernelVersion, rulesetVersion)`.** Faire évoluer un monde vivant = une **migration** explicite :
   - snapshot avant,
   - script de migration versionné (transformation d'état, pas rejeu),
   - snapshot après, marqué d'une frontière de version dans le journal.
4. **Les mondes `live` migrent rarement** (entre saisons, idéalement). Les mondes `sandbox` migrent librement — c'est là qu'on teste.

Sans cette discipline, tu passeras ton temps à te demander pourquoi la saison 4 n'est plus la même qu'hier.

---

## 7. Dimensionnement — et pourquoi la performance n'est pas ton problème

Ordre de grandeur d'un monde réaliste :

```
8 pays × 2 divisions × 18 clubs        ≈    290 clubs
× 28 joueurs                            ≈  8 100 joueurs
+ staff, agents, dirigeants             ≈ 12 000 entités
```

Coûts :

| Charge | Estimation |
|---|---|
| Jour sans match (entraînement, finance, contrats) sur 12 000 entités | quelques ms |
| Match moteur L0 (Poisson) | microsecondes |
| Match moteur L1 (Markov) | < 1 ms |
| Jour de match (~150 rencontres en L1) | ~150 ms |
| **Une saison complète (365 ticks)** | **quelques dizaines de secondes** |

**Conclusions à en tirer, et elles sont importantes :**

1. **Le CPU n'est pas le facteur limitant.** Les vrais coûts sont l'écriture en base, les projections et les requêtes clients. N'optimise pas le noyau avant d'avoir mesuré la DB.
2. **Tu peux te permettre de simuler des milliers de saisons pour équilibrer.** C'est l'avantage décisif de l'approche noyau pur — utilise-le massivement (voir `14-algorithmes.md` §8).
3. **Optimise pour l'évolutivité, pas pour la vitesse.** Le code lisible et modulaire est le bon arbitrage ici, contrairement à ce que suggère l'intuition « simulation = performance ».

---

## 8. Boucle du Host

En PHP, le Host prend la forme d'une **commande CLI qui avance le monde d'un tick, puis sort** — déclenchée par cron à la cadence du monde. Pas de démon à surveiller, pas de fuite mémoire sur la durée : c'est le grain naturel de PHP, et il suffit à `1 tick = 1 h`.

```php
final class AdvanceWorldCommand
{
    public function __invoke(WorldId $worldId): void
    {
        $this->lock->acquireOrExit($worldId);      // advisory lock Postgres

        $world   = $this->worlds->load($worldId);
        $state   = $this->snapshots->loadLatestAndReplay($worldId);
        $intents = $this->intentInbox->drain($worldId);

        $result = step($state, new TickContext(
            tick:    $state->tick + 1,
            seed:    $world->seed,
            intents: $intents,
            ruleset: $this->rulesets->get($world->rulesetVersion),
        ));

        $this->db->transactional(function () use ($worldId, $result) {
            $this->eventStore->append($worldId, $result->events);
            $this->projections->apply($result->events);
        });

        $this->stream->publish($worldId, $result->events);   // SSE

        if ($this->isSnapshotTick($result->state)) {
            $this->snapshots->save($worldId, $result->state);
        }
    }
}
```

Points à respecter :
- **Un seul writer par monde.** Un advisory lock Postgres garantit qu'aucun second processus ne fait avancer le même monde — indispensable avec un déclenchement par cron, où deux exécutions peuvent se chevaucher.
- **Écriture des événements et des projections dans la même transaction**, sinon les clients voient un monde incohérent après un crash.
- **Le tick ne dépend pas de la latence réseau.** Si les intentions arrivent en retard, elles partent au tick suivant. Le monde n'attend personne — c'est ce qui rend le jeu asynchrone tolérable.
- **Le coût de rechargement doit rester borné.** `loadLatestAndReplay` recharge un snapshot à chaque tick : c'est acceptable à 1 tick/h, ça ne l'est plus à 1 tick/s. Si un jour tu veux des ticks rapides, c'est là qu'un worker persistant (Swoole / FrankenPHP) devient nécessaire — l'architecture ne change pas, seul le mode d'exécution du Host change.

Dans le harness, la même fonction `step()` est appelée en boucle dans un seul processus CLI, sans persistance ni SSE. C'est exactement le bénéfice du noyau pur : **un seul code de simulation, deux modes d'exécution.**

---

## 9. Observabilité

À prévoir dès le premier jour, pas après.

**Santé du moteur**
- **Hash du `WorldState` et de la séquence d'événements** à chaque tick — détecte instantanément une régression de déterminisme.
- **Durée par système** — repère la dérive de performance sans profiler.

**Santé du graphe d'événements** — l'*Event Monitor*, indispensable dès qu'on propage par files :
- nombre d'événements par tick, par type, par système émetteur ;
- profondeur de propagation moyenne et maximale ;
- **entités anormalement modifiées** — le signal d'emballement le plus fiable ;
- événements répétitifs (même type, même sujet, ticks consécutifs) ;
- taille des files d'un tick à l'autre : elles ne doivent pas croître sans borne.

Une alerte typique ressemble à ça, et elle vaut mille lignes de log :

```
⚠ Club #4521 — 85 000 événements générés en 1 tick
   émetteur dominant : TransferSystem
   profondeur max : 12
```

**Santé du monde**
- **Compteurs métier à chaque tick** : masse monétaire, inflation du marché, Gini des titres et des revenus, âge moyen, nombre de blessés, distribution des scores.
- **Rapport de fin de saison** généré automatiquement, en texte, lisible d'un coup d'œil.

Ce dernier point est sous-estimé : pendant les six premiers mois, **le rapport de saison en texte est ton unique interface**. Il doit être bon.
