# 06 — Le Ruleset

## 1. L'idée

> **Définition — Ruleset.** Le paquet de toutes les valeurs numériques qui paramètrent le
> monde : âges, taux, probabilités, montants, seuils. Versionné, passé en argument à
> chaque tick, **jamais codé en dur dans un système**.

```php
final readonly class Ruleset
{
    public function __construct(
        public string $version,
        public Balance $balance = new Balance(),
    ) {}
}
```

La motivation est pratique, pas idéologique. Équilibrer un monde, c'est faire tourner
quarante saisons, regarder une métrique, changer un chiffre, recommencer. Si ce chiffre
est dans le code, chaque itération est un commit. S'il est dans une donnée, chaque
itération est un argument de ligne de commande :

```bash
php bin/aggregate.php --set meritShare=0.6 --years 40
```

Et surtout : le harness peut rejouer **le même jeu de graines** avec deux `Ruleset`
différents, ce qui isole l'effet du paramètre du bruit stochastique. C'est ce qui rend
l'équilibrage mesurable au lieu d'être une impression.

## 2. La structure : imbriquée, une classe par système

```
Ruleset
├── version : string
└── Balance
    ├── developmentRate      ─┐ deux multiplicateurs globaux
    ├── trainingRate         ─┘
    ├── RetirementBalance          → RetirementSystem
    ├── PlayerDevelopmentBalance   → PlayerDevelopmentSystem
    ├── YouthIntakeBalance         → YouthIntakeSystem
    ├── CalendarBalance            → CalendarSystem
    ├── MatchBalance               → PoissonMatchEngine (via MatchSystem)
    ├── CompetitionBalance         → CompetitionSystem
    ├── FinanceBalance             → FinanceSystem
    ├── FacilitiesBalance          → FacilitiesSystem
    └── ContractBalance            → ContractSystem + WageModel
```

**Une classe de leviers par système, jamais une liste plate de scalaires.** La règle qui
justifie ce découpage est la même que celle qui gouverne les composants :

> **Un système ne dépend jamais des leviers d'un autre.**

C'est l'exact pendant de `reads()`/`writes()` sur les composants, appliqué aux règles.
Concrètement, `FinanceSystem` ne peut pas lire `FacilitiesBalance`, et réciproquement.

Ce n'est pas de la propreté gratuite. Le découpage force à répondre à une question de
conception à chaque ajout de levier : *à qui appartient cette décision ?*

| Groupe | Décide… |
|---|---|
| `FinanceBalance` | **quel argent** entre et sort d'un club (revenus, entretien, plafond d'investissement) |
| `FacilitiesBalance` | **combien de qualité** cet argent achète, et à quelle vitesse elle se dégrade |
| `ContractBalance` | **combien coûte un joueur** et pour combien de temps |

Trois groupes pour ce qui ressemble à « l'économie ». La coupure tombe exactement là où
les responsabilités se séparent, et c'est ce qui permet de calibrer l'un sans toucher aux
autres.

### Le cas limite : où mettre une borne ?

Les bornes de `Facilities` (`MIN_QUALITY = 0.5`, `MAX_QUALITY = 2.0`) sont des **constantes
du composant**, pas des leviers du `Ruleset`. Pourquoi :

- `FacilitiesSystem` doit clamper ce qu'il écrit ;
- `FinanceSystem` doit savoir qu'un club déjà au plafond n'a rien à acheter — sinon son
  investissement brûle de l'argent sans contrepartie.

Deux systèmes ont besoin de la même valeur. La mettre dans le `Ruleset` de l'un ferait
dépendre l'autre de ses leviers. **Un invariant du composant, porté par le composant,
n'est pas un levier d'équilibrage** : les deux systèmes peuvent s'y référer sans se
coupler l'un à l'autre.

## 3. Ce que le Ruleset contient, en pratique

Un aperçu par domaine — la liste exhaustive est dans les docblocks, qui sont
particulièrement fournis (chaque champ explique *pourquoi* cette valeur).

**Carrière d'un joueur**

| Champ | Défaut | Effet |
|---|---|---|
| `retirementEligibleAge` | 29 ans | En dessous, aucun risque de retraite |
| `retirementAgeWeight` | 0.15 | Poids des années au-delà dans la probabilité annuelle |
| `growthPrimeAgeThreshold` | 23 ans | En dessous, progression maximale |
| `growthPlateauFactor` | 0.3 | Progression ralentie entre 23 ans et le pic |
| `physical/technical/mentalDeclineMultiplier` | 2.0 / 1.0 / 0.5 | Le physique décline 4× plus vite que le mental |

**Génération des jeunes**

| Champ | Défaut | Effet |
|---|---|---|
| `baseIntakePerClub` | 1.2 | Promotions moyennes par club et par saison |
| `ceilingMin` / `ceilingMax` | 55 / 95 | Bornes du potentiel tiré |
| `talentSkew` | 3 | Asymétrie de la loi de talent (`Beta(1, k)`) |
| `startingSkillRatio` | 0.45 | Fraction du potentiel à laquelle un jeune démarre |

**Match**

| Champ | Défaut | Effet |
|---|---|---|
| `homeAdvantage` | 0.25 | Ajouté à l'exposant du taux de buts à domicile |
| `strengthScale` | 20.0 | Diviseur de l'écart de niveau — plus grand = niveau moins déterminant |
| `lowScoreCorrelation` (ρ) | −0.13 | Correction de Dixon-Coles sur les scores faibles |

