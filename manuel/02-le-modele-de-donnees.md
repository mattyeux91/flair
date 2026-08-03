# 02 — Le modèle de données (ECS)

## 1. Le réflexe naturel, et pourquoi il échoue ici

Si on demande à un développeur PHP de modéliser un monde de football, il écrit :

```php
class Player {
    private string $name;
    private int $age;
    private Club $club;
    private Contract $contract;
    public function train(): void { /* ... */ }
    public function retire(): void { /* ... */ }
}
```

C'est propre, c'est du DDD, et ça marche très bien pour une application de gestion. Ça
échoue pour une simulation persistante, pour trois raisons concrètes :

1. **Les rôles se cumulent et changent.** Un joueur devient entraîneur, puis président.
   Un club a une ville, une ville a un pays. Avec des classes, on finit en héritage
   multiple, en `instanceof`, ou en `Person` de 250 champs dont 200 sont `null`.
2. **Les traitements sont transversaux.** « Faire vieillir tout le monde » traverse
   joueurs, entraîneurs, dirigeants. Avec des méthodes sur des classes, cette logique
   se disperse en autant d'endroits qu'il y a de types.
3. **L'ordre de traitement devient invisible.** Si `$player->train()` appelle
   `$club->facilities()->modifier()`, l'ordre d'exécution du monde est enfoui dans une
   chaîne d'appels. On ne peut ni le lire, ni le vérifier, ni le rendre déterministe.

## 2. ECS

> **Définition — ECS (Entity Component System).** Un modèle où :
> - une **entité** est un simple identifiant, sans données ni comportement ;
> - un **composant** est un paquet de données pures attaché à une entité, sans comportement ;
> - un **système** est du comportement pur, qui traite en masse toutes les entités portant
>   une certaine combinaison de composants.
>
> Origine : moteurs de jeu (Unity DOTS, Bevy, EnTT, flecs). Lecture d'introduction :
> [ECS FAQ](https://github.com/SanderMertens/ecs-faq).

Traduit dans ce projet :

```
   ENTITÉ                COMPOSANTS                         SYSTÈMES
   (un int)              (des données readonly)             (du comportement)

     42     ──porte──►   Person{nom, naissance}         ┌─ PlayerDevelopmentSystem
                         PlayerPotentials{ceiling,...}  │  itère toutes les entités
                         PlayerPhysicalSkills{pace,...} │  qui portent PlayerPotentials
                         PlayerTechnicalSkills{...}     │  + Person + des compétences
                         PlayerMentalSkills{...}        │
                         SquadMembership{clubId: 7}     └─ ...
                         Contract{clubId: 7, salaire}

      7     ──porte──►   Club{nom}
                         Facilities{quality: 1.3}
                         Finances{balanceCents}
                         SeasonIncome{cents}
```

**L'entité 42 n'est « un joueur » nulle part dans le code.** Il n'existe aucune classe
`Player`, aucune interface, aucun champ `type`. « Joueur » est le nom informel qu'on donne
à une entité qui porte des compétences, `Person` et `PlayerPotentials`. Le jour où on lui
retire ses compétences (`RetirementSystem`), elle cesse d'être un joueur sans cesser
d'exister : elle garde son `Person`, et pourra porter demain un composant `CoachRole`.

> **Définition — archétype.** L'ensemble des types de composants que porte une entité.
> Changer de rôle = changer d'archétype = ajouter/retirer des composants. Jamais
> détruire et recréer l'entité, ce qui casserait toutes les références à son identifiant.

## 3. Les entités

`Core\Ecs\EntityIdAllocator`, dans son intégralité :

```php
final class EntityIdAllocator
{
    public function __construct(private int $next = 1) {}

    public function allocate(): int { return $this->next++; }
}
```

Un compteur. C'est tout, et c'est suffisant :

- **Jamais de réutilisation.** Rien n'est jamais « libéré », donc aucune collision n'est
  possible par construction. Pas de génération/version d'identifiant comme dans les ECS
  de jeux temps réel, où la réutilisation d'identifiants est une optimisation mémoire
  nécessaire — ici, la mémoire n'est pas le problème.
- **`0` n'est jamais alloué.** Il sert de sentinelle « aucune entité ».
- **Monotone**, donc trier par identifiant, c'est trier par ordre de création. Cette
  propriété est utilisée partout comme départage déterministe.

## 4. Les composants : des colonnes, pas des objets

Chaque type de composant a sa propre **colonne** : une table qui associe un identifiant
d'entité à une valeur.

```
   ComponentStore<Person>            ComponentStore<Contract>
   ┌──────┬────────────────┐         ┌──────┬────────────────────┐
   │  12  │ Person{...}    │         │  12  │ Contract{club:7}   │
   │  42  │ Person{...}    │         │  42  │ Contract{club:7}   │
   │  43  │ Person{...}    │         │  99  │ Contract{club:3}   │
   │  99  │ Person{...}    │         └──────┴────────────────────┘
   └──────┴────────────────┘
                                     43 n'a pas de contrat :
                                     il est simplement absent
                                     de cette colonne.
```

`Core\Ecs\ComponentStore` est un `array<int, T>` avec trois opérations : `set()`, `get()`,
`remove()`. Et une quatrième, la plus importante :

```php
public function entities(): array
{
    $ids = array_keys($this->components);
    sort($ids);                       // ← ceci n'est pas cosmétique

    return $ids;
}
```

> **⚠️ Le piège n°1 de la reproductibilité.** Un tableau associatif PHP conserve l'ordre
> d'insertion. Itérer dessus donne donc un ordre qui dépend de *l'histoire* du monde, pas
> de son *état*. Deux mondes identiques mais construits dans un ordre différent
> produiraient des tirages aléatoires différents — sans la moindre erreur, juste une
> divergence silencieuse. Le `sort()` élimine le problème à la racine : **toute itération
> du monde se fait par identifiant croissant.**

### Composants immuables

Tous les composants sont `final readonly`. Un système ne modifie jamais un composant en
place ; il en construit un neuf et remplace :

```php
// PlayerDevelopmentSystem
$ctx->write(PlayerPhysicalSkills::class)->set($entityId, new PlayerPhysicalSkills(
    pace:    $this->nextValue($physical->pace, ...),
    stamina: $this->nextValue($physical->stamina, ...),
    // ...
));
```

Le bénéfice n'est pas idéologique : un composant immuable ne peut pas être modifié par
un système qui l'a seulement *lu*. Combiné aux gardes du [chapitre 03](03-le-tick-et-le-pipeline.md),
ça rend l'invariant « un seul système écrit un composant donné » mécaniquement vérifiable.

### Pas de balayage global

`WorldState` **n'expose volontairement aucune méthode « toutes les entités du monde »**.
Une requête se fait toujours en partant d'une colonne :

```php
foreach ($ctx->read(PlayerPotentials::class)->entities() as $entityId) {
    $person = $ctx->read(Person::class)->get($entityId);
    if ($person === null) { continue; }        // n'est pas un joueur : on passe
    // ...
}
```

C'est l'idiome ECS standard : on part de la colonne la plus discriminante, et on
intersecte à la lecture. Coût : une lecture de table de hachage par composant, par entité.
À l'échelle du projet (quelques milliers d'entités), c'est gratuit.

