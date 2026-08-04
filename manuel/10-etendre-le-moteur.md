# 10 — Étendre le moteur

Chapitre pratique : les recettes, la checklist, et les pièges déjà payés.

## 1. Ajouter un composant

```php
// packages/kernel/src/Football/Components/Morale.php
namespace Flair\Kernel\Football\Components;

/**
 * [Ce que c'est, qui l'écrit, qui le lit, pourquoi cette forme.]
 */
final readonly class Morale
{
    public function __construct(public float $level) {}
}
```

**Checklist :**

- [ ] `final readonly`. Pas de setter, pas de méthode métier — des données pures.
- [ ] Un seul système le `writes()`. Si deux candidats existent, c'est qu'il faut soit
      fusionner les systèmes, soit **scinder le composant en deux facteurs** (c'est la
      réponse retenue pour `TrainingEffect` : `i(temps de jeu)` et `j(moral)` seront des
      composants séparés, pas des champs supplémentaires).
- [ ] Décider s'il est semé au genesis (harness/worldgen) ou créé par un système.
- [ ] Docblock : ce que c'est, **qui l'écrit**, **qui le lit**, et pourquoi cette forme.
      Le docblock est le seul endroit où l'intention survit.
- [ ] **Ajouter le composant à `Harness\Support\WorldHasher`**, sinon il n'entre pas dans
      le hash de déterminisme et une divergence le concernant passera inaperçue.

**Composant ou singleton ?** S'il peut y en avoir deux (par pays, par compétition, par
club), c'est un composant. Un singleton n'existe qu'en un exemplaire dans le monde entier.

**Composant ou levier de `Ruleset` ?** Un composant est un **état qui évolue** ; un levier
est une **règle qui ne change pas** pendant la vie du monde. Une borne d'invariant
(`Facilities::MIN_QUALITY`) est une constante du composant — ni l'un ni l'autre.

## 2. Ajouter un système

```php
final class MoraleSystem implements System
{
    public function id(): string { return 'morale'; }        // ⚠️ jamais renommé

    public function reads(): array   { return [MatchResult::class, Morale::class]; }
    public function writes(): array  { return [Morale::class]; }
    public function removes(): array { return []; }
    public function creates(): array { return []; }

    public function subscribesTo(): array { return [MatchPlayed::class]; }

    public function handle(DomainEvent $event, SystemContext $ctx): void { /* réactif */ }
    public function update(SystemContext $ctx): void { /* périodique */ }
}
```

Puis **une seule ligne** à ajouter, n'importe où dans
`Football\FootballPipeline::declaration()` :

```php
new MoraleSystem(),
```

Le tri topologique le placera. C'est le bénéfice concret de l'ordre dérivé : on n'a pas à
savoir où le mettre.

**Checklist :**

- [ ] Les quatre déclarations sont **exactes**. Une déclaration trop large affaiblit le
      graphe de dépendances ; une déclaration trop étroite lève une
      `UndeclaredAccessException` au premier tick.
- [ ] `id()` est stable et ne sera **jamais** renommé : il dérive le flux aléatoire du
      système ([ch. 05](05-determinisme-et-aleatoire.md)). Un renommage change tous ses
      tirages, donc l'histoire de tous les mondes existants.
- [ ] Les leviers numériques vont dans une **nouvelle classe** de
      `Core\Ruleset\`, ajoutée à `Balance` — pas dans un groupe existant, pas en dur.
- [ ] Aucune itération non triée. Toujours `->entities()`.
- [ ] Aucun `rand()`, `time()`, I/O. Le hasard passe par `$ctx->rng($entityId)`.
- [ ] Le système ne connaît **aucun autre système**. Aucun `use` d'un `...System`.
- [ ] `PipelineInvariantsTest` passe (writer unique, remover unique, pas de dépendance
      inversée).
- [ ] Test unitaire avec un pipeline **partiel** (un ou deux systèmes) pour isoler ce qu'on
      mesure — c'est un usage légitime et prévu.

**Si le montage lève une `PipelineCycleException`,** ce n'est pas un bug du tri : c'est que
deux systèmes veulent se lire mutuellement dans le même tick. La réponse est le canal 2
(voir §4).

## 3. Ajouter un événement

Avant d'écrire la classe, passer le **test de pertinence** :

```
   Cette chose franchit-elle un seuil comportemental ?     ─┐
   Est-elle irréversible ?                                  ├─ au moins un OUI → Fait
   Est-elle racontable ?                                   ─┘
                                                            sinon → le système se tait
