# `flair/host` — un monde qui vit en base

Fait tourner un monde en continu : event store, snapshots, verrou mono-writer, et la commande qui avance le monde d'un tick puis sort. Dépend de `kernel` et `worldgen` (path repositories), jamais l'inverse (`docs/11-` §7).

```
kernel   → (rien)
worldgen → kernel
host     → kernel, worldgen
api      → host                    (Phase 4)
```

## Démarrer

```bash
docker compose up -d db            # depuis la racine du repo, PostgreSQL sur le port 54329
composer install
php bin/host.php install           # crée worlds, events, snapshots
php bin/host.php create alpha --players=500 --clubs=18 --seed=42
php bin/host.php advance alpha     # un tick
php bin/host.php status
php bin/host.php events alpha --limit=20
```

Connexion par variables d'environnement, dont les défauts sont ceux du `docker-compose.yml` : `FLAIR_DB_HOST`, `FLAIR_DB_PORT`, `FLAIR_DB_NAME`, `FLAIR_DB_USER`, `FLAIR_DB_PASSWORD`.

En production, une ligne de cron suffit — c'est le grain naturel de PHP, et il tient largement à `1 tick = 1 jour simulé` :

```cron
0 * * * * cd /chemin/packages/host && php bin/host.php advance alpha
```

## Pourquoi `illuminate/database` et pas Laravel

Le graphe dit `api → host` : **`host` est une dépendance, pas une application.** `laravel/laravel` est un skeleton d'application (`bootstrap/app.php`, `public/index.php`, routes, providers) et deux skeletons ne se composent pas. La Phase 4 mettra une application Laravel complète dans `api`, qui importera ce paquet. Laravel Zero poserait le même problème en plus petit.

Ce qu'on importe travaille vraiment : gestionnaire de connexion, constructeur de schéma, transactions. Le CLI est un script PHP nu — l'idiome déjà en place dans le repo (`harness/bin/*.php`) — parce qu'un framework console n'aurait ici aucun second consommateur. Le jour où ce CLI dépassera ~8 commandes, `symfony/console` s'ajoutera : c'est exactement la couche sur laquelle Artisan lui-même est construit (`illuminate/console` requiert `symfony/console`), donc rien ne serait à réécrire si `host` finissait absorbé dans une app Laravel.

## Le cœur : une seule transaction

`AdvanceWorld` fait **tout** dans un unique bloc atomique — verrou, lecture du snapshot, `step()`, écriture des Faits, écriture du nouveau snapshot, mise à jour du tick. C'est cette atomicité, et elle seule, qui rend vrai le critère de sortie de la Phase 3 : tuer le processus à n'importe quel instant laisse la base **avant** le tick ou **après**, jamais au milieu.

Un snapshot en avance ou en retard d'un tick sur l'event log rendrait l'histoire du monde fausse sans rien casser de visible. C'est le mode de panne que la structure exclut, plutôt que la vigilance.

**Le verrou est `pg_try_advisory_xact_lock`, et les deux mots comptent.** `try` : un second processus repart immédiatement au lieu d'empiler des exécutions qui referaient le même travail — le monde n'attend personne. `xact` : le verrou tombe au commit, au rollback **et si le processus meurt**.

> ⚠️ **Le verrou survit brièvement au processus mort** — mesuré **0,7 à 3,4 ms** après un SIGKILL, le temps que PostgreSQL constate la connexion perdue. Un cron qui repasse dans l'heure ne le verra jamais ; un script qui enchaîne mise à mort et reprise immédiate, si. `advance` répond alors `busy` sans rien écrire, ce qui est le comportement correct.

## Les classes

