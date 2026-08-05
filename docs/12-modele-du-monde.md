# Modélisation du monde

## 1. Pourquoi ECS et pas un modèle objet DDD classique

Le réflexe naturel est de modéliser `Joueur`, `Entraîneur`, `Président` comme des classes riches, éventuellement avec héritage depuis `Personne`. **C'est un piège dans ce projet précis**, pour une raison très concrète :

> Dans un monde persistant de football, une personne change de nature au cours de sa vie.

Un joueur prend sa retraite, devient entraîneur adjoint, puis entraîneur principal, puis président. Un agent est aussi un ancien joueur. Un supporter devient actionnaire. En héritage, c'est ingérable (on ne change pas la classe d'un objet). En composition d'objets, ça devient un sac de nullables.

En **ECS**, c'est trivial : on retire les composants de compétences du joueur, on ajoute `CoachSkills`. L'entité, son identité, son historique et ses relations sont intacts.

Les autres bénéfices tombent ensuite :

| Bénéfice | Mécanisme |
|---|---|
| **OCP réel** | Une nouvelle mécanique = un nouveau composant + un nouveau système. On ne touche à rien d'existant. |
| Sérialisation triviale | Les composants sont des données plates, sans comportement, sans référence circulaire. Snapshots et event sourcing deviennent simples. |
| Requêtes efficaces | « tous les joueurs sous contrat et blessés » = intersection de trois colonnes. |
| Testabilité | Un système est une fonction sur des données. Aucun mock. |

**On ne jette pas le DDD pour autant** : on en garde le *langage ubiquitaire* (les noms des composants et des événements sont ceux du métier football) et les *invariants*. On abandonne juste les agrégats à comportement, qui ne conviennent pas ici.

### ⚠️ Il n'existe aucun sous-type `Player`, `Club` ou `City`

C'est le point où l'ECS se trahit le plus souvent, y compris dans des schémas qui se déclarent ECS (le diagramme de `ressource.md` fait cohabiter `Entity → Player | Club | City` en héritage *et* des composants à côté — les deux sont incompatibles).

> Une entité est **un entier**. Rien d'autre. « Joueur » n'est pas un type : c'est le nom informel qu'on donne à une entité qui porte ses composants de compétences (`PlayerPhysicalSkills`, `PlayerTechnicalSkills`, `PlayerMentalSkills`, §5) + `Contract` + `Fitness`.

Ce n'est pas du purisme. C'est exactement ce qui permet le cas qui a motivé le choix de l'ECS : quand un joueur devient entraîneur, on retire ses composants de compétences et on ajoute `CoachSkills`. Avec un sous-type `Player`, il faudrait détruire l'entité et en recréer une — en perdant son identité, son historique de carrière et toutes ses relations. Avec l'ECS, tout est conservé, y compris les vingt ans de faits déjà journalisés à son sujet.

Conséquence pratique : `Player` n'existe que comme **archétype** — une liste de composants documentée qui sert au worldgen et aux requêtes, jamais comme classe.

### Le prix à payer, honnêtement

L'ECS disperse les invariants. En DDD, `Contrat.resilier()` protège ses règles. En ECS, rien n'empêche un système d'écrire n'importe quoi dans `Contract`.

Contre-mesures obligatoires :
1. **Les composants sont en lecture seule hors du système propriétaire.** Chaque composant a un système propriétaire déclaré ; les autres le lisent.
2. **Invariants vérifiés en fin de tick** en mode dev (`assertWorldInvariants`) : pas de joueur avec deux contrats actifs, pas d'effectif > taille max, somme monétaire cohérente, etc.
3. **Les mutations passent par des helpers nommés métier** (`signContract(world, ...)`) plutôt que par affectation brute.

---

## 2. Structure ECS

```php
// EntityId = int opaque, stable, jamais réutilisé

// Un composant = données plates, zéro comportement métier
final readonly class Contract
{
    public function __construct(
        public int    $clubId,
        public Money  $wagePerWeek,
        public SimDate $expiresOn,
        public ?Money $releaseClause = null,
        public ?int   $agentId = null,
    ) {}
}

/**
 * Stockage : une "colonne" par type de composant.
 * @template T
 */
final class ComponentStore
{
    public function get(int $entity): mixed;
    public function set(int $entity, mixed $value): void;
    public function remove(int $entity): void;

    /** @return list<int> TOUJOURS trié par id — déterminisme */
    public function entities(): array;
}
```

> Les composants sont `readonly` : un système ne modifie pas un composant, il en écrit un nouveau via `set()`. Ça rend les mutations explicites et traçables, et ça élimine les effets de bord par référence partagée.

