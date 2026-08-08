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
- **`EventStore`** — l'event log append-only. `type` prend les **clés stables** de `Core\Snapshot\TypeRegistry`, jamais un FQCN : c'est le second consommateur réel du registre, celui qui justifiait de l'écrire au lot snapshot.
- **`SnapshotStore`** — un snapshot par tick, rétention des N derniers. Le chargement passe par `WorldSnapshot::fromArray()` pour bénéficier des gardes de version — contourner ces gardes serait échanger la seule protection contre un rejeu déguisé (`docs/13-` §6) contre un tableau intermédiaire.
- **`WorldLock`**, **`AdvanceWorld`**, **`CreateWorld`**, **`AdvanceOutcome`/`AdvanceResult`**, **`Row`/`UnexpectedColumn`** (lecture typée : le query builder rend du `mixed`, et un cast transformerait silencieusement `null` en tick 0).

## `jsonb` pour les Faits, `json` pour les snapshots

`events.payload` est en **`jsonb`** : les projections de la Phase 4 devront l'interroger et l'indexer.

`snapshots.state` est en **`json`**, et c'est un choix corrigé après mesure. `jsonb` ne stocke pas le texte reçu mais une forme normalisée — **les clés d'objet sont réordonnées**. Un état relu depuis `jsonb` n'est donc plus identique octet pour octet à ce que le noyau a produit, alors que `SnapshotCodec` garantit précisément cette stabilité. La relecture reste correcte (le décodage cherche ses clés par nom), mais la propriété se perdait en silence à la frontière de la base et rendait impossible un test de parité. `json` conserve le texte tel quel ; on n'y perd rien, personne n'interroge l'intérieur d'un snapshot.

## Mesures (500 joueurs / 18 clubs, 300 ticks)

| | |
|---|---|
| Simulation | **18,7 ms/tick** |
| Persistance | **17,8 ms/tick** |
| Taille d'un snapshot | **0,39 Mo** de JSON |

L'écriture en base coûte donc à peu près autant que le noyau lui-même — c'est la première confirmation chiffrée de `docs/13-` §7 (« les vrais coûts sont l'écriture en base, pas le CPU »). À un tick par heure, les deux sont sans objet.

> **Ballonnement de table à connaître.** Un snapshot par tick avec rétention, c'est une insertion et une suppression par tick : la table `snapshots` est montée à **23 Mo** pour 1,2 Mo de données vivantes après 300 ticks joués en cinq secondes, puis **304 ko** après un `VACUUM FULL`. Ce sont des tuples morts qu'autovacuum n'avait pas encore ramassés. À la cadence réelle (24 ticks/jour) le problème ne se pose pas ; il ne se poserait qu'en rattrapage massif.

## Tests

```bash
vendor/bin/phpunit    # exige une vraie base : docker compose up -d db
composer analyse      # phpstan niveau max
```

Toute la suite tourne sur **une vraie base PostgreSQL**, jamais un double : ce que ce package apporte est *ce que la base garantit* (transaction atomique, verrou advisory), et un double ne testerait que le double. La suite se **skippe** proprement si aucune base n'est joignable.

`CrashRecoveryTest` est le critère de sortie de la Phase 3 pris au mot : un vrai sous-processus, un vrai **SIGKILL** en plein vol, trois fois de suite, puis vérification que la base est cohérente et que le monde repris est identique à un monde jamais interrompu. Sa limite est documentée dans son propre docblock — c'est un filet probabiliste, pas une preuve ; la garantie est structurelle.