- **`Database`/`DatabaseConfig`** — amorce `illuminate/database` en mode Capsule. Pas de `setAsGlobal()` ni de facades : la connexion est un objet qu'on passe, pas un état global qu'on invoque. `getenv()` est interdit dans le noyau (`docs/11-` §1) ; `host` est précisément la couche dont c'est le rôle.
- **`Schema`** — les trois tables, posées par `install()`. Volontairement **pas** de migrations versionnées : aucun monde en production, donc aucune base à faire évoluer sans la casser. `illuminate/database` embarque de quoi les câbler le jour venu.
- **`WorldRepository`/`WorldRecord`** — l'identité d'un monde et son tick de commodité.
- **`EventStore`** — l'event log append-only. `type` prend les **clés stables** de `Core\Snapshot\TypeRegistry`, jamais un FQCN : c'est le second consommateur réel du registre, celui qui justifiait de l'écrire au lot snapshot. `between()` est le **miroir exact** de `append()` — l'écriture traduit une classe en clé par `keyFor()`, la lecture fait le chemin inverse par `classFor()` puis `ValueCodec`, et rend des `RecordedEvent` porteurs d'objets **réhydratés**. Ça vit ici et pas côté lecteur : personne d'autre n'a à savoir que `events.type` porte une clé de registre. Ce que ce paquet n'apprend **pas** au passage, c'est le football — une méthode `forClub()` ferait entrer le domaine dans la persistance et devrait être retouchée à chaque Fait ajouté au noyau.
- **`RecordedEvent`** — un Fait et sa place dans l'histoire, `(tick, seq)`. Le couple vit dans l'enveloppe et non sur le `DomainEvent` parce que le noyau ne le connaît pas : un Fait ne sait pas *quand* il a eu lieu, il dit seulement ce qui est arrivé. Même partage que `WorldSnapshot`, dont l'enveloppe porte le tick que le `WorldState` ne porte pas.
- **`SnapshotStore`** — un snapshot par tick, rétention des N derniers. Le chargement passe par `WorldSnapshot::fromArray()` pour bénéficier des gardes de version — contourner ces gardes serait échanger la seule protection contre un rejeu déguisé (`docs/13-` §6) contre un tableau intermédiaire.
- **`Rules\RulesetForWorld`/`UnsupportedRulesetVersion`** — traduit le `rulesetVersion` d'un monde en `Ruleset`, et **lève** pour toute version qu'il ne sait pas reconstruire. Le garde vit ici et pas dans le noyau parce que là-bas la version est une **étiquette** libre, dont le harness se sert comme telle (`'harness'`, `'ci'`, `'snapshot-continuity'`…) ; dans un monde persisté, la même chaîne sert à *reconstruire* les règles des mois plus tard, et une chaîne qui ne les détermine pas donne un monde qui tourne selon des règles qui ne sont pas les siennes. `AdvanceWorld` faisait `new Ruleset($world->rulesetVersion)`, ce qui rendait les défauts du noyau quoi qu'il arrive. Au-delà de rendre l'erreur bruyante, le garde rend une classe entière de désaccord **inatteignable** : `Worldgen\WorldFactory::populate()` accepte des groupes de `Balance` que `CreateWorld` ne lui passe pas, donc le genesis utilise toujours les défauts — tant qu'une seule version est acceptée, genesis et avancement lisent forcément les mêmes règles. C'est le seul site que `packages/ruleset` aura à rebrancher.
- **`WorldLock`**, **`AdvanceWorld`**, **`CreateWorld`**, **`AdvanceOutcome`/`AdvanceResult`**, **`Row`/`UnexpectedColumn`** (lecture typée : le query builder rend du `mixed`, et un cast transformerait silencieusement `null` en tick 0).

> **`CreateWorld` prend une version, plus un `Ruleset`.** Le paramètre promettait un réglage qu'il n'appliquait nulle part : le genesis n'en lisait que `->version`, donc un `Balance` sur mesure était silencieusement ignoré à la création puis reconstruit aux défauts à l'avancement.

## `jsonb` pour les Faits, `json` pour les snapshots

`events.payload` est en **`jsonb`** : les projections de la Phase 4 devront l'interroger et l'indexer.

`snapshots.state` est en **`json`**, et c'est un choix corrigé après mesure. `jsonb` ne stocke pas le texte reçu mais une forme normalisée — **les clés d'objet sont réordonnées**. Un état relu depuis `jsonb` n'est donc plus identique octet pour octet à ce que le noyau a produit, alors que `SnapshotCodec` garantit précisément cette stabilité. La relecture reste correcte (le décodage cherche ses clés par nom), mais la propriété se perdait en silence à la frontière de la base et rendait impossible un test de parité. `json` conserve le texte tel quel ; on n'y perd rien, personne n'interroge l'intérieur d'un snapshot.

## Mesures — dix ans en base

Mesuré le 2026-08-08, monde de référence (500 joueurs, 18 clubs, graine 42), **3 650 ticks = dix ans à un tick par jour**, joués d'un seul trait par `advance --ticks=3650`. C'est le jeu de données sur lequel la Phase 4 va construire ses écrans, d'où la mesure avant l'écran plutôt qu'après.