**Point critique de déterminisme** : toute itération sur des entités doit être **triée par `EntityId`**, jamais dans l'ordre d'insertion d'une `Map`. C'est la source n°1 de non-reproductibilité silencieuse.

---

## 3. Catalogue des entités et composants

### Entités structurelles (le décor)

| Entité | Composants principaux |
|---|---|
| **Pays** | `Country { name, code, footballCulture, wealthIndex, taxRate }`, `EconomicClimate { growth, tvRights, attractiveness }` |
| **Région** | `Region { countryId, name }` — l'échelon qui porte le climat et les rivalités de proximité —, `Weather { conditions }` |
| **Ville** | `Location { regionId, coords }`, `Population { size, ageProfile }`, `Economy { wealth, unemployment }`, `Climate`, `Infrastructure`, `FootballPassion` |
| **Club** | `Club { cityId, foundedYear, colors }`, `Finances`, `Squad`, `Reputation`, `Facilities`, `FanBase`, `BoardExpectations` |
| **Compétition** | `Competition { format, tier, countryId }`, `Season`, `Standings` |
| **Stade** | `Stadium { capacity, quality, ownerId }` |
| **Sponsor** | `SponsorProfile { sector, budget, riskAppetite }`, `Contracts[]` — source de revenus et levier de régulation économique |
| **Média** | `MediaProfile { reach, bias, rigor }`, `Perception` — voir plus bas |

> La ville est volontairement décomposée en plusieurs composants plutôt qu'en un bloc `City` monolithique (reprise de `ressource2.md` §2). Ça permet à un système de ne dépendre que de ce qu'il lit vraiment — le système d'affluence lit `Population` et `FootballPassion`, pas le climat — ce qui rend les déclarations `reads`/`writes` utiles au lieu d'être décoratives.

**Le média comme entité à part entière** est un choix qui rapporte gros pour son coût. Il porte sa propre `Perception` (§4), donc **ses propres erreurs** : il surestime un joueur, monte une hype, se trompe sur une rumeur de transfert. Ça donne un canal de narration crédible et une source d'information volontairement peu fiable pour le joueur — c'est-à-dire du gameplay.

### Entités vivantes (les acteurs)

