# CI

Un seul workflow, `ci.yml`. Il tourne côté GitHub (Actions), jamais localement — ce document explique ce qu'il vérifie, quand il se déclenche, et comment reproduire les mêmes vérifications avant de pousser.

## Pourquoi il existe

Rien n'oblige à relancer les vérifications avant de commiter. Le CI le fait automatiquement, pour attraper une régression tout de suite plutôt qu'après dix systèmes de plus.

Il a couvert `kernel` et `harness` seuls jusqu'au 2026-08-08 — donc **ni `worldgen`, ni `host`, ni `api`**, soit toute la persistance et toute la couche de lecture. C'était la dette D1 de `docs/18-dettes.md`. Les cinq paquets qui ont des tests sont désormais dans le workflow.

## Déclenchement

```yaml
on:
  push:
    branches: [master]
  pull_request:
```

- un push vers `master`,
- **ou** l'ouverture/mise à jour d'une pull request, quelle que soit la branche source.

> ⚠️ Ce déclencheur disait `main` jusqu'au 2026-08-08, alors que la branche principale de ce dépôt est `master` et qu'aucune branche `main` n'a jamais existé. **Le déclencheur `push` n'avait donc jamais tourné une seule fois** : seules les pull requests lançaient le CI. À ne pas réapprendre — un déclencheur qui ne se déclenche pas est silencieux par nature.

Un push sur une branche de travail (`feat/...`) qui n'a pas de PR ouverte ne déclenche rien. Pour voir le CI tourner sur du travail en cours, ouvrir une PR :
```bash
git push -u origin <branche>
gh pr create
```

## Les cinq jobs

Tous ont `needs: kernel` — inutile de faire tourner quoi que ce soit sur un noyau cassé. `harness`, `worldgen`, `host` et `api` tournent ensuite **en parallèle** : ils éprouvent des choses différentes (le calibrage, la genèse, l'atomicité en base, la couche de lecture), et un seul run donne les quatre signaux.

```
kernel ──┬── harness
         ├── worldgen
         ├── host
         └── api
```

### `kernel`, `harness`, `worldgen`
```bash
cd packages/kernel   && composer install --no-interaction && vendor/bin/phpunit && vendor/bin/phpstan analyse
cd packages/worldgen && composer install --no-interaction && vendor/bin/phpunit && composer analyse
cd packages/harness  && composer install --no-interaction && vendor/bin/phpunit && composer analyse && vendor/bin/phpunit --testsuite Regression
```

> `harness` et `worldgen` appellent **`composer analyse`** et non `vendor/bin/phpstan analyse` : le script porte `--memory-limit=1G`, et depuis que `harness/public/` est analysé le défaut de 128 Mo ne suffit plus (constaté : OOM en plein worker).

Le détail de ce que couvre `CalibrationRegressionTest` (bornes, seeds, effectif attendu) est documenté dans `packages/harness/README.md`, section « Tests et qualité » — pas dupliqué ici.

### `host` et `api` — les deux jobs à base de données

Les deux montent un **service Postgres qui reproduit `docker-compose.yml` à l'identique** : même image `postgres:16-alpine`, mêmes identifiants `flair`/`flair`/`flair`, et **même port 54329**. Ce n'est pas une coquetterie : ce sont les défauts de `Host\Database\DatabaseConfig`, donc le CI exécute la configuration documentée plutôt qu'une configuration parallèle, et un échec se reproduit en local sans traduire de réglages. Aucun `FLAIR_DB_*` n'est passé nulle part.

Contrepartie assumée : le chemin de surcharge par variables d'environnement (`DatabaseConfig::fromEnvironment()`) reste non exercé en CI. C'est cinq `getenv()`, et le couvrir coûterait de faire diverger CI et poste de dev.

```bash
cd packages/host && composer install --no-interaction && vendor/bin/phpunit --fail-on-skipped && composer analyse

cd packages/api  && cp .env.example .env \
                 && composer install --no-interaction \
                 && php artisan key:generate \
                 && vendor/bin/phpunit --fail-on-skipped && composer analyse
```

Deux choses qui ne se devinent pas en lisant le YAML :

**1. `--fail-on-skipped` est le cœur du job, pas un détail.** Les suites de `host` et `api` se **skippent** proprement quand aucune base n'est joignable (`Host\Tests\DatabaseTestCase`, `Api\Tests\ReadTestCase`, `Api\Tests\TestCase`). C'est le bon comportement sur un poste de dev — un `docker compose up -d db` oublié est une machine mal préparée, pas une régression. **En CI c'est l'inverse** : un service mal câblé rendrait un job vert n'ayant rien exécuté. Mesuré, base arrêtée : sans le drapeau `exit=0`, avec le drapeau `exit=1`. Le drapeau ne peut rien attraper d'autre que ce cas — ce sont les trois seuls `markTestSkipped` du dépôt.

Le job ne lance pas `bin/host.php install` : les deux suites installent leur schéma elles-mêmes.

**2. Le `.env` d'`api` est nécessaire, et il est ignoré par git.** Les tests HTTP (`Api\Tests\TestCase`) prennent leur connexion dans le conteneur Laravel, donc dans `config/flair.php`, donc dans le `.env` — là où `Api\Tests\ReadTestCase` lit `getenv()`. `.env.example` porte déjà les défauts du `docker-compose.yml`, donc les deux chemins tombent juste sans un seul override, et sans la question de préséance `.env` vs environnement réel.

## Reproduire localement avant de pousser

Exactement les mêmes commandes que ci-dessus, avec la base démarrée pour les deux derniers :
```bash
docker compose up -d db
```

> ⚠️ **Deux écarts entre le CI et le poste de dev, à connaître avant de débugger un rouge qui n'est pas reproductible.** Le CI tourne sur **PHP 8.3** (le plancher supporté), la machine de dev est plus avancée. Et **aucun `composer.lock` n'est suivi** (le `.gitignore` racine l'ignore), donc chaque job résout ses dépendances à neuf : le CI peut installer des versions que le poste de dev n'a pas. Les deux sont des choix, pas des oublis, mais ils expliquent la classe de panne « vert en local, rouge en CI ».

## Suivre un run

```bash
gh run list                 # runs recents, avec leur statut
gh run view <id> --log      # detail d'un run (utile si un job echoue)
```
