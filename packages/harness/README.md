# `packages/harness` — harness d'équilibrage

Simule des populations synthétiques sur le pipeline football du `kernel` et agrège des métriques de santé du monde (courbes de compétence, pyramide des âges, distribution des scores, équilibre compétitif, graphe d'événements). Dépend de `kernel` (path repository), jamais l'inverse (`docs/11-` §7).

Détail classe par classe des systèmes/composants simulés : `packages/kernel/README.md`. Ce README ne documente que ce qui est spécifique au harness.

## Arborescence

- **`src/Population/`** — construction d'un monde initial : `PopulationFactory` (orchestre les deux suivantes + répartit les joueurs sur les clubs, round-robin, via `SquadMembership`), `ClubFactory` (clubs synthétiques + `Facilities`, qualité uniforme — sans clubs, `TrainingSystem`/`YouthIntakeSystem` restent incalibrables), `CompetitionFactory` (l'unique compétition de la Phase 0, sans quoi `CalendarSystem` n'a rien à planifier), `PopulationSpec` (paramètres d'un run, regroupés pour ne pas faire grossir une liste positionnelle sur les trois points d'appel qui en ont besoin).
- **`src/Simulation/`** — `PipelineFactory` (seule source de vérité pour l'ordre du pipeline football, vérifié par `Tests\Simulation\PipelineFactoryTest`), `StepRunner` (enveloppe interactive tick-par-tick pour `bin/sandbox.php`, sans agrégation — ce n'est pas son rôle).
- **`src/Metrics/`** — `Sampler` (fait tourner une simulation complète et échantillonne courbes/pyramide/scores/historique de saisons — voir son docblock pour le détail des invariants suivis), `Stats` (fonctions statistiques pures), `AggregateResult` (sortie agrégée, indépendante du format de rendu), `CompetitiveBalance`/`CompetitiveBalanceResult` (Gini des titres + rotation du top 5, post-traitement pur sur `AggregateResult::$seasonHistory`, `docs/14-` §7), `EventGraphCollector`/`EventGraphResult` (volume d'événements par type + backlog annuel du `Scheduler`, opt-in via `Sampler::run(..., $eventGraph)`, `docs/16-` §6 — voir la limitation documentée sur la profondeur de cascade et les entités sur-modifiées, non mesurables sans changer le contrat du noyau).
- **`src/Comparison/`** — `PairedSeedComparison` (rejoue le même jeu de graines avec deux `Ruleset`, isole l'effet du bruit, `docs/13-` §4.0), `RulesetOverride` (construit un `Ruleset` modifié à partir d'un ensemble de champs de calibration, tous groupes de `Balance` confondus).
- **`src/Report/`** — `TextReport` (rendu console), `JsonSerializer` (rendu JSON consommé par `public/app.js`) — même structure `AggregateResult`, aucune logique propre.
- **`src/Support/`** — `WorldInspector` (lecture à la demande d'un `WorldState`, vérité pas perception — un outil de debug interne, pas un client de jeu), `WorldHasher` (hash déterministe d'un `WorldState` football et d'une séquence d'événements — même machine/même PHP seulement, `docs/13-` §4.8, pas une forme canonique cross-machine ; énumère explicitement les types de composants football connus, `WorldState` n'exposant volontairement aucun balayage global).
- **`bin/aggregate.php`** — CLI de calibrage, sans plafond de taille (contrairement à `public/index.php`). `--set champ=valeur` (répétable) bascule en comparaison à graines appariées. `--event-graph` ajoute la section graphe d'événements au rapport.
- **`bin/sandbox.php`** — stepper interactif tick-par-tick sur `StepRunner`.
- **`public/`** — mini-appli web (PHP intégré, bornes de taille de requête distinctes du CLI) consommant `JsonSerializer` via `app.js`. Hors périmètre PHPStan pour l'instant (superglobales HTTP incompatibles avec le niveau max sans bruit).

## Tests et qualité

```bash
composer install
vendor/bin/phpunit                    # suite par défaut (rapide, ~40s) - exclut tests/Regression
vendor/bin/phpunit --testsuite Regression   # ~35s, run de calibrage complet (500 joueurs/18 clubs/25 saisons)
vendor/bin/phpstan analyse             # niveau max sur src/tests/bin
```

`tests/Regression/CalibrationRegressionTest.php` est le garde-fou de non-régression du critère de sortie Phase 0 (`docs/15-roadmap.md` §4) : assertions numériques directes sur `Sampler::run()`, bornes larges (±8 points, 250-400 joueurs) pour attraper une vraie régression de calibrage sans réagir au bruit normal entre graines. `tests/Determinism/DeterministicRunTest.php` vérifie que même graine → même hash d'état et même hash de séquence d'événements sur le pipeline complet (via `WorldHasher`), critère de sortie Phase 1 distinct des vecteurs figés `Rng`/`Hash` déjà testés côté `kernel`.

CI (`.github/workflows/ci.yml`) : deux jobs, `kernel` puis `harness` (phpunit + phpstan + suite `Regression`) — détail complet (déclenchement, comment reproduire localement, comment suivre un run) dans `.github/workflows/README.md`.

## Guide d'utilisation

Deux exécutables, deux besoins différents : `bin/aggregate.php` répond à *"est-ce que le monde est plausible sur N saisons ?"* (rapport agrégé, aucune interaction), `bin/sandbox.php` répond à *"que se passe-t-il exactement à cet instant ?"* (REPL, avance manuelle). `public/` (`composer serve`, `localhost:8000`) propose la même chose que `bin/aggregate.php` sous forme d'UI web, pour qui préfère cliquer plutôt que taper des `--set` — pas détaillé ici, options identiques.

### `bin/aggregate.php` — rapport agrégé sur un run complet

Construit une population synthétique, fait tourner tout le pipeline football sur `--years` années, puis affiche un rapport texte. Aucune interaction une fois lancé.

**Options** (toutes optionnelles) :

| Option | Défaut | Effet |
|---|---|---|
| `--players` | 500 | Taille de la population initiale |
| `--years` | 40 | Durée du run — 40 pour laisser la population atteindre son palier (stationnaire dès ~année 13, `docs/15-roadmap.md` §4), 20 ne suffit pas avec une population de départ hors équilibre d'âge |
| `--seed` | 42 | Graine du monde — même graine ⇒ même population, mêmes tirages |
| `--clubs` | 18 | Nombre de clubs synthétiques (0 = pas de clubs, désactive calendrier/match/classement/formation) |
| `--facilities-quality` | 1.0 | Qualité d'installations uniforme sur tous les clubs (échelle `[0.5, 2.0]`) |
| `--set champ=valeur` | — | **Répétable.** Bascule automatiquement le mode (voir plus bas) |
| `--event-graph` | — | Flag (pas de valeur). Ajoute la section "graphe d'événements" au rapport |

**Deux modes, choisis par la présence ou non de `--set` :**
- **Sans `--set`** : rapport baseline simple sur le `Ruleset` par défaut.
- **Avec au moins un `--set`** : bascule en **comparaison à graines appariées** — le même jeu de graines est rejoué avec le `Ruleset` baseline puis avec le `Ruleset` modifié, ce qui isole l'effet du paramètre du bruit stochastique (`docs/13-` §4.0). C'est le mode qui rend le critère de sortie Phase 1 atteignable ("voir l'effet chiffré en moins de 5 minutes").

**Champs disponibles pour `--set`** (groupés comme `Comparison\RulesetOverride::GROUPS`, valeur toujours numérique) :

| Groupe | Champs |
|---|---|
| Global | `developmentRate`, `trainingRate` |
| Retraite | `retirementEligibleAge`, `retirementAgeWeight`, `retirementFragilityWeight` |
| Développement | `growthPrimeAgeThreshold`, `growthPlateauFactor`, `declineRatePerYear`, `physicalDeclineMultiplier`, `technicalDeclineMultiplier`, `mentalDeclineMultiplier` |
| Formation des jeunes | `intakeDayOfYear`, `intakeAgeYears`, `baseIntakePerClub`, `ceilingMin`, `ceilingMax`, `talentSkew`, `startingSkillRatio`, `startingSkillJitter`, `physicalPeakAgeMin/Max`, `technicalPeakAgeMin/Max`, `mentalPeakAgeMin/Max`, `growthRateMin/Max`, `fragilityMin/Max` |
| Calendrier | `seasonStartDayOfYear`, `firstMatchdayOffsetDays`, `matchdayIntervalDays` |
| Match | `homeAdvantage`, `strengthScale`, `lowScoreCorrelation`, `maxSimulatedGoals` |
| Classement | `pointsForWin`, `pointsForDraw` |

Un champ inconnu ou une valeur hors bornes (`talentSkew`, `baseIntakePerClub` — les deux seuls champs qui bornent une boucle de tirage RNG) fait échouer la commande avant toute simulation.

**Lire le rapport** (sections dans l'ordre d'affichage) :
1. Courbes physique / technique / mental — valeur moyenne des attributs par âge (coupe transversale, pas une trajectoire individuelle).
2. Effectif actif par année — pour juger la stationnarité de la population.
3. Pyramide des âges (dernière année) et distribution des âges de retraite.
4. Distribution des buts par match, répartition domicile/nul/extérieur, scores exacts triés par fréquence — à comparer aux proportions réelles du football (~42/29/29 observé, `docs/15-roadmap.md` §4).
5. Classement et matchs de la dernière saison jouée.
6. Équilibre compétitif — Gini des titres (0 = égalité parfaite, 1 = monopole) et rotation du top 5 sur tout le run.
7. Graphe d'événements (seulement avec `--event-graph`) — volume par type d'événement sur tout le run, puis backlog annuel du `Scheduler` (une croissance qui ne redescend jamais est le signe d'une cascade non amortie).

**Workflow type de calibration** :
```bash
php bin/aggregate.php --players=500 --years=40 --seed=42                              # 1. baseline
php bin/aggregate.php --players=500 --years=40 --seed=42 --set trainingRate=1.5        # 2. un changement
# 3. lire le delta chiffré affiché section par section (baseline vs modifié)
```

**Exemples** :
```bash
php bin/aggregate.php --players=500 --years=40 --seed=42 --clubs=18
php bin/aggregate.php --players=500 --years=40 --seed=42 --set retirementFragilityWeight=0.30 --set trainingRate=1.5
php bin/aggregate.php --players=500 --years=40 --seed=42 --event-graph

# Run plus rapide sur de gros volumes (~2,5x, resultat identique bit a bit - verifie sur le meme seed) :
php -d opcache.enable_cli=1 -d opcache.jit_buffer_size=64M -d opcache.jit=tracing bin/aggregate.php --players=2000 --years=50
```

### `bin/sandbox.php` — REPL interactif tick par tick

Construit la même population synthétique (mêmes options que `aggregate.php` sauf `--years`, absent ici — c'est le REPL qui pilote la durée, et `--event-graph`, qui n'existe pas dans ce script) puis laisse avancer manuellement, tick par tick ou par tranche, avec inspection à la demande. Un seul process, `WorldState` en mémoire, aucune persistance — fermer le REPL perd le monde.

```bash
php bin/sandbox.php --players=200 --seed=42 --clubs=8
php bin/sandbox.php --players=200 --seed=42 --clubs=8 --set trainingRate=1.5 --set retirementFragilityWeight=0.30
```

**Commandes une fois démarré :**

| Commande | Effet |
|---|---|
| `step [n]` | Avance de `n` ticks (défaut 1), affiche le tick courant et le décompte des événements émis sur la tranche par type |
| `standings` | Classement courant de l'unique compétition |
| `player <id>` | Dump brut des composants d'un joueur (vérité, pas perception — outil de debug, pas un client de jeu) |
| `club <id>` | Dump brut d'un club (identité, facilités, effectif) |
| `events [n]` | Réaffiche les `n` derniers événements observés (défaut 10, capacité du journal : 200) |
| `help` | Rappelle cette liste |
| `quit` / `exit` | Quitte |

**Exemple de session** (le calendrier ne se génère qu'au dernier jour de l'année — `seasonStartDayOfYear=0` — et le premier match démarre `firstMatchdayOffsetDays=14` jours après ; `step 365` pile un an ne montre donc jamais de match, il faut dépasser le cap d'année, cf. le même invariant documenté dans `Metrics\Sampler` pour `bin/aggregate.php`) :
```
Monde pret : 200 joueurs, 8 clubs, graine 42. Tapez 'help' pour la liste des commandes.

> step 400
Tick courant : 400.
  MatchPlayed              16
  PlayerRetired            23
  SeasonStarted            1
  YouthPlayerPromoted      12

> standings
club                        J    G    N    P     BP     BC   Pts
Club synthetique 5          4    3    0    1      9      2     9
Club synthetique 3          4    2    2    0      8      6     8
...
```