| | |
|---|---|
| Durée totale | **177 s** pour 3 650 ticks, soit **48,5 ms/tick** |
| dont simulation | 17,5 ms/tick |
| dont écritures dans la transaction | 17,2 ms/tick |
| dont lecture du snapshot | 5,6 ms/tick |
| dont le reste (~8 ms) | commit, verrou, lecture de `worlds` |
| Faits journalisés | **4 610** sur dix ans, soit ~460 par saison |
| `events` | **1,5 Mo** pour 4 734 lignes |
| `snapshots` | **104 Mo** en fin de run, **808 ko** après `VACUUM FULL` |
| Taille d'un snapshot | **0,39 Mo** de JSON (1,2 Mo pour les 3 retenus) |

L'écriture en base coûte à peu près autant que le noyau lui-même — confirmation chiffrée de `docs/13-` §7 (« les vrais coûts sont l'écriture en base, pas le CPU »). À un tick par heure, les deux sont sans objet.

> ⚠️ **Ce que `advance` imprime sous-estime le coût réel.** Les deux compteurs de `AdvanceWorld` totalisent 34,7 ms alors que le tick coûte 48,5 ms. L'écart n'est pas du bruit : `SnapshotStore::latest()` est appelé **avant** `$startedSimulation`, et le commit de la transaction arrive **après** le retour de la closure. Les deux sont donc hors des compteurs. Pour juger d'un coût, lire la durée du processus, pas les moyennes affichées.

> **Ballonnement de `snapshots`, et sa vraie nature.** Un snapshot par tick avec rétention 3, c'est une insertion et une suppression de ~0,4 Mo par tick : la table monte à **104 Mo pour 808 ko de données vivantes** après 3 650 ticks joués en trois minutes — un facteur 128. Ce n'est *pas* qu'autovacuum n'a rien ramassé (il ne reste que 96 tuples morts) : il a bien libéré l'espace, mais **PostgreSQL ne rend pas les pages au système**, il les garde réutilisables dans le fichier. Les 104 Mo sont donc un plafond que les ticks suivants réutilisent, pas une fuite. À la cadence réelle (24 ticks/jour) ce plafond ne se forme jamais ; il ne se forme qu'en rattrapage massif, et un `VACUUM FULL` le remet à plat.

### Le mélange de Faits, avant d'écrire le digest

```
football.event.match_played                 2754   (60 %)
football.event.contract_signed               819
football.event.player_retired                359
football.event.youth_player_promoted         214
football.event.contract_expired              192
football.event.transfer_counter_demanded      96
football.event.club_invested_in_facilities    57
football.event.transfer_negotiation_opened    50
football.event.transfer_negotiation_broken    33
football.event.transfer_agreed                17
football.event.season_started                 10
football.event.season_concluded                9
```

Douze types sur les quatorze enregistrés. Les deux absents ne le sont pas par accident : `season_ended` et `fixture_kickoff` passent par le Scheduler (`SystemContext::schedule()`) et ne sont donc **jamais journalisés** — seuls les Faits *émis* le sont.

Trois mois de digest (`docs/14-` §9), c'est donc ~115 Faits dont ~70 `MatchPlayed`. C'est un journal, pas une histoire — l'information à avoir avant de construire le lot du digest, et le contrôle qualité des seuils d'émission que `docs/14-` §9 promettait.

### Le monde à dix ans

Composants du dernier snapshot : 18 clubs (avec `Finances`, `Facilities`, `SeasonIncome`, `BoardPatience`, un `Scout` employé chacun), 355 joueurs dont **297 sous contrat**, 306 `Fixture`, 1 `Competition`, 1 `Standings`.

Deux choses valent d'être notées, aucune n'étant du ressort de ce package :

