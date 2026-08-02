# CI

Un seul workflow, `ci.yml`. Il tourne côté GitHub (Actions), jamais localement — ce document explique ce qu'il vérifie, quand il se déclenche, et comment reproduire les mêmes vérifications avant de pousser.

## Pourquoi il existe

Le noyau (`kernel`) et le harness d'équilibrage sont couverts par des tests, PHPStan niveau max, et un garde-fou de calibrage (`packages/harness/tests/Regression/CalibrationRegressionTest.php`) qui vérifie que la population reste stationnaire et que la répartition des scores reste plausible (`docs/15-roadmap.md` §4, Phase 0). Rien n'oblige aujourd'hui à relancer ces vérifications avant de commiter — le CI le fait automatiquement à chaque push, pour attraper une régression de calibrage tout de suite plutôt qu'après dix systèmes de plus (marché, finances...).

## Déclenchement

```yaml
on:
  push:
    branches: [main]
  pull_request:
```

Le workflow se lance sur :
- un push vers `main`,
- **ou** l'ouverture/mise à jour d'une pull request, quelle que soit la branche source.

Un push sur une branche de travail (`feat/...`) qui n'a pas de PR ouverte **ne déclenche rien**. Pour voir le CI tourner sur du travail en cours, ouvrir une PR :
```bash
git push -u origin <branche>
gh pr create
```

## Les deux jobs

`harness` attend que `kernel` passe (`needs: kernel`) avant de démarrer — inutile de calibrer un harness qui dépend d'un noyau cassé.

### `kernel`
```bash
cd packages/kernel
composer install --no-interaction
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

### `harness`
```bash
cd packages/harness
composer install --no-interaction
vendor/bin/phpunit                          # suite par defaut, exclut tests/Regression
vendor/bin/phpstan analyse
vendor/bin/phpunit --testsuite Regression   # ~35s, run de calibrage complet
```

Le détail de ce que couvre `CalibrationRegressionTest` (bornes, seeds, effectif attendu) est documenté dans `packages/harness/README.md`, section "Tests et qualité" — pas dupliqué ici.

## Reproduire localement avant de pousser

Exactement les mêmes commandes, dans chaque package :
```bash
cd packages/kernel && composer install && vendor/bin/phpunit && vendor/bin/phpstan analyse
cd packages/harness && composer install && vendor/bin/phpunit && vendor/bin/phpstan analyse && vendor/bin/phpunit --testsuite Regression
```
Si tout est vert en local, le CI doit passer de la même façon — même PHP 8.3, mêmes commandes, aucune étape cachée côté GitHub.

## Suivre un run

```bash
gh run list                 # runs recents, avec leur statut
gh run view <id> --log      # detail d'un run (utile si un job echoue)
```
