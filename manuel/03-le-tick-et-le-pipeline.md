# 03 — Le tick et le pipeline

C'est le chapitre central. Tout le reste en découle.

## 1. Un système

> **Définition — système.** Une unité de comportement qui traite le monde en masse.
> Il ne possède aucun état entre deux ticks : tout son état est dans le `WorldState`.

L'interface complète (`Core\Pipeline\System`) :

```php
interface System
{
    public function id(): string;

    public function reads(): array;          // composants lus
    public function writes(): array;         // composants mutés  (set)
    public function removes(): array;        // composants retirés (remove)
    public function creates(): array;        // composants posés sur une entité créée ici

    public function subscribesTo(): array;   // types d'événements écoutés

    public function handle(DomainEvent $event, SystemContext $ctx): void;  // réactif
    public function update(SystemContext $ctx): void;                      // périodique
}
```

Un système est **réactif** (il réagit à des événements via `handle()`), **périodique** (il
fait quelque chose chaque tick via `update()`), ou les deux. Dans le pipeline actuel :

| Système | Réactif | Périodique |
|---|---|---|
| `FacilitiesSystem` | ✔ `SeasonConcluded`, `ClubInvestedInFacilities` | — |
| `YouthIntakeSystem` | — | ✔ jour 180 |
| `SquadSystem` | ✔ `ContractSigned`, `ContractExpired`, `PlayerRetired` | — |
| `TrainingSystem` | — | ✔ chaque tick |
| `RetirementSystem` | — | ✔ chaque tick |
| `FinanceSystem` | ✔ `SeasonConcluded` | ✔ jour de paie |
| `PlayerDevelopmentSystem` | — | ✔ chaque tick |
| `CalendarSystem` | — | ✔ jour 0 |
| `MatchSystem` | ✔ `FixtureKickoff` | — |
| `CompetitionSystem` | ✔ `FixtureKickoff`, `SeasonStarted`, `SeasonEnded` | — |
| `ContractSystem` | — | ✔ jour 180 |

## 2. Anatomie d'un tick

`Core\Pipeline\Pipeline::tick()`, dans son intégralité :

```php
public function tick(WorldState $world, int $tick, int $worldSeed, Ruleset $ruleset, array $intents): void
{
    $scheduler = $world->scheduler();
    $outQueue  = $world->outQueue();
    $seq       = new SeqCounter();

    $incoming = [...$scheduler->drainDueBy($tick), ...$outQueue->drain()];   // ①

    foreach ($this->systems as $index => $system) {                          // ②
        $ctx = new SystemContext(/* ... */);

        foreach ($incoming as $event) {                                      // ③
            foreach ($system->subscribesTo() as $type) {
                if ($event instanceof $type) {
                    $system->handle($event, $ctx);
                    continue 2;
                }
            }
        }

        $system->update($ctx);                                               // ④
    }
}
```

**① Le lot d'événements est figé une fois, avant que le moindre système ne s'exécute.**
C'est *la* ligne qui rend les cascades bornées. Tout ce qu'un système émet pendant ce tick
part dans l'`OutQueue`, qui vient d'être vidée : impossible que ça rejoigne `$incoming`.

Deux sources concaténées, chacune déjà triée par sa propre règle : les échéances du
`Scheduler` dont la date est arrivée, puis les Faits émis au tick précédent.

**② Les systèmes s'exécutent dans l'ordre du pipeline**, séquentiellement. Pas de
parallélisme, pas de priorités dynamiques. `$index` sert de clé de tri pour les
événements émis (voir [ch. 04](04-messages-et-files.md)).

**③ Chaque système rejoue le même `$incoming`** et ne traite que ce qui l'intéresse. Un
même événement est donc vu par tous ses souscripteurs, **dans l'ordre du pipeline** :
`FixtureKickoff` est traité par `MatchSystem` puis par `CompetitionSystem`, et c'est ce
qui permet au second de lire le `MatchResult` que le premier vient d'écrire.

Le `continue 2` signifie qu'un système traite un événement **une seule fois**, même s'il
correspond à deux de ses souscriptions.

