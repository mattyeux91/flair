# `flair/api` — le monde devient visible

Une couche de lecture unique sur l'event store et les snapshots de `flair/host`, servie en **deux présentations** : les pages d'administration et le JSON. Premier lot de la Phase 4 (`docs/15-` §4).

```
kernel   → (rien)
worldgen → kernel
host     → kernel, worldgen
api      → host                    ← ce paquet
```

## Démarrer

```bash
docker compose up -d db            # depuis la racine du repo, PostgreSQL sur le port 54329
composer install
php artisan serve                  # http://localhost:8000
```

Il faut un monde en base. S'il n'y en a pas :

```bash
cd ../host
php bin/host.php install
php bin/host.php create alpha --players=500 --clubs=18 --seed=42
php bin/host.php advance alpha --ticks=420    # 420 ticks pour avoir un classement
```

Connexion par `FLAIR_DB_*` dans `.env` — mêmes noms que le CLI du Host, mêmes défauts que le `docker-compose.yml`.

## Trois routes, un seul vrai écran

| | |
|---|---|
| `/` | les mondes |
| `/worlds/{world}` | le monde : tick, classement, clubs, masse monétaire |
| `/worlds/{world}/clubs/{club}` | la fiche d'un club |
| `/worlds/{world}/clubs/{club}/history` | **dix ans d'histoire**, en blocs par saison |

Et les mêmes DTO en JSON sous `/api/…`. Ce ne sont pas des routes de confort : voir « le test qui compte » plus bas.

## Ce qu'on a assumé, et ce qui le tient

**Un seul paquet sert l'admin *et* l'API.** Le graphe de `docs/11-` §7 prévoyait `admin` et `api` séparés ; on s'en écarte délibérément. L'admin a **un seul utilisateur** et un seul métier — rendre le monde visible. Un SPA y coûterait un build front, un routeur client, un état client et une auth par token pour afficher des tableaux.

Mais l'argument tenait à une condition : **ce n'est pas la séparation en paquets qui empêche les deux présentations de diverger, c'est qu'elles n'ont qu'une source.** D'où la règle : rien en dehors de `Flair\Api\Read\` ne lit le snapshot ni l'event log. Un contrôleur assemble, une vue affiche, aucun des deux n'interroge quoi que ce soit.

**Le test qui compte.** `Tests\Http\PagesMatchJsonTest` prend les chiffres de la réponse JSON, les met sous la forme que la page emploie, et exige de les y retrouver — club par club, joueur par joueur. Si quelqu'un lit la base depuis une vue, ou renumérote un champ dans un seul des deux chemins, c'est là que ça rougit. Sans ce test, l'écart au graphe serait une intention ; avec, c'est une contrainte.

## Aucune projection, aucun cache — et c'est mesuré

`docs/11-` §4 posait les projections comme évidentes. Elles n'existent pas ici, parce que le lot 0 a mesuré ce qu'elles auraient coûté d'éviter :

| | |
|---|---|
| Décoder le monde entier | **14 ms**, 18 Mo (5,7 ms de base + `json_decode`, 8,3 ms de `SnapshotCodec`) |
| Dix ans d'histoire d'un club dans `events` | **2,17 ms** en `Seq Scan`, table entière en cache |

À ce prix, une table de projection serait de la dénormalisation sans problème à résoudre, et un cache un invalidateur à maintenir pour gagner dix millisecondes. Le monde ne change d'ailleurs qu'une fois par heure en production (un tick = un jour, déclenché par cron) : le snapshot relu est le même pendant 3 600 secondes.

Mieux : lire le snapshot et dériver à la lecture est la **seule** forme conforme à `docs/12-` §4, qui interdit de *stocker* une perception. Une projection qui matérialiserait des fiches joueur irait contre, ou exploserait en combinatoire (une ligne par couple observateur × joueur).

### Coût réel d'une requête

Mesuré sur le monde `dix-ans` (500 joueurs / 18 clubs / tick 3650), serveur de développement `artisan serve`, moyenne sur sept requêtes :

| | |
|---|---|
| `/` (index, **aucun snapshot décodé**) | **17,2 ms** |
| `/worlds/dix-ans` | **30,6 ms** |
| `/worlds/dix-ans/clubs/11` | **28,7 ms** |
| `/api/worlds/dix-ans/clubs/11` | **28,1 ms** |

Les 14 ms de décodage sont donc la moitié du coût ; le reste est l'amorçage de Laravel et le rendu. HTML et JSON coûtent la même chose — le rendu Blade n'est pas le sujet. L'index reste à 17 ms précisément parce qu'il ne décode rien : c'est l'usage pour lequel le `tick` de commodité de la table `worlds` existe.