## 5. Les singletons

Certaines données ne sont attachées à aucune entité : la masse monétaire totale du monde,
la phase de saison, l'inflation du marché. Elles vivent dans `WorldState::$singletons`,
adressées **par type** :

```php
$mass = $ctx->singleton(MonetaryMass::class) ?? new MonetaryMass();
$ctx->setSingleton(new MonetaryMass($mass->totalInjectionsCents + $injected, ...));
```

Un seul singleton existe aujourd'hui : `Football\Singletons\MonetaryMass`, qui accumule
le total des injections et des puits monétaires.

**La frontière à ne pas rater.** Une donnée qui varierait par pays, par région ou par
compétition n'est **pas** un singleton — c'est un composant d'une entité. Le climat
économique appartient à un pays ; la météo appartient à une région. Le test est simple :
*s'il peut y en avoir deux, c'est un composant.*

## 6. `WorldState` : l'assemblage

```php
final class WorldState
{
    private array $componentStores = [];   // class-string -> ComponentStore
    private array $singletons = [];        // class-string -> objet

    public function __construct(
        private EntityIdAllocator $entityIds = new EntityIdAllocator(),
        private Scheduler $scheduler = new Scheduler(),
        private OutQueue $outQueue = new OutQueue(),
    ) {}
}
```

La présence du `Scheduler` et de l'`OutQueue` **dans** le `WorldState` peut surprendre :
ce sont des files de messages, pas des données du monde. Elles sont là pour une raison
mécanique et une raison de durabilité :

- **Mécanique :** `step()` ne prend que `WorldState` + `TickContext`. Rien d'autre ne
  pourrait faire survivre ces files d'un appel à l'autre.
- **Durabilité :** un événement seulement *planifié* n'a émis aucun Fait. Il n'existe donc
  nulle part dans le journal d'événements. Un snapshot qui ignorerait le `Scheduler`
  perdrait silencieusement, par exemple, tous les matchs restants de la saison en cours.

## 7. Le catalogue actuel

### Composants — les acteurs