**④ `update()` passe après les `handle()` du même système.** Un système qui fait les deux
voit donc d'abord les conséquences de ce qu'il a réagi.

## 3. Les deux canaux de communication

**Un système n'appelle jamais un autre système.** Jamais directement, jamais
indirectement. Il n'a que deux moyens de se faire entendre :

```
   CANAL 1 — le composant, dans le même tick
   ────────────────────────────────────────────────────────────────
   Système A ──écrit──► [Composant] ──lu──► Système B
     (tôt dans                                (plus loin dans
      le pipeline)                             le pipeline)

   Latence : 0. Contrainte : A doit passer avant B, donc l'ordre
   du pipeline devient une dépendance dure.

   Exemple : TrainingSystem écrit TrainingEffect,
             PlayerDevelopmentSystem le lit le même jour.


   CANAL 2 — l'événement, au tick suivant
   ────────────────────────────────────────────────────────────────
   Système A ──émet──► [OutQueue] ─── fin du tick N ───┐
                                                        │
   ┌────────────────── début du tick N+1 ───────────────┘
   │
   └──► [$incoming] ──handle()──► Système B

   Latence : 1 tick. Contrainte : aucune. A et B peuvent être
   dans n'importe quel ordre.

   Exemple : FinanceSystem émet ClubInvestedInFacilities,
             FacilitiesSystem le traite le lendemain.
```

**En cas de doute, c'est le canal 2.** Le canal 1 crée une contrainte d'ordre permanente
entre deux systèmes ; le canal 2 n'en crée aucune. On ne prend le canal 1 que quand la
résolution *le jour même* est réellement nécessaire (un match joué doit alimenter le
classement du jour, pas celui de demain).

### Le mur qui force le canal 2

Le cas le plus instructif du code. `ContractSystem` décide des renouvellements. Pour ça
il doit :

- lire les compétences (écrites par `PlayerDevelopmentSystem`) et `Finances` (écrit par
  `FinanceSystem`) → il doit passer **après** eux ;
- écrire `SquadMembership`, que `TrainingSystem` et `MatchSystem` lisent → il devrait
  passer **avant** eux.

Or `TrainingSystem` passe avant `PlayerDevelopmentSystem`. **Aucun ordre de pipeline ne
satisfait les deux contraintes.** La réponse n'est pas un compromis, c'est un découpage :

```
   tick N                                    tick N+1
   ─────────────────────────────────         ─────────────────────────────
   ContractSystem (dernier du pipeline)      SquadSystem (2e du pipeline)
     lit tout ce qui est à jour               applique la décision :
     décide                                   écrit Contract + SquadMembership
     émet ContractSigned ──────────────────►  avant TrainingSystem et MatchSystem
```

**Décider tard, appliquer tôt.** La même forme se retrouve pour
`FinanceSystem` → `ClubInvestedInFacilities` → `FacilitiesSystem`. Ce n'est pas un motif
qu'on a choisi par élégance : c'est ce que la structure impose dès que les contraintes de
lecture et d'écriture se croisent.

## 4. Les déclarations sont opposables

Un système déclare ce qu'il lit et écrit. Ces déclarations ne sont **pas** de la
documentation : elles sont vérifiées à l'exécution, et l'ordre du pipeline s'en déduit.

`SystemContext` est la seule porte d'accès au monde pour un système, et il oppose les
déclarations à chaque appel :

```php
public function read(string $componentType): ComponentReader
{
    if (!$this->access->mayRead($componentType)) {
        throw UndeclaredAccessException::read($this->access->systemId, $componentType);
    }

    return $this->world->components($componentType);
}
```

Le type de retour compte autant que le contrôle : `read()` rend un `ComponentReader`, une
interface qui **n'a physiquement pas de `set()`**. Écrire à travers un handle de lecture
n'est donc pas une exception au premier tick, c'est une erreur d'analyse statique attrapée
par PHPStan (niveau max, obligatoire sur `kernel`).

Symétriquement, `write()` rend un `GuardedComponentWriter` qui n'a **ni `get()` ni
`entities()`** : un système ne peut pas lire à travers son handle d'écriture, sans quoi
`reads()` redeviendrait décoratif — et c'est de `reads()` que se déduisent les arêtes du
graphe de dépendances.