## L'histoire d'un club : six noms pour la même chose

Un club **n'a pas de clé unique** dans les payloads de l'event log. Il apparaît sous six noms selon le type de Fait, et `SeasonConcluded` l'encode carrément par sa **position** dans un tableau :

| clé | Faits |
|---|---|
| `clubId` | `contract_expired`, `contract_signed`, `youth_player_promoted`, `club_invested_in_facilities` |
| `previousClubId` | `contract_signed` — un transfert entre dans l'histoire des **deux** clubs |
| `homeClubId` / `awayClubId` | `match_played` |
| `buyerClubId` / `sellerClubId` | `transfer_agreed`, `transfer_negotiation_opened`, `transfer_negotiation_broken`, `transfer_counter_demanded` |
| `finalTable[].clubId` | `season_concluded` — le rang **est** la position |

« L'histoire du club X » n'est donc pas une requête, c'est une union de cas. Elle est déclarée **une seule fois**, dans `Read\History\ClubMentions`, sur des objets réhydratés par `Host\Store\EventStore::between()` — un `match` sur classe avec accès typé, que PHPStan vérifie. Un `$payload['homeClubId'] ?? null` compilerait aussi bien avec une faute de frappe.

`Tests\Architecture\EveryFactIsPlacedOrExcludedTest` balaie `FootballTypes::registry()->events` et exige que **chaque** type soit traité ou inscrit dans `NOT_ABOUT_A_CLUB` **avec sa raison**. Sans lui, le prochain Fait ajouté au noyau disparaîtrait en silence de l'histoire de tous les clubs.

### Une saison conclue est citée, une saison en cours est comptée

Pour une saison **conclue**, rang, points, bilan et buts viennent tous de `SeasonConcluded.finalTable` — le procès-verbal que le monde a publié. Rien n'est recalculé.

Ça n'a pas toujours été vrai. L'événement ne portait que l'ordre du classement, et ce lecteur reconstruisait les points depuis les `MatchPlayed` et les barèmes du `Ruleset`. Ce recalcul n'était juste **que par accident** : il suppose que les points ne viennent que de résultats de match, et le premier retrait de points ou forfait aurait fait mentir cette page sur le passé, **sans recours** — `Standings` étant remis à zéro à la saison suivante, l'information n'aurait existé nulle part. Le Fait porte donc sa table depuis le 2026-08-08 (`docs/16-` §2, « un Fait porte de quoi l'attribuer à ses sujets »).

Le chemin « compté » subsiste pour la saison **en cours**, qui n'a pas encore de `SeasonConcluded`, et c'est là que `Host\Rules\RulesetForWorld` trouve son second consommateur — un monde épinglé à des règles que ce Host ne sait pas reconstruire **lève**, plutôt que d'afficher des points calculés avec les mauvais barèmes.

Les deux chemins doivent coïncider tant qu'aucune règle n'attribue de points hors d'un match, et deux tests le tiennent : le comptage de la saison en cours doit égaler le `Standings` du snapshot pour les quatre clubs, et le bloc d'une saison conclue doit égaler la ligne du Fait, champ par champ.

### ⚠️ Trois surprises mesurées

**Une prolongation n'est ni une arrivée ni un départ.** Sur le monde de référence à dix ans, **753 des 819 `ContractSigned` ont le même club avant et après** — contre 25 vrais transferts et 41 signatures de joueurs sans club. La première version de ce lecteur les comptait comme des arrivées, et la page annonçait « 7 arrivées » pour un club qui n'avait recruté personne. Vu en ouvrant la vraie page, pas par un test.

**Le seau 0 n'est pas la première saison de compétition.** Le découpage est `intdiv($tick, 365)`, et une saison est générée quand `tick % 365 === 0`, donc **au tick 365, qui tombe dans le seau 1**. Le seau 0 couvre la première année du monde : mercato et intake, mais aucun match. Le découpage est juste — tout ce qui concerne une saison (génération, journées, clôture, mercato, transferts) tombe dans un seul seau — mais il faut le savoir.

**Deux Faits ne pouvaient pas entrer dans l'histoire d'un club — soldé.** `PlayerRetired` ne portait que `playerId` et `ageYears`, `TransferCounterDemanded` que la négociation : les retraites d'un club étaient invisibles, et les reconstruire depuis les `ContractSigned` aurait été **silencieusement faux** (les contrats du genesis ne sont pas dans l'event log). C'était le contrôle qualité des seuils d'émission que `docs/14-` §9 promettait, arrivé plus tôt que prévu — et il a donné une règle générale, écrite dans `docs/16-` §2. Les deux Faits portent leurs clubs depuis le 2026-08-08 ; `NOT_ABOUT_A_CLUB` ne compte plus que **trois** exclusions, toutes de vraies décisions.

