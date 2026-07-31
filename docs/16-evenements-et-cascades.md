# Événements et cascades

> Ce document reprend et prolonge l'analyse de `ressource.md` (discussion Matt × ami), dont les garde-fous anti-emballement sont à l'origine du modèle décrit ici. Les points conservés, adaptés ou écartés sont signalés au fil du texte.

Le tick hybride est décrit dans `13-moteur-de-simulation.md` §2. Ce document traite ce qui circule dedans : quels messages existent, lesquels sont journalisés, comment on empêche un monde de s'emballer.

---

## 1. Taxonomie : trois messages, pas un

L'erreur la plus coûteuse serait de tout appeler « événement ». Trois natures distinctes circulent, avec des cycles de vie opposés.

| | **Fait** | **DecisionRequest** | **Intent** |
|---|---|---|---|
| Sens | « ceci est arrivé » | « quelqu'un doit trancher » | « voici ce que je fais » |
| Temps | passé | présent | futur immédiat |
| Mutable ? | jamais | se résout ou expire | consommé une fois |
| Journalisé ? | ✅ event log | ❌ transitoire | ✅ intent log |
| Émis par | les systèmes | les systèmes | les agents (humains **et** PNJ) |
| Nommage | participe passé | besoin / état | verbe à l'infinitif |
| Exemples | `MatchFinished`, `PlayerInjured`, `ContractSigned` | `ClubNeedsRecruitment`, `CoachUnderPressure`, `PlayerWantsTransfer` | `BidForPlayer`, `OfferContract`, `SackCoach` |

### Le cycle

```
Fait  ──→  DecisionRequest  ──→  délibération d'un agent  ──→  Intent  ──→  Faits
                                  (humain ou PNJ)
```

C'est la boucle qui fait vivre le monde, et elle a une propriété qu'il faut protéger :

> **Un `DecisionRequest` ne déclenche jamais d'action directement.** Il pose une question. Quelqu'un y répond, ou personne — et alors elle expire.

C'est ce qui rend humains et PNJ réellement interchangeables (`11-` §3) : les deux reçoivent les mêmes questions et répondent par les mêmes intentions. Un club dont l'agent humain se déconnecte voit simplement ses `DecisionRequest` reprises par l'IA.

Un `DecisionRequest` porte une **échéance** :

```php
final readonly class ClubNeedsRecruitment implements DecisionRequest
{
    public function __construct(
        public int $clubId,
        public Position $position,
        public int $expiresAtTick,   // sans réponse d'ici là, la question disparaît
        public float $urgency,       // 0..1 — sert à trier ce qu'on montre au joueur
    ) {}
}
```

L'échéance n'est pas un détail : c'est ce qui empêche les questions non traitées de s'accumuler indéfiniment dans un monde qui tourne sans personne.

---

## 2. Seuils d'émission : une mutation n'est pas un événement

**La règle la plus rentable de ce document** (`ressource.md` pt. 3).

```
changement d'état  →  test de pertinence  →  événement éventuel
```

Le réflexe naturel — « toute modification produit un événement » — est un piège arithmétique :

```
FatigueChanged × 8 000 joueurs × 365 ticks  =  ~3 000 000 événements / saison
```

Trois millions d'entrées dans l'event store, dont aucune n'intéresse personne. Le journal devient illisible, les projections coûteuses, et le monde impossible à raconter.

La discipline :

| Fatigue | Émission |
|---|---|
| 42 → 43 | rien. Le composant change, c'est tout. |
| 78 → 81 | `PlayerVeryTired` — franchissement d'un seuil qui **change une décision** |

### Le test de pertinence

Un fait mérite d'être émis s'il satisfait **au moins un** de ces critères :

1. **Il franchit un seuil** qui modifie le comportement d'un système ou d'un agent.
2. **Il est irréversible** (contrat signé, joueur retraité, club relégué).
3. **Il est racontable** — quelqu'un, quelque part, voudra le lire.

Si la réponse est « non » aux trois, le système modifie le composant et se tait.

### Corollaire

Les systèmes qui ont besoin d'un état continu (fatigue, forme, moral) **lisent le composant**, ils ne s'abonnent pas à ses variations. L'abonnement est réservé aux discontinuités.

---

## 3. Contrôle des cascades