**Économie** — tous les montants sont en **centimes entiers**, jamais en flottants (c'est
ce qui rend l'invariant de conservation monétaire vérifiable à la centime près).

| Champ | Défaut | Effet |
|---|---|---|
| `clubIncomePerSeasonCents` | 70 M | Revenu **moyen** par club et par saison |
| `meritShare` | **0.0** | Part de l'enveloppe distribuée au mérite plutôt qu'à parts égales |
| `facilityUpkeepPerQualityPointCents` | 14 M | Entretien à `quality = 1.0` — croît en **carré** |
| `wageBudgetShare` | 0.7 | Part du revenu qu'un club consacre à sa masse salariale |
| `baseWagePerWeekCents` | 50 000 | Salaire d'un joueur à `referenceQuality = 50` |

## 4. Deux disciplines qui valent la peine d'être copiées

### Un défaut ne change qu'avec une mesure à l'appui

`meritShare` vaut `0.0` par défaut, alors que le monde réel se situe entre 0 et 1. Ce
n'est pas un oubli. À cette valeur, chaque club touche exactement
`clubIncomePerSeasonCents` — c'est-à-dire **exactement le comportement d'avant
l'introduction du champ**, division entière comprise.

Bénéfice : le monde par défaut reste bit-identique, toutes les mesures antérieures gardent
leur validité, et l'effet du mérite se mesure par comparaison à graines appariées avant de
devenir éventuellement un défaut.

La même discipline a corrigé une vraie erreur : `clubIncomePerSeasonCents` valait
initialement 5 M, choisi sans vérifier l'ordre de grandeur. La masse salariale d'un club
moyen étant de ~72,8 M/an (50 000 × 52 semaines × ~28 joueurs), chaque club s'endettait de
~590 k€/an — un facteur ~15 hors sujet. Corrigé en **observant une simulation sur cinq
saisons**, pas en redevinant un chiffre.

### Un docblock explique le pourquoi, pas le quoi

Le nom du champ dit déjà ce qu'il fait. Le docblock sert à autre chose : expliquer
pourquoi cette forme-là, quelles alternatives ont été essayées, et ce qui casserait si on
le changeait naïvement.

Exemple sur `ContractBalance::$targetSquadSize` (extrait) :

> Un `minSquadSize = 16` a été essayé pour le corriger : sur six graines appariées il ne
> change pas le Gini des titres (0,521 contre 0,557) et **détruit** le seul gain
> consistant du lot, la rotation du top 5 (49,2 % contre 53,3 %, et 4 graines sur 6 au
> lieu de 6 sur 6). Retiré.

Cette information ne vit nulle part ailleurs. Sans elle, la prochaine personne qui trouve
que « ce serait mieux avec un plancher d'effectif » refait l'expérience.

## 5. Où s'arrête le data-driven

**Ce qui est une donnée :** tous les nombres. Âges, taux, probabilités, montants, seuils,
bornes.

**Ce qui reste du code :** la *forme* des formules. Que la progression soit
`f(écart au plafond) × g(âge)`, que le déclin soit linéaire après le pic, que le tirage
de talent soit un `min` de k uniformes — tout ça est en PHP, et c'est délibéré.

La raison est lucide plutôt qu'idéologique : rendre la *forme* configurable demanderait un
langage d'expression, donc un interpréteur, donc un débogueur, donc une couche de test —
et on remplacerait du PHP lisible par un DSL maison mal outillé. Le curseur est mis à
l'endroit où le bénéfice (itérer vite sur les nombres) est maximal et le coût minimal.

## 6. Le verrou de versionnage

Un monde est épinglé à un couple **`(kernelVersion, rulesetVersion)`**.

Changer les règles d'un monde vivant n'est **pas** un rechargement à chaud : c'est une
migration explicite. La raison est le piège classique de l'event sourcing :

```
   Journal d'événements du monde                Règles v1        Règles v2
   ─────────────────────────────                ─────────        ─────────
   tick 4200 : PlayerRetired(42)                retraite à 29    retraite à 33
   ...

   Rejouer le journal avec les règles v2 ne reproduit PAS l'histoire :
   le joueur 42 n'aurait jamais pris sa retraite au tick 4200.
```

Un event store n'est rejouable que contre la version de règles qui l'a produit. D'où
l'épinglage, et d'où le fait que `Ruleset::$version` soit un champ obligatoire sans valeur
par défaut — on ne peut pas construire un `Ruleset` sans le nommer.

## 7. Ce qui n'existe pas encore

Le `Ruleset` est aujourd'hui un **objet de valeur PHP**, construit en mémoire. Le schéma
JSON, sa validation et son versionnage vivront dans un futur package `packages/ruleset/`,
qui produira les données alimentant cet objet — **sans jamais que `kernel` en dépende**
(le graphe du monorepo impose `kernel → rien`).

En attendant, le harness dispose d'un mécanisme de surcharge par champ
(`Harness\Comparison\RulesetOverride`) qui construit un `Ruleset` modifié à partir de
paires `champ=valeur`, tous groupes confondus. C'est ce qui alimente `--set`.

---

**Suite :** [07 — Les algorithmes du football](07-algorithmes-football.md)
</content>
</invoke>