| Composant | Porté par | Contenu | Écrit par |
|---|---|---|---|
| `Person` | toute personne | nom, date de naissance | `YouthIntakeSystem` (création) |
| `PlayerPotentials` | joueur | `ceiling`, 3 âges de pic, `growthRate`, `fragility` | `YouthIntakeSystem` (création) |
| `PlayerPhysicalSkills` | joueur | pace, stamina, strength, reflexes | `PlayerDevelopmentSystem` |
| `PlayerTechnicalSkills` | joueur | technique, passing, finishing, defending, positioning, handling, distribution | `PlayerDevelopmentSystem` |
| `PlayerMentalSkills` | joueur | vision, composure, leadership, discipline, command | `PlayerDevelopmentSystem` |
| `SquadMembership` | joueur | `clubId` | `SquadSystem` |
| `Contract` | joueur | `clubId`, salaire hebdo, `expiresOn` | `SquadSystem` |
| `TrainingEffect` | joueur | multiplicateur `[0.5, 2.0]` | `TrainingSystem` |

### Composants — le décor

| Composant | Porté par | Contenu | Écrit par |
|---|---|---|---|
| `Club` | club | nom | (genesis) |
| `Facilities` | club | `quality` ∈ `[0.5, 2.0]` | `FacilitiesSystem` |
| `Finances` | club | `balanceCents` (peut être négatif) | `FinanceSystem` |
| `SeasonIncome` | club | revenu de la dernière saison | `FinanceSystem` |
| `Competition` | compétition | nom | (genesis) |
| `Standings` | compétition | table de `StandingsEntry` par `clubId` | `CompetitionSystem` |
| `Fixture` | rencontre | competitionId, domicile, extérieur, journée | `CalendarSystem` |
| `MatchResult` | rencontre | idem + score | `MatchSystem` |

Deux choses à remarquer dans ce tableau :

1. **Chaque composant a exactement un writer.** Ce n'est pas une convention de style,
   c'est un invariant vérifié mécaniquement (`Football\PipelineInvariantsTest`). Il est
   ce qui rend l'ordre du pipeline dérivable — voir [chapitre 03](03-le-tick-et-le-pipeline.md).
2. **`Contract` et `SquadMembership` vivent sur le joueur et pointent vers le club**, pas
   l'inverse. Il n'y a pas de composant `Squad` côté club listant ses joueurs. Une
   relation unidirectionnelle n'a pas de cohérence bidirectionnelle à maintenir ; le prix
   est qu'énumérer l'effectif d'un club demande de balayer la colonne `SquadMembership`
   (ce que fait `MatchSystem::ratings()`).

### Un cas instructif : `MatchResult` sur l'entité `Fixture`

Il n'existe pas d'« entité résultat ». Un match programmé est une entité qui porte
`Fixture` ; quand il est joué, `MatchSystem` lui ajoute `MatchResult`. Deux composants,
une entité, deux writers différents — et un troisième système, `CalendarSystem`, qui
**retire les deux** en fin de saison.

C'est l'illustration la plus nette de « qui crée détruit » : `CalendarSystem` crée les
rencontres et les dépouille un an plus tard. Sans ce nettoyage, les rencontres
s'accumulaient **sans borne** : 1 320 `Fixture` après dix ans sur un monde de douze
clubs, pour 345 personnes vivantes. À l'échelle cible (290 clubs), ~200 000 entités
mortes en vingt ans, sérialisées dans chaque snapshot.

Le raisonnement qui autorise à jeter : **l'histoire du monde vit dans le journal
d'événements, pas dans l'état.** `MatchPlayed` est un Fait journalisé ; garder les saisons
mortes dans le `WorldState` reviendrait à dupliquer le journal dans chaque snapshot.

## 8. L'échelle 1-100

Toutes les compétences vivent sur une échelle **absolue et mondiale** :

```
    1        30         50         70         85        95      99
    |─────────|──────────|──────────|──────────|─────────|───────|
             amateur   pro médian  titulaire  inter-   une poignée
                       (toutes     1re div.   national  de joueurs
                        divisions)                       vivants
```

Cette échelle est un **contrat sémantique**, pas une simple normalisation. Si un calibrage
mettait 20 % de la population au-dessus de 85, ce ne serait pas « un monde généreux » :
ce serait la destruction du sens de l'échelle — 85 cesserait de vouloir dire
« international ». C'est le garde-fou explicite documenté sur
`YouthIntakeBalance::$ceilingMin/$ceilingMax`.

Corollaire : l'échelle est universelle, **les distributions ne le sont pas**. Les bornes
de tirage d'une recrue (`[55, 95]` aujourd'hui) ne décrivent pas « le talent en général »
mais *la tranche dans laquelle recrute un club de première division*.

---

**Suite :** [03 — Le tick et le pipeline](03-le-tick-et-le-pipeline.md)
</content>
</invoke>