### Le mécanisme principal : l'OutQueue

Rappel de `13-` §2 : **un événement n'est jamais traité dans le tick qui l'a produit.** Toute propagation s'étale d'au moins un tick.

Ça suffit à rendre impossible la classe de bugs la plus dangereuse — la boucle infinie intra-tick — sans aucun compteur, aucune limite, aucune heuristique. C'est structurel, pas défensif.

### Les métadonnées de traçage

Chaque événement porte de quoi reconstruire sa généalogie :

```php
final readonly class EventMeta
{
    public function __construct(
        public int     $tick,
        public ?string $causedBy,      // id de l'événement parent
        public string  $correlationId, // partagé par toute une chaîne causale
        public int     $depth,         // distance à l'événement racine
        public string  $emittedBy,     // id du système émetteur
    ) {}
}
```

`correlationId` est ce qui permet de dire, six mois plus tard : *« ce transfert record découle d'un match perdu en octobre »*. C'est la traçabilité causale — le seul vrai avantage du modèle événementiel sur le pipeline pur, et il faut donc l'exploiter.

### ⚠️ La profondeur est une métrique, pas un disjoncteur

`ressource.md` pt. 2 propose de **bloquer** au-delà d'une profondeur limite (exemple donné : coupure à `depth 3`). **On écarte ce comportement**, pour deux raisons.

**1. Il fait perdre des événements en silence.** Tu obtiendras des bugs de la forme « pourquoi la presse n'a pas parlé de ce transfert ? », impossibles à diagnostiquer puisque rien n'a échoué : un compteur a simplement dépassé un seuil, quelque part, trois ticks plus tôt.

**2. Le seuil proposé couperait le gameplay.** `ressource2.md` §5 donne cette chaîne comme l'exemple même de l'émergence réussie :

```
MatchFinished → PlayerFormChanged → TransferInterestIncreased → TransferOfferReceived
   depth 0          depth 1               depth 2                    depth 3  ← coupé
```

L'offre de transfert — un événement de jeu central — tombe exactement sur la ligne de coupe. Les deux documents se contredisent, et c'est l'exemple d'émergence qui a raison : ce n'est pas une cascade pathologique, c'est le monde qui fonctionne.

**Ce qu'on fait à la place :**

| Contexte | Comportement |
|---|---|
| Production | on **mesure** la profondeur, on ne coupe pas |
| Event Monitor | alerte au-delà d'un seuil (§6) — pour investiguer, pas pour bloquer |
| Dev / harness | **assertion qui échoue bruyamment** au-delà de la limite, avec la chaîne `correlationId` complète en sortie |

Une cascade profonde est un **signal de conception**, pas un incident à absorber. On veut la voir en développement et la corriger à la source, pas la masquer en production.

---

## 4. Idempotence : où elle compte vraiment

`ressource.md` pt. 4 a raison sur le principe, mais le situe au mauvais endroit dans notre architecture.

**Dans le noyau, le tick est atomique** : `step()` est une fonction pure, elle réussit entièrement ou pas du tout, et la transaction du Host valide événements et projections ensemble (`13-` §8). Un système ne reçoit jamais deux fois le même événement. L'idempotence n'y est donc pas une nécessité de correction.

**Elle est en revanche critique côté lecture** :

- **Projections** — on les reconstruit régulièrement depuis l'event log complet. Une projection non idempotente produit des doublons à chaque rebuild.
- **Livraison au client** — le flux SSE se reconnecte et peut renvoyer des événements déjà reçus.
- **Migrations** — un script rejoué doit converger.

### La règle qui règle le problème

> **Affectation absolue plutôt qu'incrément relatif.**

```php
// ✗ non idempotent — deux livraisons donnent 60 jours de blessure
$player->injuryDays += $event->duration;

// ✓ idempotent par construction — deux livraisons donnent le même état
$player->setInjury($event->duration);
```

Le second n'a besoin d'aucune vérification, d'aucun registre des événements déjà vus. C'est structurel.

Un garde `if ($event->alreadyProcessed()) return;` est un pis-aller : il exige de tenir un état des événements traités, qui doit lui-même être persisté et cohérent. À réserver aux cas où l'affectation absolue est réellement impossible.

---

## 5. Échelles de temps