```

Puis :

- [ ] `implements DomainEvent`, `final`, propriétés publiques en lecture seule.
- [ ] **Payload scalaire.** Il sera journalisé en `jsonb` : des `int`, `string`, `float`,
      `list<int>` — pas de `SimDate` ni d'objet du domaine. C'est pourquoi
      `ContractSigned` porte `expiresOnEpochDay` et non un `SimDate`.
- [ ] **Auto-suffisant.** Le consommateur doit pouvoir agir sans relire un composant. Une
      duplication d'identifiant déjà présent ailleurs est acceptée pour ça.
- [ ] Docblock : qui l'émet, qui le consomme, **et pourquoi c'est un événement plutôt
      qu'une écriture directe**. Cette dernière question a une bonne réponse dans ce
      projet, presque toujours la même (§4).
- [ ] Estimer le **volume annuel**. Un Fait par joueur et par semaine est un piège
      documenté ; un Fait par contrat expirant (~un tiers de l'effectif par an) est sain.

## 4. Le motif à reconnaître : « décider tard, appliquer tôt »

Tu tomberas dessus. La forme est toujours la même :

```
   Symptôme : le système que j'écris doit LIRE quelque chose d'écrit tard
              dans le pipeline, et ÉCRIRE quelque chose de lu tôt.
              Aucun ordre ne convient. Le tri lève un cycle.

   Réponse :  scinder en deux systèmes.
              ┌─────────────────────────────────────────────────────┐
              │  DÉCIDEUR (tard)   lit tout ce qui est à jour        │
              │                    n'écrit rien                      │
              │                    émet un Fait ────────┐            │
              └─────────────────────────────────────────┼────────────┘
                                                        │ tick+1
              ┌─────────────────────────────────────────▼────────────┐
              │  APPLICATEUR (tôt) ne décide rien                    │
              │                    écrit ce que le Fait porte         │
              └──────────────────────────────────────────────────────┘