- **`Person` s'accumule sans fin.** 732 `Person` pour 373 entités vivantes (355 joueurs + 18 recruteurs) : l'écart de 359 est exactement le nombre de `PlayerRetired`. `Football\RetirementSystem::removes()` retire les quatre composants de compétences mais **garde `Person`** — plausiblement voulu (on veut le nom d'une légende retraitée), mais rien ne le documente, et l'état du monde croît donc linéairement avec son histoire. Sans objet à dix ans, à revoir avant qu'un monde en vive cent.
- **L'équilibre compétitif sur une fenêtre de dix ans est trompeur.** Neuf saisons conclues, et le club 17 en gagne **sept d'affilée**. Le harness sur le **même build** et la même graine, en 39 saisons : 12 champions distincts, Gini des titres 0,608, rotation du top 5 48,9 %. Le monde en base n'a donc rien d'anormal — c'est le même monde vu par une fenêtre trop courte, et c'est le piège que `CLAUDE.md` signale déjà (« un Gini lu sur une seule graine est du bruit »). Aucune conclusion de régression n'est tirée ici : la comparer aux chiffres notés dans les documents serait précisément la comparaison interdite, qui doit se faire à graines appariées dans un même build.

### « Dix ans d'histoire d'un club » : aucun index à poser

`events` porte la primaire `(world_id, tick, seq)` et un index `(world_id, type)`. Rien ne sert un filtre sur le club — et le club n'a même pas de clé unique : `MatchPlayed` porte `homeClubId`/`awayClubId`, `ContractSigned` porte `clubId`, `SeasonConcluded` un tableau `finalRanking`. L'histoire d'un club est donc une **union de prédicats par type**, pas un `payload->>'clubId'`.

Mesuré sur les dix ans, les cent derniers Faits du club 11 :

```sql
EXPLAIN (ANALYZE, BUFFERS) SELECT tick, seq, type, payload FROM events
WHERE world_id = 'dix-ans'
  AND (payload @> '{"homeClubId": 11}' OR payload @> '{"awayClubId": 11}' OR payload @> '{"clubId": 11}')
ORDER BY tick DESC, seq DESC LIMIT 100;
```

`Seq Scan`, **2,17 ms**, 385 lignes retenues sur 4 733, tout en cache. Toute la table tient dans 1,5 Mo. **Conclusion : aucun index, aucune projection.** Le seuil à surveiller n'est pas le nombre de saisons mais le nombre de mondes — dix ans coûtent 1,5 Mo d'event log, et le `world_id` en tête de la primaire découpe déjà proprement.

## Tests

```bash
vendor/bin/phpunit    # exige une vraie base : docker compose up -d db
composer analyse      # phpstan niveau max
```

Toute la suite tourne sur **une vraie base PostgreSQL**, jamais un double : ce que ce package apporte est *ce que la base garantit* (transaction atomique, verrou advisory), et un double ne testerait que le double. La suite se **skippe** proprement si aucune base n'est joignable.

`PersistedWorldMatchesMemoryTest` garantit que **persister ne change rien au monde** : le même monde avancé par le Host, avec un aller-retour complet en base à chaque tick, doit être identique à celui d'un processus qui n'a jamais rien écrit.

> ⚠️ **Il tournait sur 120 ticks, et c'était un trou.** `CalendarBalance::$seasonStartDayOfYear` vaut 0 et un monde naît au tick 0 : la première saison n'est générée qu'au **tick 365**. En 120 ticks l'aller-retour n'avait donc jamais traversé un match, une fin de saison, un contrat renouvelé ni une négociation de transfert — **tout ce que la Phase 2 a construit était hors du seul test qui garantit ça.** Corrigé le 2026-08-08 : 575 ticks, ce qui fait traverser la base à une `Negotiation` **en cours de vol**, le seul état multi-tick du noyau.

Deux détails de ce test valent d'être connus avant d'y toucher :

- **La couverture est vérifiée, pas supposée.** `MUST_COVER` exige dix types de Faits dans l'event log du run. Sans elle, déplacer un jour-de-l'année du `Ruleset` ramènerait ce test à une parité sur un monde où il ne se passe rien, sans qu'aucune assertion ne rougisse — c'est exactement ce qui s'était produit. Sabotage vérifié : à 120 ticks le test échoue en annonçant qu'il n'a jamais rencontré que `player_retired`.
- **Quinze joueurs par club, un chiffre à ne pas monter à la légère.** Dix par club (l'ancien 40/4) ne composent pas un onze. Vingt-cinq par club saturent les effectifs, plus aucun club n'est en manque et **le marché des transferts ne s'ouvre jamais** — mesuré : 4/100, 4/140, 6/150 et 8/200 n'ouvrent aucune négociation en 600 ticks. Au passage : le marché n'ouvre qu'au jour 200 de l'**année 2**, jamais de l'année 1, parce qu'au genesis aucun club n'est en manque.

`CrashRecoveryTest` est le critère de sortie de la Phase 3 pris au mot : un vrai sous-processus, un vrai **SIGKILL** en plein vol, trois fois de suite, puis vérification que la base est cohérente et que le monde repris est identique à un monde jamais interrompu. Sa limite est documentée dans son propre docblock — c'est un filet probabiliste, pas une preuve ; la garantie est structurelle.