### Pourquoi quatre listes et pas deux

| Déclaration | Autorise | Pourquoi séparée |
|---|---|---|
| `reads()` | `read()`, `singleton()` | Source des arêtes du graphe de dépendances |
| `writes()` | `set()` sur n'importe quelle entité | L'invariant « un seul writer » porte sur celle-ci |
| `removes()` | `remove()` | Retirer n'est pas muter : un remover et un writer distincts peuvent coexister |
| `creates()` | `set()` **uniquement sur une entité créée par ce système dans ce tick** | Ne peut pas invalider une lecture déjà faite |

Le cas `creates()` mérite un mot. `YouthIntakeSystem` pose un `Contract` sur ses recrues ;
`SquadSystem` est le writer de `Contract`. Deux systèmes touchent le même composant sans
violer l'invariant du writer unique, **parce qu'ils ne touchent jamais la même entité** :
le créateur ne pose ses composants que sur une entité qui n'existait pas quand qui que ce
soit a itéré.

Cette moitié de phrase — « créée par ce système dans ce tick » — serait une convention sur
l'honneur sans `Core\Pipeline\CreatedEntities`, qui enregistre les entités rendues par
`SystemContext::createEntity()` et que `GuardedComponentWriter::set()` consulte :

```php
if ($this->access->requiresCreatedEntity($this->componentType) && !$this->created->contains($entity)) {
    throw UndeclaredAccessException::setOnForeignEntity(/* ... */);
}
```

La portée tombe juste sans effort : `Pipeline::tick()` construit un `SystemContext` par
système et par tick, donc la durée de vie de `CreatedEntities` **est** « ce système, ce tick ».

## 5. L'ordre du pipeline est dérivé, pas écrit

Voilà la partie la plus intéressante du noyau générique.

L'ordre d'exécution des systèmes est une dépendance dure (canal 1). L'écrire à la main,
c'est le laisser se désynchroniser en silence : ça s'est produit dans ce projet, où la
liste des systèmes était recopiée dans quatre fichiers, dont deux se déclaraient chacun
« seule source de vérité ».

Aujourd'hui la liste est écrite **une fois** (`Football\FootballPipeline::declaration()`),
et l'ordre en est **déduit** par `Core\Pipeline\SystemGraph`.

### La règle d'arête

```
   Une arête A ──► B existe dès que B lit un composant que A écrit ou retire.
   Lire : « B doit passer après A ».
```

Exemple concret :

```
   TrainingSystem ──writes──► TrainingEffect ──reads──► PlayerDevelopmentSystem
   donc :  TrainingSystem ──► PlayerDevelopmentSystem
```

Deux subtilités :

- **`creates()` n'engendre aucune arête.** Un créateur ne peut pas invalider une lecture
  déjà faite (l'entité n'existait pas). L'exclure du graphe est donc légitime — et cette
  légitimité est garantie à l'exécution par `CreatedEntities`, pas seulement supposée.
- **Les arêtes réflexives sont ignorées.** `FacilitiesSystem` lit et écrit `Facilities` ;
  un système ne peut pas passer avant lui-même.

### Le tri : Kahn, avec départage stable

> **Définition — tri topologique.** Ordonner les sommets d'un graphe orienté sans cycle
> de sorte que chaque arête aille de la gauche vers la droite. Algorithme de Kahn (1962) :
> on retire répétitivement un sommet sans prédécesseur restant. Voir
> [ch. 99](99-ressources-et-glossaire.md).

L'implémentation (`SystemGraph::kahn()`) suit l'algorithme classique avec **une décision
qui compte** : parmi les systèmes prêts, on prend celui qui vient en **premier dans la
liste déclarée**.

```php
foreach (array_keys($systems) as $index) {
    if (!isset($placed[$index]) && $inDegree[$index] === 0) {
        $next = $index;
        break;                    // ← premier prêt dans l'ordre déclaré
    }
}
```