```

Trois occurrences dans le code actuel :

| Décideur | Fait | Applicateur |
|---|---|---|
| `ContractSystem` (11ᵉ) | `ContractSigned` / `ContractExpired` | `SquadSystem` (3ᵉ) |
| `FinanceSystem` (6ᵉ) | `ClubInvestedInFacilities` | `FacilitiesSystem` (1ᵉʳ) |
| `CompetitionSystem` (10ᵉ) | `SeasonConcluded` | `FinanceSystem` (6ᵉ) |

Bénéfice collatéral que l'on découvre après coup : l'applicateur devient trivialement
vérifiable (il ne décide rien), et toute la politique reste à un seul endroit.

## 5. Les pièges déjà payés

Chacun a coûté un bug réel dans ce projet.

| Piège | Symptôme | Parade |
|---|---|---|
| **Itérer un tableau associatif** | Divergence de déterminisme silencieuse | Toujours `->entities()`, jamais `foreach` sur une map |
| **Arithmétique 64 bits dans le PRNG** | Bascule `int → float` sans erreur | `Math32::mul32()` partout |
| **Recopier la liste des systèmes** | `bin/demo.php` a tourné sur 9 systèmes sur 11, simulant une économie que le vrai monde n'avait pas | `FootballPipeline` est la seule source |
| **Créer sans détruire** | 1 320 `Fixture` mortes après 10 ans, sérialisées dans chaque snapshot | Qui crée détruit ; l'histoire vit dans le journal |
| **Un `round()` sec sur une espérance fractionnaire** | Le levier n'a plus aucun effet entre 0,5 et 1,5 | Arrondi stochastique |
| **Trier par `clubId` faute de mieux** | Une hiérarchie arbitraire gravée à la création du monde, mesurée ensuite comme une vraie inégalité | Clé de loterie rejouée, ou cas particulier explicite |
| **Un seuil binaire dans une formule d'équilibrage** | Effet de falaise, métriques non monotones | Formes continues (le carré, pas le palier) |
| **Un événement émis dans les deux cas contradictoires** | Le journal raconte un licenciement puis un réembauchage qui n'ont jamais eu lieu | N'émettre que ce qui s'est réellement passé |
| **Conclure sur un run unique** | Le Gini varie de 0,363 à 0,614 entre graines | Graines appariées, plusieurs graines |
| **Une fenêtre de mesure plus courte que le transitoire** | « La population n'est pas stationnaire » — elle l'était dès l'année 13 | Mesurer au-delà du régime transitoire |

## 6. Ce qu'on refuse explicitement

Utile de le savoir avant de proposer :

- **Un ORM, un framework, un conteneur DI dans `kernel`.** Le noyau doit tourner dans un
  test unitaire nu.
- **La découverte automatique de systèmes.** Ce serait de l'I/O, et surtout ça
  changerait le comportement de tous les mondes existants sans qu'aucun diff ne le montre.
- **Le parallélisme des systèmes.** Gain nul (le CPU n'est pas le facteur limitant),
  risque de non-déterminisme majeur.
- **Un sous-type `Player`/`Club`/`City`.** Jamais. Une entité est un entier.
- **Un compteur ou un disjoncteur de cascade.** La barrière inter-ticks est structurelle ;
  un disjoncteur serait un pansement sur un problème qu'on n'a pas.
- **Une abstraction avec un seul consommateur.** Le critère d'extraction est **deux
  consommateurs réels, jamais un seul, jamais par anticipation**. `WageModel` en a deux ;
  `TrainingEffect` reste mono-facteur tant qu'il n'y en a qu'un.
- **Construire hors du périmètre de la phase en cours** sans demande explicite.

## 7. Écarts connus entre le code et la documentation

Ce manuel a été écrit à partir du code. Les points suivants divergent de ce qu'affirment
`docs/` ou les READMEs de packages, à la date de rédaction — **le code fait foi**.

| Où | Ce que dit la doc | Ce que fait le code |
|---|---|---|
| `docs/12-` §7 | La loi de talent est une **log-normale** | C'est une `Beta(1, k)` = `min(U₁…U_k)`, substitut arithmétique assumé pour éviter Box-Muller ([ch. 05 §6](05-determinisme-et-aleatoire.md)) |
| `docs/14-` §2 | La progression porte un **bruit additif** | C'est un **arrondi stochastique** : le taux devient une probabilité quotidienne de pas ±1 |
| `docs/13-` §2 | `InQueue` et `OutQueue` sont deux structures | Une seule classe `OutQueue`, observée à deux moments de son cycle de vie |
| `docs/14-` §1 | Le moteur de match est derrière une interface `MatchEngine` | `PoissonMatchEngine` est une classe concrète : pas de deuxième implémentation, donc pas d'interface |
| `packages/harness/README.md` | `src/Simulation/PipelineFactory` est la source de vérité de l'ordre | Ce fichier n'existe plus ; c'est `Football\FootballPipeline`, côté kernel |
| `docs/12-` §6 | `Contract` porte `releaseClause`/`agentId` | Ni l'un ni l'autre : aucune négociation ni agent ne les consomme encore |
| `docs/11-` §7 | Sept packages dans le monorepo | Deux existent : `kernel` et `harness` |

Ces écarts ne sont pas tous des dettes. La plupart sont des décisions documentées **dans
le code** (docblocks), qui n'ont simplement pas été répercutées dans les documents de
conception — lesquels décrivent la cible, pas l'état.

## 8. L'état actuel, en une page

**Ce qui tourne de bout en bout :**

- Le socle générique : ECS, messagerie, `Scheduler`/`OutQueue`, `Pipeline` + ordre dérivé,
  PRNG 32 bits, `Ruleset` versionné, `Simulation::step()`.
- Le domaine football : vieillissement, entraînement, intake, retraite, calendrier, match
  Dixon-Coles, classement, grand livre monétaire, installations, contrats et mercato.
- Le harness : population synthétique, comparaison à graines appariées, métriques,
  déterminisme, conservation monétaire, CI.

**Ce qui n'existe pas encore :**

- Le `Host` (boucle temps réel, persistance, snapshots, SSE) et l'API.
- La **perception** : `ContractSystem::quality()` lit aujourd'hui la vérité cachée. Le
  modèle visé fait de `observerId` une **personne** (scout, entraîneur, président), dont
  la compétence détermine l'erreur d'estimation. Aucun rôle non-joueur n'existe encore.
- Le marché des transferts avec indemnités, et le régulateur d'inflation.
- Les postes (`Position`, `PositionAffinity`), dont dépendent la sélection d'un onze et
  une pondération honnête des compétences.
- La boucle de jeu humaine : `TickContext::$intents` est de la plomberie sans consommateur.

---

**Suite :** [99 — Ressources et glossaire](99-ressources-et-glossaire.md)
</content>
</invoke>