Un monde où tout réagit dans la journée paraît mécanique. Le Scheduler (`13-` §3) permet d'étaler les conséquences, et c'est ce qui produit l'impression de vie.

```php
// Au moment de la blessure
$ctx->emit(new PlayerInjured($playerId, $duration));                     // immédiat
$ctx->schedule(new RecoveryUpdate($playerId),     atTick: $t + 7);
$ctx->schedule(new MedicalAssessment($playerId),  atTick: $t + 30);
$ctx->schedule(new RecoveryCompleted($playerId),  atTick: $t + $duration);
```

Motifs récurrents :

| Processus | Échelonnement |
|---|---|
| Blessure | fait immédiat → point d'étape à 7 j → bilan à 30 j → reprise |
| Transfert | intérêt (J1) → observation (J1-J14) → offre (J15) → négociation → conclusion (J30) |
| Contrat | alerte à −180 j → fenêtre de renouvellement à −90 j → expiration |
| Crise sportive | mauvais résultats → pression (2 semaines) → ultimatum (1 mois) → limogeage |

Ce dernier exemple est aussi une **boucle de régulation** : la pression qui monte est la contre-réaction du succès accumulé. Voir `14-algorithmes.md` §7, qui traite les boucles voulues et leur amortissement.

**Règle de choix du canal** (rappel de `13-` §2) : composants pour ce qui doit se résoudre le jour même, OutQueue par défaut pour tout le reste, Scheduler dès qu'une échéance est connue à l'avance.

---

## 6. Event Monitor

Système de surveillance en lecture seule sur le flux (`ressource.md` pt. 7). Il ne modifie rien, il observe — et il devient indispensable dès qu'on propage par files, parce qu'un emballement ne se manifeste pas par une erreur mais par une lenteur et un journal illisible.

Métriques par tick :

| Métrique | Ce qu'elle révèle |
|---|---|
| Événements / tick, par type | volume anormal, seuil d'émission oublié |
| Événements / tick, par système émetteur | quel système part en vrille |
| Profondeur moyenne et max | cascade qui s'allonge |
| **Entités les plus modifiées** | le signal le plus fiable d'emballement |
| Événements répétitifs (même type + même sujet, ticks consécutifs) | boucle non amortie |
| Taille des files d'un tick à l'autre | croissance non bornée → le monde diverge |

Seuils d'alerte à calibrer sur les premiers runs du harness, pas à deviner d'avance.

```
⚠ Club #4521 — 85 000 événements générés en 1 tick
   émetteur dominant : TransferSystem
   profondeur max : 12
   correlationId racine : 8f3a...
```

Le `correlationId` racine permet de remonter directement à l'événement déclencheur. Sans lui, l'alerte dit qu'il y a un problème mais pas où.

---

## 7. Anti-patterns

| Anti-pattern | Pourquoi c'est grave | À la place |
|---|---|---|
| Un système en appelle un autre | détruit l'ordonnancement, la testabilité et le déterminisme | émettre un événement |
| Traiter un événement dans le tick qui l'a produit | rouvre les cascades infinies | OutQueue |
| Émettre à chaque changement de composant | noie l'event store, rend le monde impossible à raconter | test de pertinence (§2) |
| Bloquer silencieusement sur la profondeur | bugs indiagnosticables, gameplay amputé | mesurer, alerter, assertion en dev |
| `+=` dans un handler | casse le rebuild des projections | affectation absolue |
| Journaliser les `DecisionRequest` | pollue l'histoire du monde avec des questions sans réponse | transitoire |
| `EventBus` qui appelle ses abonnés dans l'ordre d'inscription | non déterministe dès qu'un conteneur DI est impliqué | ordre du pipeline (`13-` §4.6) |
| Une file non triée à la fusion | non déterminisme silencieux | ordre total (`13-` §4.5) |

---

## 8. Le test de compréhension

Ce document a rempli son office si, devant n'importe quel événement du projet, tu peux répondre sans hésiter :

1. **Est-ce un Fait, une DecisionRequest ou une Intent ?**
2. **Passe-t-il le test de pertinence ?**
3. **Est-il journalisé, et dans quel journal ?**
4. **Quand sera-t-il traité — tick suivant, ou à une échéance ?**
5. **Qui décide, et que se passe-t-il si personne ne répond ?**