### Coût, et le seuil à surveiller

Sur `dix-ans` (club 11, tick 3650, 4 610 Faits dans le monde dont **402 le concernent**) : page **57,1 ms**, JSON **54,1 ms**.

On charge l'intervalle complet et on filtre en PHP. Le filtre SQL serait ~8× plus rapide (2,17 ms en `Seq Scan` avec des `payload @>`), mais **dupliquerait la correspondance club ↔ clé** en deux endroits, l'un en PHP typé et l'autre en chaînes SQL — exactement la divergence que `ClubMentions` existe pour empêcher.

L'échappatoire, si un monde vieillit assez : **dériver les prédicats SQL de la même déclaration**. Une source, la vitesse du SQL. Le déclencheur est mesurable — `ClubHistoryView::$factsRead`, autour de 23 000 Faits, soit une cinquantaine d'années.

## La vérité cachée, et qui a le droit de la voir

Ces pages montrent la **vérité** du monde : la note au meilleur poste calculée sur les compétences réelles. C'est légitime parce que la seule surface qui existe aujourd'hui est celle d'**exploitation** — un exploitant voit son monde, sinon il ne peut pas l'inspecter, et la Phase 4 lui demande d'« explorer et éditer » (`docs/15-` §4).

Ce n'est **pas** un réglage de client. Deux droits distincts, à ne pas confondre :

- **Ce que voient les *joueurs*** — une option du **monde**, pas du client. FM appelle ça l'*attribute masking* et laisse le joueur le désactiver, mais FM est solo : dans un monde partagé, un client qui décide seul de voir la vérité obtient un avantage sur les autres agents du même marché, et le scouting ne vaut plus rien. Le choix appartiendra donc au monde, comme son couple `(kernelVersion, rulesetVersion)` (`docs/12-` §6).
- **Ce que voit l'exploitant** — pas une question de jeu. Toujours tout, dans tous les mondes.

**Rien de ce mécanisme n'est dans ce lot**, parce qu'il n'a aucun consommateur : il n'y a pas de client de jeu en Phase 4. Ce que le lot fait, c'est **nommer le site** — `Read\ClubSheetReader::qualityOf()`, et nulle part ailleurs. Le jour où `game-web` lira ces fiches, `PerceptionModel::estimate()` y prendra la place, avec l'observateur et son jugement.

Et ce n'est pas un espoir : `estimate()` rend un `int` sur la **même échelle 1-100** que `WageModel::quality()` (vérifié, pas supposé), donc `SquadPlayerView` ne changera pas de forme. C'est pour la même raison qu'il n'y a pas de champ « attributs détaillés » sur ce DTO — la moitié de ses champs n'auraient aucun équivalent perçu, et il faudrait alors deux formes au lieu d'une.

> ⚠️ **Le prix de la fusion admin + API.** Un seul processus sert les pages omniscientes et le JSON : « l'admin voit tout » est à une erreur de routage de « tout le monde voit tout ». Contenu par le **type**, pas par une vérification à l'exécution — le jour où un service de lecture pour le jeu existera, sa signature prendra un `observerId` et n'aura structurellement aucune méthode capable de rendre la vérité cachée. Pas de booléen qui descend la pile, pas de `if` qu'on peut oublier.

## Les classes

- **`Read\WorldReader`** — la porte d'entrée : un `worldId`, un monde décodé. Réutilise `Host\Store\SnapshotStore::latest()`, donc passe par `WorldSnapshot::fromArray()` et ses gardes de version : un monde écrit par un noyau que celui-ci ne sait plus lire **lève**, au lieu de s'afficher à moitié faux.
- **`Read\LoadedWorld`** — le monde décodé, son tick (qui vient de l'**enveloppe** du snapshot, pas du `WorldState` — le noyau n'y stocke pas le tick), et de quoi en déduire saison et jour de l'année.
- **`Read\ClubSheetReader`** — la fiche. Porte `qualityOf()`, le point de bascule ci-dessus.
- **`Read\WorldSummaryReader`**, **`Read\StandingsReader`**, **`Read\WorldListReader`**.
- **`Read\History\ClubMentions`/`ClubMention`/`ClubRole`** — quels clubs un Fait concerne, et à quel titre. Le seul endroit du projet qui le sait.
- **`Read\History\ClubHistoryReader`** — les blocs par saison.
- **`Read\View\*`** — les DTO. Plats, `readonly`, sérialisables tels quels : c'est le contrat que HTML et JSON partagent.
- **`Format\Money`** — centimes → euros. Le noyau ne connaît que des **centimes entiers**, ce qui rend l'invariant monétaire exact ; la conversion n'appartient qu'à l'affichage, jamais à un DTO (un DTO porteur de chaînes formatées ne serait plus exploitable par un client qui veut recalculer).
- **`App\Providers\AppServiceProvider`** — le seul endroit où Laravel et `host` se touchent.

