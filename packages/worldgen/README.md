# `flair/worldgen` — la genèse d'un monde

Fabrique l'état initial d'un monde : des clubs, une compétition, un recruteur par club, et une population de joueurs déjà en cours de carrière. Déterministe pour une graine donnée. Dépend de `kernel` (path repository), jamais l'inverse (`docs/11-` §7).

```
kernel   → (rien)
worldgen → kernel
harness  → kernel, worldgen
host     → kernel, worldgen        (à venir)
```

Ce package existe pour cette dernière ligne. La genèse vivait dans `packages/harness/src/Population/` jusqu'au 2026-08-08 ; elle en est sortie parce que `host` doit pouvoir créer un monde et n'a pas le droit d'importer un outil de mesure. Le déplacement n'a **rien changé** au monde produit — vérifié empreinte contre empreinte, état et séquence d'événements identiques.

> `worldgen → ruleset` est prévu par `docs/11-` §7 mais `packages/ruleset` n'existe pas encore : `Ruleset` et ses `*Balance` vivent dans `kernel/src/Core/Ruleset/`. `WorldFactory::populate()` reçoit les groupes dont il a besoin (`YouthIntakeBalance`, `ContractBalance`, `PositionBalance`) en paramètres optionnels.

## La règle d'or : ne jamais réordonner les tirages

`WorldFactory::populate()` consomme un flux RNG partagé et alloue des `EntityId` dans un ordre précis. **Changer cet ordre change le monde entier** — et rend incomparables toutes les mesures déjà enregistrées (`docs/15-` §4), parce que décaler l'allocateur décale les flux RNG de tout ce qui sera créé plus tard *à l'exécution* (les jeunes promus, par exemple).

Ce n'est pas une crainte théorique, c'est le fait de méthode le plus cher du projet : au lot perception, dix-huit scouts ajoutés au genesis ont suffi à rendre deux arbres différents alors que le lot était un no-op strict sur chaque décision. Trois précautions dans le code en découlent, chacune écrite **après** avoir constaté le décalage :

- le staff est semé **après** les joueurs (`StaffFactory::create()`) ;
- `ClubFactory::disperseBoardPatience()` est une méthode séparée, appelée **après** le staff — la mettre dans `create()`, qui tourne avant la boucle des joueurs, décalerait toute la population ;
- `WorldFactory::employ()` **dérive** `signedOn` de `expiresOn` au lieu de le tirer, pour ne pas consommer un `nextUint32()` de plus.

Corollaire pratique : toute modification de ce package se vérifie par comparaison d'empreintes **dans un même build** (`git worktree` sur la révision précédente, un script d'autoload maison, deux runs), jamais contre des chiffres notés dans un document.

## Les classes

- **`WorldSpec`** — la forme du monde : combien de clubs et de joueurs, avec quelle graine, plus les grandeurs qui décrivent l'état de départ (qualité d'installations, trésorerie initiale, dispersion du jugement des recruteurs et de la patience des conseils d'administration). **Aucune durée de simulation** : « pendant combien d'années fait-on tourner ce monde » est une question d'appelant, portée par `Harness\Population\PopulationSpec`.

  La frontière avec le `Ruleset` a une conséquence pratique : le `Ruleset` dit *comment le monde se comporte*, `WorldSpec` dit *de quoi il est fait au premier tick*. Comme une comparaison à graines appariées ne rejoue jamais la génération, un levier de génération logé par erreur dans le `Ruleset` serait silencieusement inopérant sous `--set`.

- **`WorldFactory`** — orchestre tout, et répartit les joueurs sur les clubs en round-robin via `SquadMembership`. Deux choix de génération valent d'être connus : le talent est tiré par `Kernel\Football\Generation\PlayerFactory`, **la même loi que l'intake annuel** (deux lois différentes rendraient la pyramide des âges non stationnaire, donc le critère de sortie de la Phase 0 ininterprétable) ; et le salaire initial passe par `WageModel`, pour que le monde démarre à l'échelle salariale vers laquelle il convergera.

- **`ClubFactory`** — les clubs (`Club` + `Facilities` + `Finances`), qualité et trésorerie uniformes, plus la dispersion de `BoardPatience` en méthode séparée (cf. règle d'or).
- **`CompetitionFactory`** — l'unique compétition, sans laquelle `CalendarSystem` n'a rien à planifier.
- **`StaffFactory`** — un recruteur par club (`Person` + `Employment` + `Scout`). Le jugement est dispersé entre clubs, et c'est cette dispersion — pas le bruit lui-même — qui fait de l'asymétrie d'information une ressource (`docs/12-` §4).

## Commandes de dev

```bash
cd packages/worldgen
vendor/bin/phpunit          # tests
vendor/bin/phpstan analyse  # niveau max (src, tests)
```

## Ce que ce package ne fait pas encore

Pays, divisions multiples, noms réalistes, variance entre clubs, académies de formation. La distribution imposée des archétypes (`WorldFactory::archetypeDeal()`) est un pis-aller assumé, à remplacer par les centres de formation décrits dans `docs/15-` §4 Phase 6.
