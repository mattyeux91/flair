# Manuel du moteur de simulation

Ce dossier est un **manuel** : il explique comment le moteur de simulation de Flair
fonctionne, et surtout **pourquoi il fonctionne comme ça**. Il est écrit pour être lu
par un humain, du début à la fin, sans connaissance préalable du projet.

## À qui ça s'adresse

- Un développeur qui arrive sur le code et veut comprendre le noyau avant d'y toucher.
- Un lecteur curieux qui veut comprendre comment on simule un monde persistant.
- Toi dans six mois, qui aura oublié pourquoi l'entretien des installations est convexe.

Aucun prérequis en ECS, en simulation ou en probabilités. Chaque notion est définie
au moment où elle apparaît, et les concepts qui demandent un vrai bagage (Dixon-Coles,
tri topologique, générateurs pseudo-aléatoires) renvoient à des ressources externes
listées dans [`99-ressources-et-glossaire.md`](99-ressources-et-glossaire.md).

## Ce que ce manuel est, et n'est pas

| Ce document | Rôle |
|---|---|
| `manuel/` (ici) | **Comment et pourquoi ça marche.** Pédagogique, narratif, avec des schémas. Source : le code. |
| `docs/10-` à `16-` | **Ce qu'on veut construire.** Référence de conception, décisions d'architecture, roadmap. |
| `packages/*/README.md` | **Inventaire.** Classe par classe, ce qui existe aujourd'hui. |
| Le code | **La vérité.** En cas de contradiction avec quoi que ce soit, le code gagne. |

Ce manuel a été écrit **à partir du code**, pas à partir de `docs/`. Là où le code et la
doc de conception divergent, c'est signalé explicitement (voir la section « Écarts connus »
en fin de [`10-etendre-le-moteur.md`](10-etendre-le-moteur.md)).

## Parcours de lecture

**En 20 minutes** — comprendre de quoi on parle :
[01](01-vue-d-ensemble.md) → [03](03-le-tick-et-le-pipeline.md) → [08](08-boucles-et-retroactions.md)

**En 2 heures** — être capable de lire le code sans se perdre :
tout, dans l'ordre.

**Pour contribuer** — avant d'écrire une ligne :
[03](03-le-tick-et-le-pipeline.md), [05](05-determinisme-et-aleatoire.md),
[10](10-etendre-le-moteur.md).

## Chapitres

| # | Fichier | Contenu |
|---|---|---|
| 01 | [Vue d'ensemble](01-vue-d-ensemble.md) | Le problème posé, la fonction `step()`, le temps, la carte du système |
| 02 | [Le modèle de données (ECS)](02-le-modele-de-donnees.md) | Entités, composants, colonnes, archétypes, singletons — et pourquoi pas des objets |
| 03 | [Le tick et le pipeline](03-le-tick-et-le-pipeline.md) | Anatomie d'un tick, les systèmes, les déclarations opposables, l'ordre dérivé |
| 04 | [Messages et files](04-messages-et-files.md) | Faits / DecisionRequests / Intents, OutQueue, Scheduler, contrôle des cascades |
| 05 | [Déterminisme et aléatoire](05-determinisme-et-aleatoire.md) | Pourquoi, le PRNG 32 bits, les flux dérivés, les pièges PHP |
| 06 | [Le Ruleset](06-le-ruleset.md) | Les règles comme données, versionnées, groupées par système |
| 07 | [Les algorithmes du football](07-algorithmes-football.md) | Les onze systèmes, un par un, formules comprises |
| 08 | [Boucles et rétroactions](08-boucles-et-retroactions.md) | La boucle économique, ce qui l'amortit, comment on évite les oscillations |
| 09 | [Mesurer le monde](09-mesurer-le-monde.md) | Le harness, les graines appariées, les invariants, ce qu'on teste |
| 10 | [Étendre le moteur](10-etendre-le-moteur.md) | Recettes concrètes, checklist, pièges, écarts connus |
| 99 | [Ressources et glossaire](99-ressources-et-glossaire.md) | Lectures externes, vocabulaire |

## Conventions

- **`Ceci`** en police fixe désigne une classe, une méthode ou un champ du code réel.
- Les chemins sont relatifs à la racine du dépôt : `packages/kernel/src/...`.
- Les schémas sont en ASCII, comme dans `docs/` — lisibles dans un terminal comme dans
  un navigateur, sans dépendance de rendu.
- Les formules sont écrites en pseudo-code, pas en LaTeX.
- Un encadré **⚠️** signale un piège qui a déjà coûté un bug réel dans ce projet.
</content>
</invoke>