## `src/` et `app/` : deux racines, et ce qui les tient

| | |
|---|---|
| `src/` → `Flair\Api\` | la lecture du monde et son formatage. **PHP nu** : ne connaît que `flair/host` et `flair/kernel`. |
| `app/` → `App\` | l'adaptation à Laravel : contrôleurs, service provider. Assemble, ne lit rien. |

La dépendance est **à sens unique** : `App\` connaît `Flair\Api\`, jamais l'inverse.

Deux raisons de le faire ainsi, et une mauvaise à écarter :

- **La convention du dépôt.** `CLAUDE.md` dit « namespace racine `Flair\` », et les quatre autres paquets sont `Flair\Kernel\`, `Flair\Host\`, `Flair\Worldgen\`, `Flair\Harness\`, tous dans `src/`. Ici c'est `App\` qui est l'import de Laravel-land, pas `src/` qui est exotique.
- **Le cœur de lecture est substantiel et n'a rien à voir avec HTTP.** Neuf classes qui lisent un ECS ; Laravel n'y sert à rien. Le digest (lot 3), SSE (lot 4) et `game-web` réutiliseront cette couche.
- ~~« Le jour où l'admin sortira en paquet séparé, `src/` part sans rien toucher »~~ — c'est vrai, mais c'est un **bonus, jamais la justification**. Une frontière posée pour un futur hypothétique serait l'anticipation que ce projet refuse partout ailleurs.

> ⚠️ **Deux racines coûtent une décision par fichier**, et une frontière qui coûte ça sans rien garantir est **pire que pas de frontière**. Elle est donc mécanisée, pas conventionnelle — sans quoi il faudrait tout ramener sous `App\` et l'assumer.

Ce qui la tient, et qui a été éprouvé par sabotage :

- **`Tests\Architecture\ReadLayerStaysFrameworkFreeTest`** balaie le disque — même idiome que `SnapshotConformanceTest` du kernel, parce qu'une liste écrite à la main aurait le défaut qu'on corrige. Il interdit à `src/` d'importer `Illuminate\`, `Symfony\`, `Laravel\` **et `App\`** (le sens unique), et interdit à un contrôleur d'importer `Host\Store\*` ou `Core\Snapshot\*`. Sabotages vérifiés : un `use Illuminate\Support\Collection` dans `WorldReader`, et un `use SnapshotStore` dans `ClubController` — détectés tous les deux.
- **`Tests\ReadTestCase` n'étend pas la classe de test de Laravel.** Ce n'est pas une économie de millisecondes : un test qui boote une application HTTP pour vérifier qu'un effectif est trié par note teste deux choses au lieu d'une. Étendre `PHPUnit\Framework\TestCase` **prouve** que la couche de lecture n'a besoin d'aucun framework, là où le test d'architecture le vérifie à la lecture des imports.
- **`Tests\Support\WorldFixture`** sème les mondes jetables, et existe parce qu'il a **deux consommateurs réels** — `TestCase` (qui boote Laravel, pour les routes) et `ReadTestCase` (qui ne boote rien).

> **Conséquence pratique à connaître** : `ReadTestCase` prend sa connexion dans l'**environnement** (`DatabaseConfig::fromEnvironment()`, défauts du `docker-compose.yml`), comme toute la suite de `flair/host`, puisqu'il n'y a personne pour charger le `.env`. `TestCase` la prend dans le **conteneur**, donc du `.env`. Si ta base n'est pas sur les réglages par défaut, les tests de lecture demandent de vrais `FLAIR_DB_*` exportés.

### Lire l'ECS sans être un système

`WorldState::components()` est public et **génériquement typé** (`@template T of object`) : `->components(Club::class)->get($id)` est vu `?Club` par PHPStan, sans cast ni assertion. Rien à ajouter au noyau, et pas de `mixed` à vérifier comme le fait `Host\Store\Row` sur les lignes du query builder.

Ces lecteurs ne sont pas des `System` et n'ont pas de `SystemContext` : ils ne déclarent rien, ne peuvent rien ordonner, et n'écrivent jamais. Une lecture n'a pas de place dans le pipeline.

## Ce que le squelette Laravel a perdu, et pourquoi

`composer create-project laravel/laravel` livre une application qui suppose avoir **sa propre base**. Ce paquet n'en a pas : il lit celle du monde par `flair/host`. Ont donc été retirés — pas par goût du ménage, mais parce que chacun mentait sur la nature de l'application :

- **`database/`** en entier (SQLite, migrations `users`/`cache`/`jobs`, seeders, factories) et **`app/Models/User.php`**. Pas d'auth en Phase 4, pas de file d'attente, pas de cache en base. `SESSION_DRIVER`, `CACHE_STORE` et `QUEUE_CONNECTION` passent en `file`/`sync` : les défauts de Laravel (`database`) exigeraient des tables qui n'existent pas et n'ont pas à exister.
- **`config/auth.php`**, qui référençait le modèle supprimé.
- **`package.json`, `vite.config.js`, `resources/js`, `resources/css`.** L'admin est rendu serveur ; le CSS est en ligne dans `resources/views/layout.blade.php`. Un pipeline npm qu'on n'exécute jamais est exactement le genre de pièce morte qui pourrit sans qu'on le voie.
- **La commande `inspire`** de `routes/console.php`. Le CLI du monde est celui de `host` ; en ajouter ici dupliquerait cette surface, ou donnerait à une application de lecture les moyens d'écrire.

`config/database.php` reste, inutilisé : c'est du mobilier du framework, et `host` ouvre sa propre connexion en mode Capsule sans `setAsGlobal()`. L'application n'ouvre donc **pas** une seconde connexion vers les mêmes tables, et personne n'est tenté d'écrire de l'Eloquent sur `events`.

> **Piège Composer à connaître : les `path` repositories ne sont pas transitifs.** Composer ne lit que ceux du paquet **racine**. `api` doit donc déclarer `../worldgen` et `../kernel` en plus de `../host`, même s'il ne dépend directement que de `host` — sinon la résolution échoue sur `flair/kernel @dev could not be found`.

## Tests et analyse

```bash
vendor/bin/phpunit    # exige une vraie base : docker compose up -d db
composer analyse      # phpstan niveau max
```

Même doctrine que `flair/host` : une **vraie** base PostgreSQL, jamais un double. Ce paquet ne contient à peu près aucune logique propre, il lit un monde — un double ne testerait que le double, et laisserait passer ce qui compte, à savoir qu'un snapshot écrit par le Host se relit et se présente correctement. La suite se **skippe** proprement si aucune base n'est joignable.

> **PHPStan niveau max couvre la couche HTTP**, contrôleurs, routes et tests inclus. C'est la leçon de `packages/harness/public/`, cassé depuis le lot worldgen (il importe encore `Harness\Population\PopulationFactory`, renommé `Worldgen\WorldFactory`) sans que personne le voie — parce qu'il était explicitement hors périmètre, « superglobales HTTP incompatibles avec le niveau max ». C'est la vraie raison d'avoir pris un framework ici : son objet `Request` fait disparaître les superglobales, donc l'exclusion n'a plus de prétexte.
>
> Seule exception, et bornée : `config/` n'est analysé que pour `config/flair.php`. Les autres fichiers sont ceux du squelette, ils font `(string) env(...)` sur du `mixed`, et les corriger serait modifier du code qui n'est pas le nôtre et qui dérivera à la prochaine mise à jour.

Les vues Blade ne sont pas analysées (elles compilent en PHP dans `storage/`). Elles sont couvertes autrement, et mieux : `PagesMatchJsonTest` rend chaque page et exige d'y retrouver les chiffres de la route JSON.

## Ce que ce lot ne fait pas

Aucune écriture du monde — **lecture seule**. Éditer un monde persisté signifierait écrire un snapshot hors de `Host\AdvanceWorld`, donc percer l'atomicité mono-writer qui fait tenir le critère de sortie de la Phase 3 ; c'est une décision à part.

Restent pour la Phase 4 : le digest de retour d'absence (lot 3), et SSE (lot 4, hors critère de sortie — à un tick par heure il ne vaut pas mieux qu'un rafraîchissement).