| Entité | Composants principaux |
|---|---|
| **Personne** | `Person { name, birthDate, nationalities, homeCityId }`, `Personality`, `Reputation` |
| ↳ en tant que joueur | `PlayerPhysicalSkills`, `PlayerTechnicalSkills`, `PlayerMentalSkills`, `PlayerPotentials` (caché, §5 — porte le niveau, l'archétype et le vecteur de plafonds), `Fitness`, `Form`, `Morale`, `InjuryState`, `Contract`, `CareerRecord` |
| ↳ en tant qu'entraîneur | `CoachSkills`, `TacticalPreference`, `Role` |
| ↳ en tant qu'agent | `AgentProfile { commissionRate, network, reputation }`, `ClientList` |
| ↳ en tant que dirigeant | `ExecutiveProfile { ambition, patience, riskAppetite }`, `Role` |

> **Aucun composant de poste, et c'est délibéré (2026-08-04).** Ce tableau annonçait un `PositionAffinity` ; il n'existe pas et n'existera pas sous cette forme. Le poste **joué** est *dérivé* des compétences du moment (`Football\Support\PositionModel::bestPosition()`), jamais stocké — une étiquette stockée dériverait de la réalité sur vingt saisons de développement. Et aucun facteur d'affinité n'est appliqué par-dessus la note : la matrice de contribution pénalise déjà seule un attaquant aligné dans les buts, puisque sa note au poste de gardien se calcule sur son `handling` et ses `reflexes`, qui sont mauvais. Un multiplicateur en plus serait un double comptage.
>
> Ce qui est stocké, c'est l'**archétype de développement** (`PlayerPotentials::$archetype`) : la *forme* du potentiel, fixée à la naissance comme un gabarit physique. Deux causalités, deux moments — **à la naissance le poste fait les compétences** (seize tirages indépendants se concentrent sur leur moyenne et ne produisent jamais de spécialiste : il faut un archétype pour imposer la corrélation), **ensuite les compétences font le poste**.
>
> Limite mesurée et assumée : le poste dérivé coïncide aujourd'hui avec l'archétype dans **100 %** des cas. La seconde causalité est donc architecturalement correcte mais empiriquement inerte — elle ne mordra que le jour où l'entraînement ou le développement pourront pousser un joueur hors de son profil.

### Composants transverses

- `Relationship { a, b, affinity, history[] }` — le tissu social. Un composant à part entière, pas un champ.
- `Perception { observerId, subjectId, estimate, confidence }` — voir §4. **Dérivé à la lecture, jamais stocké**, et `observerId` est toujours une personne.
- `Tag` — marqueurs légers (`wonderkid`, `clubLegend`, `troublemaker`) posés par les systèmes, consommés par la narration.

---

## 3 bis. L'état global : les singletons

Une partie de l'état du monde n'appartient à **aucune entité**. `ressource2.md` l'appelle le *Context* et le place au même rang que les entités et les systèmes — l'intuition est juste, et cette catégorie manquait complètement ici.

En vocabulaire ECS ça s'appelle des **singletons** (les *Resources* de Bevy, les *Singleton Components* de Unity DOTS) : des composants dont il n'existe qu'une instance, adressés par type et non par entité.

```php
$inflation = $ctx->singleton(MarketInflation::class);
```

Candidats concrets :

| Singleton | Contenu | Lu par |
|---|---|---|
| `MarketInflation` | indice global, masse monétaire, tendance | valorisation des joueurs, régulation économique |
| `SeasonPhase` | pré-saison / championnat / mercato / trêve | quasiment tous les systèmes |
| `WorldClock` | tick courant, date simulée | tout |

> ⚠️ **`EconomicClimate` et `Weather` ne sont pas des singletons**, malgré une confusion présente dans une version antérieure de ce document. Un singleton est adressé **par type**, une seule instance pour tout le monde. Une donnée qui varie par pays ou par région est adressée **par entité** : c'est un composant, pas un singleton. `EconomicClimate` est donc un composant de l'entité **Pays**, `Weather` un composant de l'entité **Région** — les deux sont listés au catalogue, §3.

### Trois règles

1. **Un singleton se lit comme un composant**, via le `SystemContext`. Ce n'est **pas** une variable globale, pas un `static`, pas un service injecté — sinon le noyau cesse d'être une fonction pure de son état.
2. **Il est déclaré dans `reads()`/`writes()`** au même titre qu'un composant. Un singleton non déclaré est une dépendance cachée.
3. **Il est sérialisé dans les snapshots** comme le reste du `WorldState`. Un singleton oublié au snapshot, c'est un monde qui redémarre avec une inflation remise à zéro.

### La frontière avec le `Ruleset`

Confusion facile et coûteuse :

| | `Ruleset` (§6) | Singleton |
|---|---|---|
| Nature | **règle** — décidée par un humain | **état** — produit par la simulation |
| Change quand | on publie une nouvelle version | à chaque tick, tout seul |
| Exemple | `marketInflationTarget: 0.03` | `MarketInflation.current: 0.041` |

La cible est une règle. La valeur atteinte est un état. Le régulateur économique lit les deux et corrige l'écart.

---

## 4. Le cœur du jeu : vérité cachée vs perception

C'est **la décision de modélisation la plus importante** pour rendre le jeu d'agent intéressant.

> Toute donnée « vraie » du monde a une contrepartie **perçue**, bruitée, propre à chaque observateur.

```php
// La vérité — jamais exposée à un client (un des trois composants de
// compétences, §5 ; les deux autres suivent le même principe)
final readonly class PlayerTechnicalSkills {
    public function __construct(
        public int $technique, public int $passing, public int $finishing, /* ... */
    ) {}
}
final readonly class PlayerPotentials {
    public function __construct(
        public int $ceiling, public int $physicalPeakAge, /* ... un pic par categorie */ public float $growthRate,
    ) {}
}

// Ce qu'un observateur croit — DÉRIVÉ, jamais stocké (cf. ci-dessous)
final readonly class PerceivedSkills {
    public function __construct(
        public int   $observerId,   // un club, un agent, un média
        public int   $subjectId,
        public array $estimate,     // array<string, int>, partiel
        public float $confidence,   // 0..1, croît avec l'observation
    ) {}
}
```

Sans ça, le jeu d'agent est un tableur : tout le monde voit les mêmes chiffres, la valeur optimale est calculable, il n'y a pas de décision. Avec ça, **l'asymétrie d'information devient la ressource principale** : scouter, se déplacer, cultiver un réseau, vendre au bon moment à celui qui surestime.

### Implémentation — le détail qui compte

Le bruit ne doit **pas** être ré-échantillonné à chaque lecture, sinon le rapport de scouting change à chaque rafraîchissement de page.

```php
// Bruit = fonction déterministe de (observateur, sujet, nb d'observations)
$sigma    = $baseSigma / sqrt(1 + $observationCount * $scoutQuality);
$noise    = Gaussian::fromHash($worldSeed, $observerId, $subjectId, $observationCount);
$estimate = $trueValue + $noise * $sigma;
```

On ne stocke donc pas les estimations : on stocke `observationCount` et `scoutQuality`, et on **dérive** l'estimation à la lecture (dans la projection). Gain : coût mémoire nul, stabilité parfaite, et la « révélation » progressive est gratuite.

> **La formule livrée diffère sur un point, et il est important (2026-08-05).** L'esquisse ci-dessus met `scoutQuality` **dans** le facteur d'observation : à `observationCount = 0`, le jugement du scout n'a plus aucun effet, et *tous* les clubs jugent un joueur qu'ils n'ont jamais eu sous les yeux aussi mal les uns que les autres. Un bon recruteur ne servirait alors qu'à apprendre plus vite sur les joueurs déjà maison — l'inverse du métier de scout, et le contraire de ce dont le marché des transferts a besoin. Forme implémentée dans `Football\Support\PerceptionModel` :
>
> ```
> sigma = erreurDeBase / sqrt(facteurDeJugement × (1 + nbObservations))
> ```
>
> Les deux effets subsistent — le jugement aide toujours, l'observation compose — et l'esquisse est retrouvée à un facteur près.
>
> Deux autres écarts d'implémentation, mineurs mais à connaître : `z` n'est **pas** gaussien mais une somme de quatre uniformes (Irwin-Hall sur les quatre octets du hash), pour ne pas introduire de fonction transcendante dans le noyau — même arbitrage que le `Beta(1, k)` de `PlayerFactory` ; et le hash ne prend pas le `worldSeed` en paramètre explicite mais passe par `Core\SystemContext::stableHash()`, qui replie le `worldSeed` (resté privé) et est volontairement **invariante par tick et indépendante du système appelant** — sans quoi la valorisation d'un joueur par le marché le percevrait autrement que le système de contrats, le même jour, dans le même monde.

### `observerId` est une personne, jamais un club — note de conception (2026-08-02)

Le commentaire `// un club, un agent, un média` sur `observerId` ci-dessus est trompeur tel quel : un club n'est pas de nature à percevoir quoi que ce soit, c'est une **personne** qui perçoit — scout, coach, journaliste, supporter, président — et le club s'appuie (ou non) sur ces personnes pour recruter, superviser, etc. C'est très exactement le cas d'école de §1 : *« un joueur prend sa retraite, devient entraîneur adjoint, puis entraîneur principal, puis président »*. `observerId` doit donc être l'`EntityId` d'une entité portant `Person`, à laquelle un composant de rôle est attaché (`CoachSkills`/`ScoutingRole`/... — cf. §1 et le tableau de §5) — jamais un attribut porté par `Club` lui-même.

**Ce que ça laisse à concevoir avant que la formule ci-dessus ait un sens**, découvert en discutant la priorité de ce lot plutôt qu'en l'implémentant à l'aveugle :

1. **Comment une personne acquiert un rôle non-joueur.** Semée directement au genesis (même précédent que `Facilities`/`Finances` : état externe, aucun système du noyau n'en crée), ou par transition depuis un joueur retraité (plus riche narrativement, mais un système à part entière — `RetirementSystem` retire déjà les composants de compétences, il resterait à décider qui y attache un rôle et quand).
2. **La relation d'emploi club ↔ personne.** `SquadMembership` lie un joueur à un club dans un sens précis (effectif) ; l'emploi d'un scout par un club est une relation différente, un nouveau composant à concevoir, pas une réutilisation de `SquadMembership`.
3. **Le mécanisme qui fait avancer `observationCount`.** La formule le prend comme un acquis, mais rien ne l'incrémente sans une action d'observation explicite (qui regarde qui, à quelle fréquence) — c'est le vrai cœur mécanique du scouting, pas la formule de bruit qui vient après.

**Le premier consommateur existe déjà, et il attend (2026-08-02).** `Football\ContractSystem::quality()` décide chaque année quels joueurs un club prolonge et lesquels il laisse partir — en lisant les compétences **vraies**. C'est une **simplification de périmètre, jamais une affirmation de conception** : ce n'est pas « un club connaît forcément bien ses propres joueurs ». Un club n'a pas d'yeux ; c'est son staff qui perçoit, et un club au staff médiocre doit pouvoir se tromper sur son propre joueur — le prolonger trop cher, ou laisser filer le bon. Le jour où `Person` + rôle existeront, c'est cette méthode qui passera de la vérité cachée à une estimation bruitée par `observerId`, et rien d'autre dans ce système n'aura à changer. C'est aussi ce qui rend le lot de perception immédiatement mesurable au harness : un staff qui se trompe produit des effectifs différents, donc des classements différents.

**Scoping retenu pour la Phase 2** (`15-` §4 — perception/scouting et agents PNJ y sont explicitement, ce n'est pas hors périmètre) : seul le rôle **scout employé par un club** sert un besoin déjà identifié, la valorisation du marché des transferts (§ ci-dessus, `14-` §5). Coach/président relèvent de la gouvernance de club (attentes du board, licenciements, `14-` §7) et journaliste/supporter de la narration (`14-` §9, Phase 6) — tous deux hors périmètre tant que rien ne les consomme. L'architecture (`Person` + composant de rôle, jamais de sous-type) reste ouverte pour les ajouter plus tard sans rien casser.

**Les trois questions, tranchées (2026-08-04).** Elles le sont dans le sens qui livre le consommateur le plus tôt ; chacune rouvre proprement plus tard.

1. **Acquisition du rôle : semée au genesis.** Précédent `Facilities`/`Finances` — état externe, aucun système du noyau n'en crée. La transition retraité → scout est plus riche narrativement mais c'est un système entier, et elle retarde le seul consommateur écrit. Elle appartient à la gouvernance de club, avec coach et président.
2. **Emploi : un nouveau composant**, distinct de `SquadMembership` — porté par la personne, pointant vers le club, comme `Contract`. Un scout n'est pas un membre d'effectif et ne doit apparaître dans aucun des parcours qui itèrent l'effectif (`TrainingSystem`, `MatchSystem`, `SquadIntegrityTest`).
3. **`observationCount` : aucun mécanisme d'observation n'est construit en Phase 2.** (Voir « Livré » ci-dessous : c'est bien cette forme qui a été implémentée.) C'est le point où le périmètre était le plus mal cadré. Le compteur est indexé par **paire** (observateur, sujet) : ce n'est un composant ni de l'un ni de l'autre, et son stockage naïf est en O(scouts × joueurs) — une structure relationnelle que rien, aujourd'hui, ne justifie de concevoir. Forme retenue : le scout d'un club observe en continu l'effectif de son club, `observationCount` = **ancienneté du joueur au club**, dérivée d'un champ `signedOn` ajouté à `Contract` ; tout sujet hors de l'effectif du club reste à 0. Aucun stockage nouveau, aucune structure par paire, et la propriété recherchée est là : un club connaît mieux ses joueurs que ceux des autres, **et se trompe quand même si son staff est mauvais**. « Qui va observer qui », avec son coût et ses arbitrages, est une mécanique du **jeu d'agent** : sa place est en Phase 5, dirigée par un besoin réel, pas anticipée ici.

### Livré le 2026-08-05 — ce qui existe maintenant dans le monde

Les trois réponses ci-dessus ont été implémentées telles quelles. Détail classe par classe dans `packages/kernel/README.md` (§ « Perception ») ; ce qui compte pour le **modèle du monde** :

- **Deux composants nouveaux, et la séparation qu'ils portent.** `Employment(clubId)` est la relation d'emploi d'une personne par un club ; `Scout(judgement)` est le rôle. C'est la **présence** de `Scout` qui fait d'une personne un scout — aucun sous-type, aucun enum de rôle, conformément à §1. Coach, président, journaliste, supporter s'ajouteront comme composants frères sur des personnes qui portent déjà `Employment`, sans toucher à une ligne existante.
- **`observerId` est bien une personne.** C'est l'`EntityId` du scout qui entre dans la dérivation du bruit, jamais celui du club. Conséquence testable : deux clubs qui échangeraient leurs scouts échangeraient leurs erreurs.
- **Un club sans observateur n'est pas omniscient**, il est le pire observateur du monde (`PerceptionBalance::$unstaffedJudgement`). L'inverse aurait réintroduit par la petite porte l'affirmation fausse que ce lot supprime.
- **Le poste reste vrai.** Seule la *note* d'un joueur est perçue ; l'archétype dérivé de ses compétences, lui, est lu sur la vérité — comme tout ce que lit le moteur de match, parce qu'un match n'est pas une opinion. Se tromper sur le poste d'un joueur est une extension possible, notée comme telle.
- **Le seul consommateur est `Football\ContractSystem`**, comme annoncé. Le prochain sera la valorisation du marché des transferts, qui doit obtenir *la même* estimation que lui pour un même observateur — d'où une dérivation de bruit indépendante du système appelant.

Ce qui reste explicitement non construit : le mécanisme d'observation (« qui observe qui »), tout rôle autre que scout, l'embauche et la progression du staff, et toute exposition de la perception à un client.

---

## 5. Les attributs des joueurs : peu, orthogonaux, groupés par comportement de vieillissement

FM a ~250 attributs. **On en vise 10 à 12** attributs de champ, plus un jeu réduit pour le gardien. Raison brutale : on ne peut pas équilibrer un espace à 250 dimensions en solo, et le moteur de match n'en consomme qu'une poignée.

Les attributs sont répartis en **trois composants orthogonaux** (`PlayerPhysicalSkills`, `PlayerTechnicalSkills`, `PlayerMentalSkills`) plutôt qu'un seul bloc plat — pas pour le rangement, mais parce qu'un système les traite déjà différemment : le vieillissement (`14-` §2) fait culminer et décliner le physique avant le mental, à talent égal. Chaque catégorie a son propre âge de pic (individuel, `PlayerPotentials`) et sa propre pente de déclin post-pic (globale, `Ruleset\PlayerDevelopmentBalance`).

**Important : la répartition suit le comportement de vieillissement, pas le domaine métier.** Il n'existe pas de quatrième catégorie « gardien » — les attributs de gardien sont répartis dans les trois catégories ci-dessus selon leur nature (les réflexes vieillissent comme le physique, le geste comme la technique, l'autorité comme le mental), pas regroupés à part sous prétexte qu'ils partagent un poste.

### L'échelle 1-100 : absolue et mondiale

Tous les attributs, et le `ceiling` de `PlayerPotentials`, vivent sur une même échelle entière **1-100**, dont voici les points d'ancrage :

| Valeur | Ce qu'elle décrit |
|---|---|
| 1-20 | plancher du modèle — pas le niveau d'un professionnel |
| ~30 | professionnel du bas de la pyramide modélisée |
| ~50 | professionnel **médian**, toutes divisions modélisées confondues |
| ~70 | titulaire dans la meilleure division d'un pays fort |
| ~85 | niveau international, titulaire d'un grand club — quelques pour cent de la population |
| ~95 | une poignée de joueurs vivants à un instant donné |
| 100 | asymptote, jamais atteinte |

> **L'échelle est absolue et définie à l'échelle du monde entier. Elle n'est jamais relative à une division, à un pays, ni à la population du moment.**

Quatre raisons, dont la première suffit :

1. **Sinon, ajouter une division ou un pays re-signifie rétroactivement tous les nombres existants.** C'est exactement la classe de bug décrite en `14-` §1 pour le LOD du moteur de match : un changement de périmètre d'observation qui change l'histoire du monde. Une échelle relative rendrait incomparables deux joueurs de deux championnats — et un monde multi-pays est prévu (`15-` phase 6).
2. **La promotion et la relégation deviendraient des rescalages.** Un joueur gagnerait dix points en descendant d'une division. Absurde, et impossible à raconter.
3. **La perception (§4) suppose un référentiel partagé.** Ce qu'un observateur croit est une version bruitée de la vérité ; « ce joueur est à 70 » doit vouloir dire la même chose pour un recruteur français et pour un recruteur brésilien, sinon la couche de perception ne veut plus rien dire.
4. **Le moteur de match L0 en dérive `attaque`/`défense`** (`14-` §1). C'est l'échelle absolue qui rend le contrat de calibration L0/L1 vérifiable d'un championnat à l'autre.

### Corollaire : l'échelle est universelle, les distributions ne le sont pas

C'est la moitié qu'on oublie. La distribution du talent **est une propriété d'une population**, pas de l'échelle. Un centre de formation de première division et un centre de quatrième division tirent sur la *même* échelle, mais dans des *tranches* différentes.

Conséquence pratique sur le `Ruleset` : les bornes de `ceiling` de `YouthIntakeBalance` ne décrivent pas « le talent en général », elles décrivent **la tranche dans laquelle recrute un club donné**. Elles sont globales aujourd'hui parce qu'aucune notion de niveau (`tier`) ni de `Reputation` de club n'existe encore ; elles devront devenir fonction du club le jour où elle existera. En attendant, le monde de la Phase 0 étant **une seule première division** (`15-` §4), ces bornes doivent décrire une promotion de première division — pas la pyramide entière, dont le médian à ~50 serait bien trop bas pour cette population.

Le corollaire du corollaire, à ne pas rater : une distribution qui donnerait 20 % de la population au-dessus de 85 ne serait pas « généreuse », elle **casserait la sémantique de l'échelle** — 85 ne voudrait plus dire « international ». La forme de la loi de talent (asymétrique à droite, queue rare, cf. §7) n'est pas un choix esthétique, c'est ce qui maintient les ancrages ci-dessus vrais.

**Physique** (`PlayerPhysicalSkills`) :

| Attribut | Consommé par |
|---|---|
| `pace` | transitions rapides, contre-attaque |
| `stamina` | dégradation en fin de match, enchaînement |
| `strength` | duels |
| `reflexes` | arrêts réflexes du gardien |

**Technique** (`PlayerTechnicalSkills`) :

| Attribut | Consommé par |
|---|---|
| `technique` | conservation du ballon, dribble |
| `passing` | transitions inter-zones |
| `finishing` | conversion des tirs |
| `defending` | interception, récupération |
| `positioning` | probabilité d'être impliqué au bon endroit |
| `handling` | conservation du ballon en sortie d'arrêt (gardien) |
| `distribution` | relance du gardien |

**Mental** (`PlayerMentalSkills`) :

| Attribut | Consommé par |
|---|---|
| `vision` | création d'occasions à haute valeur |
| `composure` | performance sous pression (fin de match, gros match) |
| `leadership` | effets d'équipe, moral |
| `discipline` | cartons, comportement hors terrain |
| `command` | sorties aériennes, organisation de la défense (gardien) |

> **Les quatre attributs de gardien (`reflexes`, `handling`, `distribution`, `command`) sont portés par tout joueur, pas seulement les gardiens titulaires.** Un joueur de champ appelé à garder les buts (exclusion ou blessure du gardien) joue avec ces attributs — généralement bas — au même titre que ses autres compétences. Ce n'est **pas** un archétype exclusif au sens de §1 (le joueur qui devient entraîneur, où ses composants de compétences cèdent la place à `CoachSkills`) : il n'y a pas de bascule, juste des attributs de plus que tout le monde porte, disséminés dans les trois catégories comme n'importe quel autre attribut.

**Règle** : n'ajouter un attribut (ou une catégorie) que si un système le lit *et* qu'il change une décision de jeu ou un comportement — le vieillissement justifie déjà la catégorisation ci-dessus. Un attribut décoratif est une dette d'équilibrage.

### Ce qui consomme réellement ces attributs (2026-08-04)

La colonne « consommé par » ci-dessus décrit une **intention**, pas l'état du code. Audit fait avant le lot des postes : sur seize attributs, **sept ne décidaient de rien** — `handling`, `distribution` et `command` (la panoplie du gardien) étaient générés, vieillis sur quarante saisons et facturés dans le salaire sans qu'aucune décision ne les lise, et `reflexes` servait de compétence **défensive à tous les joueurs de champ**. Le monde générait des gardiens et jouait les matchs comme s'ils n'existaient pas.

Depuis le lot des postes, la matrice de contribution (`Football\Support\PositionModel`, §5 bis ci-dessous) en rend **treize** vivants. Restent dormants, et c'est documenté comme tel :

| Dormant | Ce qui le réveillera |
|---|---|
| `stamina` | fatigue en cours de match — moteur L1 (`14-` §1) |
| `discipline` | cartons — moteur L1 |
| `leadership` | effets d'équipe et moral, plus le départ à la retraite selon la personnalité (`15-` §4 Phase 6, note sur les centres de formation) |

Ils gardent un **plafond plein** malgré leur inutilité présente (`PositionBalance::$offProfileCeilingRatio` ne les rabaisse pas) : les rabaisser les rendrait mauvais chez tout le monde, et le monde serait atone sur ces axes le jour où un système les lira.

### 5 bis. Le potentiel plafonne une composition, pas chaque compétence

`PlayerPotentials::$ceiling` **ne plafonne pas chaque attribut séparément**. Il plafonne la note du joueur **à son poste**, et le joueur répartit son talent sous cette contrainte :

```
Σ  poids(poste, attribut) × plafond(attribut)  =  ceiling
```

C'est ce qui fait exister « excellent passeur, mauvais tacleur ». La première version du lot mettait tous les attributs du profil exactement à `ceiling` : deux milieux de même potentiel étaient alors **littéralement le même joueur** — mesuré, écart-type de 1,5 point entre les cinq attributs de leur propre profil. Un monde où connaître le poste et le niveau suffit à tout reconstituer n'a rien à faire scouter, et c'est l'asymétrie d'information qui porte le jeu d'agent (§4). Après correction : **8,57 points**.

Trois conséquences à ne pas perdre :

- **Le vecteur de plafonds est stocké** (`PlayerPotentials::$ceilings`), pas dérivé. C'est un tirage propre au joueur, donc une vraie propriété de lui — le dériver d'un hash de son `EntityId`, comme le fait la perception (§4), ferait dépendre son talent d'un ordre d'allocation qui ne survivrait pas à une renumérotation.
- **L'invariant de l'échelle tient** : un joueur pleinement développé note exactement son `ceiling` à son poste, donc « un `ceiling` de 90 » veut toujours dire « un joueur de 90 », et les ancrages de l'échelle 1-100 ci-dessus gardent leur sens.
- **Le plafond par attribut reste le mécanisme**, parce que `Football\PlayerDevelopmentSystem` progresse proportionnellement à l'écart au plafond : sans plafonds distincts il ramène tous les attributs au même niveau — et d'autant plus vite qu'ils en sont loin, donc il **efface** les profils. Mesuré avant le lot : écart-type des seize attributs à l'intérieur d'un même joueur, à l'âge du pic, **4,0 points**, soit du bruit de marche aléatoire et aucun profil. Après : **16,7**.

---

## 6. Les règles comme données : le `Ruleset`

C'est la réponse à « administrer et faire évoluer les règles sans redéployer ».

Un `Ruleset` est un **bundle versionné, validé par schéma**, décrivant tout ce qui est paramétrique ou compositionnel :

```jsonc
{
  "version": "2026.1.0",
  "competitions": [{
    "id": "fr.d1",
    "format": { "type": "roundRobin", "legs": 2, "teams": 18 },
    "points": { "win": 3, "draw": 1, "loss": 0 },
    "tiebreakers": ["points", "goalDiff", "goalsFor", "headToHead"],
    "qualification": [{ "positions": [1, 2], "to": "eu.cl" }],
    "relegation":    { "positions": [17, 18], "to": "fr.d2" }
  }],
  "transferWindows": [
    { "opensOn": "07-01", "closesOn": "09-01" },
    { "opensOn": "01-01", "closesOn": "02-01" }
  ],
  "contracts": { "maxYears": 5, "minAgeProfessional": 16, "agentCommissionCap": 0.10 },
  "finance":   { "wageCapRatio": 0.7, "ffpWindowYears": 3 },
  "balance":   { "trainingRate": 1.0, "injuryBaseHazard": 0.004, "marketInflationTarget": 0.03 }
}
```

Trois familles de compétitions couvrent ~95 % du football mondial : `roundRobin`, `knockout`, `groupsThenKnockout`. Un interpréteur de ces trois formats a un excellent rapport effort/couverture.

### Où s'arrête le data-driven — sois lucide

| Type de changement | Donnée ? |
|---|---|
| Passer une ligue à 20 clubs, changer le barème de points | ✅ Ruleset |
| Ajouter une coupe nationale | ✅ Ruleset |
| Changer le taux d'inflation cible, la fréquence des blessures | ✅ Ruleset (`balance`) |
| Ajouter un système de prêts avec option d'achat | ❌ Code (nouveau composant + système) |
| Ajouter une mécanique de fatigue mentale | ❌ Code |

**Ne construis pas un DSL généraliste.** C'est le gouffre classique : tu passes six mois à écrire un langage de script, tu te retrouves avec un mauvais langage sans débogueur, et tu écris quand même le code en PHP. Données pour le paramétrique et le compositionnel, code pour les mécaniques.

### Verrou de versionnage

Un monde est **épinglé** à une version de ruleset. Changer les règles d'un monde vivant est une **migration explicite**, versionnée, auditée — pas un hot reload. Voir `13-moteur-de-simulation.md` §5.

---

## 7. Génération du monde initial

Le monde ne démarre pas vide : il démarre **avec une histoire**, comme Dwarf Fortress.

Pipeline `worldgen` (déterministe à partir d'une graine) :

1. **Géographie** — pays, villes, populations, richesse, passion football.
2. **Pyramide** — divisions par pays, clubs par division, ancrage géographique (un club par ville selon la population).
3. **Population** — personnes générées avec noms culturellement cohérents, pyramide des âges, distribution de talent réaliste (log-normale, pas uniforme).
4. **Affectation** — joueurs aux clubs selon la réputation, contrats échelonnés (pas tous expirant la même année !).
5. **Histoire pré-simulée** — faire tourner le noyau en mode L0 sur **10 à 20 saisons** avant l'ouverture du monde. Génère palmarès, légendes de clubs, rivalités, hiérarchies. Coût : quelques secondes. Valeur narrative : énorme.

L'étape 5 est celle qu'on oublie et qui fait toute la différence entre un monde et une base de données.

---

## 8. Invariants du monde à tester en continu

À vérifier en fin de tick (mode dev) et dans le harness :

- Un joueur a **0 ou 1** contrat actif.
- La taille d'un effectif respecte les bornes du ruleset.
- **Conservation monétaire** : `Σ injections − Σ puits = Δ masse monétaire totale`. C'est le test qui prévient la mort par inflation.
- La pyramide des âges reste stationnaire sur 20 saisons (pas de vieillissement ni de rajeunissement global).
- Aucune entité orpheline (référence vers un `EntityId` supprimé).
- Le calendrier est complet et cohérent (chaque club joue le bon nombre de matchs).
- **Les files ne croissent pas sans borne** d'un tick à l'autre. Une InQueue qui grossit saison après saison signale une boucle d'événements non amortie — le monde diverge lentement, et ça ne se voit pas autrement. Voir `16-evenements-et-cascades.md` §6.
- Tous les singletons attendus sont présents et sérialisés (§3 bis).