Pourquoi pas un départage alphabétique par `id()` ? Parce que **le monde ne doit pas
dépendre des noms**. Un renommage de système changerait l'ordre, donc l'ordre des tirages
aléatoires, donc l'histoire de tous les mondes existants. Avec le départage stable :

- là où une dépendance tranche, elle tranche ;
- là où rien ne tranche, un système reste où l'auteur l'a mis ;
- ajouter un système revient à le déposer n'importe où : les dépendances le placent, le
  reste ne bouge pas.

Un cycle lève une `PipelineCycleException` **au montage**, pas au premier tick, avec un
exemple d'arête bloquante dans le message plutôt qu'une liste abstraite.

### Ce que le graphe ne capture pas

Uniquement les dépendances **par composant**. Deux systèmes abonnés au même événement et
dont l'ordre relatif compte ne sont pas couverts : `subscribesTo()` ne dit rien de
l'ordre entre souscripteurs.

Aujourd'hui `MatchSystem` passe bien avant `CompetitionSystem` — mais grâce à l'arête
`MatchResult`, pas grâce à leur souscription commune à `FixtureKickoff`. C'est une
coïncidence heureuse, pas une couverture. Si le cas se présente un jour, il faudra une
déclaration d'ordre explicite.

## 6. L'ordre obtenu, et ce qu'il raconte

```
  1. FacilitiesSystem          écrit Facilities  ──┐
  2. YouthIntakeSystem         crée les recrues    │ lu par 2 et 4
  3. SquadSystem               applique le mercato │
  4. TrainingSystem            écrit TrainingEffect ─┐
  5. RetirementSystem          retire les compétences│
  6. FinanceSystem             écrit Finances       │ lu par 7
  7. PlayerDevelopmentSystem   écrit les compétences ┘
  8. CalendarSystem            crée les Fixture
  9. MatchSystem               écrit MatchResult ──┐
 10. CompetitionSystem         écrit Standings     │ lu par 10
 11. ContractSystem            décide le mercato   ┘  lit tout ce qui précède
```

La structure d'ensemble se lit d'un coup d'œil :

- **`SquadSystem` (3) et `ContractSystem` (11) encadrent tout le reste.** Le décideur est
  dernier, là où tout est à jour ; l'applicateur est presque premier, avant tous les
  lecteurs de l'effectif. C'est le motif « décider tard, appliquer tôt » du §3.
- **`FacilitiesSystem` ouvre**, parce que `YouthIntakeSystem` et `TrainingSystem` lisent
  `Facilities`. C'est aussi pourquoi il ne peut **pas** lire `Finances` (écrit bien plus
  loin) et dépend d'un événement pour connaître l'investissement d'un club.
- **`CompetitionSystem` (10) ferme la chaîne du jour de match** : `CalendarSystem` (8)
  programme, `MatchSystem` (9) joue, `CompetitionSystem` (10) classe — le tout dans le
  même tick, par le canal 1.

## 7. Ce que le noyau ne fait pas

Utile à savoir pour ne pas chercher :

- **Pas de parallélisme.** Les systèmes s'exécutent en séquence. Le graphe de dépendances
  permettrait techniquement de paralléliser les systèmes indépendants ; ce serait un
  gain nul (le CPU n'est pas le facteur limitant) contre un risque de non-déterminisme
  majeur.
- **Pas de découverte automatique des systèmes.** Scanner `Football/Systems/` serait de
  l'I/O — interdite. Mais la vraie raison est ailleurs : un monde est épinglé à
  `(kernelVersion, rulesetVersion)`. Avec la découverte automatique, déposer un fichier
  changerait le comportement de **tous les mondes existants** sans qu'aucun diff ne le
  montre.
- **Pas de priorité, pas de fréquence par système.** Un système qui ne veut agir qu'un
  jour par an teste `$ctx->tick % 365` lui-même, en une ligne. Un ordonnanceur de
  fréquences serait une abstraction pour un seul motif.
- **`TickContext::$intents` n'a aucun consommateur.** La plomberie existe, la boucle de
  jeu humaine n'est pas encore branchée.

---

**Suite :** [04 — Messages et files](04-messages-et-files.md)
</content>
</invoke>
