# 07 — Les algorithmes du football

Ce chapitre parcourt les onze systèmes. Pour chacun : ce qu'il fait, la formule, et
pourquoi cette forme-là plutôt qu'une autre.

**Carte de lecture** — les trois groupes fonctionnels, dans l'ordre du pipeline :

```
  ┌─ LA VIE D'UN JOUEUR ────────────────────────────────────────────────┐
  │  YouthIntakeSystem   naissance dans la population professionnelle    │
  │  TrainingSystem      qualité de l'environnement                      │
  │  PlayerDevelopmentSystem   progression et déclin                     │
  │  RetirementSystem    sortie                                          │
  └──────────────────────────────────────────────────────────────────────┘
  ┌─ LA SAISON ─────────────────────────────────────────────────────────┐
  │  CalendarSystem      génère le calendrier, programme les matchs      │
  │  MatchSystem         joue un match                                   │
  │  CompetitionSystem   tient le classement, sacre le champion          │
  └──────────────────────────────────────────────────────────────────────┘
  ┌─ L'ÉCONOMIE ────────────────────────────────────────────────────────┐
  │  FinanceSystem       revenus, salaires, entretien, investissement    │
  │  FacilitiesSystem    convertit l'argent en qualité d'installations   │
  │  ContractSystem      décide le mercato                               │
  │  SquadSystem         applique le mercato                             │
  └──────────────────────────────────────────────────────────────────────┘
```

---

# A. La vie d'un joueur

## A.1 `YouthIntakeSystem` — l'entrée dans le monde

**Quand :** un jour par an (`intakeDayOfYear = 180`).
**Ce qu'il fait :** chaque club promeut une poignée de joueurs neufs.

### Le niveau d'abstraction, et pourquoi il n'est pas celui du vrai foot

Un centre de formation réel accueille 8 à 12 jeunes par promotion. Les modéliser tous
ferait exploser la population : ~180 entrées/an sur 18 clubs contre ~28 sorties par la
retraite. Un monde multiplié par cinq en quelques saisons, **sans qu'aucun test ne le
signale**.

Le vrai football ne gonfle pas parce que la grande majorité des formés est libérée et
quitte le football professionnel. Modéliser ça demanderait en plus un mécanisme d'échec,
un Fait `PlayerReleased`, un composant de statut.

Choix retenu : **`intake` ne modélise pas « entre au centre de formation » mais « entre
dans la population professionnelle »** — soit 1 à 3 joueurs par club et par saison. Les
8-12 scolaires restent hors modèle : ils n'ont aucune valeur de jeu et représenteraient
~2 500 entités inutiles.

### Combien de joueurs

```
   part(club)   = qualité(club) / qualité_moyenne_du_monde
   espérance    = baseIntakePerClub × part(club)
   cohorte      = arrondi_stochastique(espérance)
```

**La normalisation par la moyenne est la ligne la plus importante du système.** Elle
garantit que le total mondial vaut toujours `baseIntakePerClub × nombre de clubs`, quelles
que soient les installations. Les bons centres captent une plus grosse **part** du vivier
national ; ils ne l'agrandissent pas.

Ce n'est pas une subtilité d'équilibrage. Sans elle, la boucle
`installations → jeunes → effectif → masse salariale → argent → installations` se referme
avec un délai de retour d'**une carrière** (~15 ans). Une contre-réaction retardée de ce
gain oscille : mesure à l'appui, la population balançait entre 224 et 381 sur 60 saisons,
et deux calibrages successifs n'en ont changé que l'amplitude, jamais l'existence.

Ça se défend aussi dans la fiction : le nombre de jeunes talentueux d'un pays tient à sa
démographie, pas à la générosité de ses clubs — ceux-ci se disputent lesquels percent.

> **Définition — arrondi stochastique.** Convertir un nombre fractionnaire en entier en
> tirant la partie décimale au hasard : `1,2` donne 1 avec 80 % de chances et 2 avec 20 %.
> L'espérance reste exactement 1,2 malgré des résultats entiers.
>
> ```php
> $guaranteed = (int) floor($expected);
> $remainder  = $expected - $guaranteed;
> return $guaranteed + ($rng->nextUint32() % 10_000 < (int)($remainder * 10_000) ? 1 : 0);
> ```
>
> Un `round()` sec écraserait le calibrage : avec 1,2 attendu, tous les clubs
> promouvraient exactement 1 joueur, et `baseIntakePerClub` n'aurait plus **aucun effet
> entre 0,5 et 1,5**.

### La loi de talent (`PlayerFactory`)

```
   ceiling = ceilingMin + min(U₁, …, U_k) × (ceilingMax − ceilingMin)
```

où les `Uᵢ` sont uniformes sur `[0, 1]` et `k = talentSkew = 3`.

> **Pourquoi ça donne une queue longue.** Le minimum de k uniformes suit une loi
> `Beta(1, k)`, concentrée près de 0 : la plupart des tirages restent proches de
> `ceilingMin`, et il faut que les k tirages soient élevés simultanément pour approcher
> `ceilingMax`. C'est de la statistique d'ordre élémentaire, sans aucune fonction
> transcendante.

Avec `k = 3` et `[55, 95]` : moyenne ~65, médiane ~63, p90 ~76, et **~1,6 % au-dessus de
85**. Ce dernier chiffre est le garde-fou à surveiller — il maintient le sens de l'échelle
(85 = « international », [ch. 02 §8](02-le-modele-de-donnees.md#8-léchelle-1-100)).

C'est un substitut délibéré à la log-normale demandée par la conception, dont Box-Muller
exigerait `exp`/`log`/`sqrt`/`cos` (voir [ch. 05 §6](05-determinisme-et-aleatoire.md)).

Les compétences de départ valent `round(ceiling × 0.45)`, plus un bruit de ±4 tiré
**une fois par catégorie**. Simplification assumée : à la création, tous les attributs
d'une même catégorie sont égaux. Ils ne divergent qu'ensuite, sous les tirages
indépendants du développement. Ce qui justifierait un départ différencié (un attaquant
fort en `finishing`, faible en `defending`) est le **poste**, et le poste n'existe pas
encore.

### Le contrat d'une recrue

Salaire **forfaitaire** (`basePlayerWagePerWeekCents`), jamais indexé sur la qualité comme
le sont les renouvellements. Ce n'est pas un oubli : un premier contrat d'académie est
standardisé dans le vrai football, et le joueur passe au prix du marché à son premier
renouvellement. C'est ce qui donne au centre de formation son intérêt économique —
quelques années de talent payé en dessous de sa valeur.

La durée, elle, est tirée comme partout ailleurs (2 à 4 ans) : sans terme, le jeune ne
reviendrait jamais sur le marché.

## A.2 `TrainingSystem` — l'environnement

Le plus simple du pipeline, et le plus discipliné.

```
   TrainingEffect(joueur) = clamp(trainingRate × Facilities.quality(son club), 0.5, 2.0)
```

Aucun aléatoire, aucun événement. Un joueur sans club ne reçoit **aucune écriture** : le
défaut neutre (1.0) que `PlayerDevelopmentSystem` applique en l'absence de `TrainingEffect`
couvre déjà le cas, sans code spécial.

**Ce qu'il ne fait pas, et pourquoi c'est écrit.** La formule complète visée est
`modif = clamp(h(entraînement) × i(temps de jeu) × j(moral), 0.5, 2.0)`. Ce système ne
modélise que `h`. Le jour où `i` et `j` existeront, ce seront des **composants-facteurs
séparés** (`PlayingTimeEffect`, `MoraleEffect`), chacun avec son propre producteur —
jamais fusionnés dans `TrainingEffect`. Un composant, un writer.

## A.3 `PlayerDevelopmentSystem` — progresser, puis décliner

**Quand :** chaque tick, pour chaque joueur, pour chacun des 16 attributs.

```
   écart          = ceiling − valeur_actuelle

   g(âge)  =  1.0                                   si âge < 23
              growthPlateauFactor (0.3)             si âge < pic de la catégorie
              −declineRatePerYear × (âge − pic)     après le pic

   modificateur   = TrainingEffect      si g(âge) ≥ 0
                    1 / TrainingEffect  sinon

   delta_annuel   = developmentRate × modificateur ×
                      ( growthRate × écart × g(âge)                  si g(âge) ≥ 0
                        g(âge) × fragility × declineMultiplier       sinon )

   proba_du_jour  = min(1, |delta_annuel| / 365)
   → tirage : avec cette probabilité, ±1 point ; sinon, rien.
```

Quatre choses à comprendre là-dedans.

**① Le potentiel est une asymptote, pas un plafond.** Le facteur `écart` fait que la
progression ralentit à mesure qu'on approche du `ceiling` — on ne le heurte jamais net,
on l'approche. Un joueur à 5 points de son potentiel progresse dix fois plus lentement
qu'un joueur à 50 points.

**② Le déclin ne dépend pas de l'écart, mais de la fragilité.** Après le pic, la formule
change de nature : elle n'est plus proportionnelle à ce qu'il reste à gagner, mais à
`fragility × declineMultiplier`. Un joueur robuste tient ; un joueur fragile s'effondre.

**③ Chaque catégorie a son propre pic, individuel.** `physicalPeakAge` (21-26),
`technicalPeakAge` (23-29), `mentalPeakAge` (26-30), tirés à la naissance du joueur. Plus
les multiplicateurs de déclin globaux (2.0 / 1.0 / 0.5). Résultat : **le physique culmine
et s'érode avant le mental**, à talent égal — un fait de football établi, ici émergent
plutôt que codé en dur.

**④ Le modificateur d'entraînement s'applique par sa réciproque après le pic.** Un bon
environnement doit *ralentir* le déclin, pas l'accélérer. `1/x` est une bijection de
`[0.5, 2.0]` sur lui-même (`1/0.5 = 2.0`), donc le résultat reste borné sans re-clamp.

**L'arrondi stochastique, encore.** Un taux annuel de 3 points donne un taux quotidien de
0,008 point. Additionné en entier, ça s'arrondit toujours à zéro et le joueur ne progresse
jamais. On convertit donc le taux en **probabilité quotidienne d'un pas de ±1**. Bénéfice
secondaire : la progression est irrégulière, par à-coups, plutôt qu'une interpolation
lisse — plus proche d'une vraie carrière.

> **Note d'implémentation.** Un seul `Rng` est créé par joueur et par tick, puis consommé
> séquentiellement par les 16 attributs. Les tirages ne sont donc pas indépendants entre
> attributs au sens strict, ils sont successifs dans le même flux — ce qui est
> parfaitement déterministe, et c'est la seule propriété qui compte ici.

## A.4 `RetirementSystem` — la sortie

```
   si âge < retirementEligibleAge (29) :  rien

   chance_annuelle = min(1, (âge − 29) × 0.15  +  fragility × 0.15)
   chance_du_jour  = chance_annuelle / 365
   → tirage. Si elle tombe :
       remove PlayerPotentials, PlayerPhysicalSkills,
              PlayerTechnicalSkills, PlayerMentalSkills
       emit PlayerRetired
```

**L'entité n'est pas détruite.** On lui retire son archétype « joueur ». Elle garde son
`Person` — nom, date de naissance — et pourra porter demain un rôle d'entraîneur. C'est
l'illustration la plus directe du principe ECS du [chapitre 02](02-le-modele-de-donnees.md).

**Ce système ne touche pas au contrat.** Il possède l'archétype joueur (compétences et
potentiels) ; `SquadSystem` possède la relation d'emploi et nettoie `Contract` et
`SquadMembership` en réagissant à `PlayerRetired`, donc **au tick suivant**.

Conséquence à connaître : un retraité garde son contrat un jour de plus et peut être payé
une dernière fois si sa retraite tombe le jour de paie. C'est un versement réel,
comptabilisé comme puits — donc sans effet sur l'invariant de conservation monétaire.

---

# B. La saison

## B.1 `CalendarSystem` — générer une saison

**Quand :** jour 0 de l'année simulée. Il fait trois choses, dans cet ordre :

```
   1. Dépouiller la saison écoulée : remove Fixture + MatchResult sur toutes
      les rencontres existantes
   2. Générer le calendrier : round-robin aller/retour
   3. Programmer : un FixtureKickoff par match, un SeasonEnded en fin de saison
      + emit SeasonStarted
```

L'ordre 1 → 2 n'est pas un détail d'implémentation : les entités créées en 2 portent des
identifiants neufs (jamais réutilisés), donc **c'est ce qui garantit qu'on ne détruit pas
ce qu'on vient de créer**.

### La méthode du cercle

> **Définition — round-robin (méthode du cercle).** Algorithme pour qu'N équipes se
> rencontrent toutes une fois, en N−1 journées. On fixe une équipe et on fait tourner
> toutes les autres d'un cran à chaque journée.

```
   Journée 1        Journée 2        Journée 3
   1 — 6            1 — 5            1 — 4
   2 — 5            6 — 4            5 — 3
   3 — 4            2 — 3            6 — 2

   Le club 1 reste fixe ; les autres tournent dans le sens horaire.
```

Entièrement déterministe, aucun RNG. L'alternance domicile/extérieur est gérée par un
échange sur les journées impaires. La manche retour rejoue exactement les mêmes paires,
inversées.

Propriété testable indépendamment du détail de l'algorithme : **chaque club joue
exactement N−1 fois à domicile et N−1 fois à l'extérieur**, un match contre chaque autre
club dans chaque sens.

### Les dates

```
   tick(journée m) = tick_de_génération + firstMatchdayOffsetDays (14) + m × matchdayIntervalDays (7)
   tick(SeasonEnded) = tick(dernière journée) + 1
```

Pour 18 clubs : 34 journées, la dernière au jour 245 de l'année simulée, fin de saison au
jour 246. Le reste de l'année (~119 jours) est l'intersaison — pendant laquelle le mercato
a lieu (jour 180).

Le `+1` sur `SeasonEnded` n'est pas cosmétique : au tick de la dernière journée,
`CompetitionSystem` traite encore les `FixtureKickoff` du jour, et le classement n'est
complet qu'à la fin de ce tick.

### Une limite documentée

Aucun composant `CompetitionMembership` n'existe. `CalendarSystem` associe donc **tous les
clubs du monde à chaque compétition** qu'il trouve. Correct tant qu'il n'y en a qu'une ;
à corriger le jour où une deuxième division apparaît. Introduire plusieurs compétitions
aujourd'hui leur ferait partager exactement les mêmes clubs.

## B.2 `MatchSystem` — jouer un match

### La force d'un club

Pas de sélection d'un onze, pas de postes. La force d'un club est la **moyenne de tout son
effectif**, séparée en deux notes :

```
   attaque(joueur)  = (finishing + passing + technique + pace) / 4
   défense(joueur)  = (defending + positioning + strength + reflexes) / 4

   attaque(club) = moyenne sur l'effectif
   défense(club) = moyenne sur l'effectif
```

Un split par **connotation d'attribut** plutôt que par poste — la seule approximation
disponible sans composant `Position`. Un club sans le moindre joueur reçoit un rating
neutre de 50 plutôt que d'être exclu.

> **⚠️ Le biais connu de cette formule.** `MatchSystem` note la **moyenne** de l'effectif
> quand le budget en contraint le **total**. Concentrer est donc légèrement avantageux :
> 14 joueurs à 60,3 de qualité battent 18 joueurs à 50,8. Un plancher d'effectif a été
> essayé pour compenser et s'est mesuré **nuisible** (rotation du top 5 retombée de
> 53,3 % à 49,2 %) ; il a été retiré. La vraie correction — ne noter que les onze
> meilleurs — demande `PositionAffinity`, hors périmètre actuel.

### Dixon-Coles

> **Définition — modèle de Poisson pour le football.** Le nombre de buts d'une équipe
> dans un match suit approximativement une loi de Poisson de paramètre λ (le nombre de
> buts *attendu*). Maher (1982) a posé le modèle ; Dixon & Coles (1997) l'ont corrigé.

```
   λ_domicile = exp( (attaque_dom − défense_ext) / strengthScale + homeAdvantage )
   λ_extérieur = exp( (attaque_ext − défense_dom) / strengthScale )
```

L'exponentielle garantit un λ toujours positif et rend l'effet du niveau **multiplicatif** :
un écart de 20 points de rating (avec `strengthScale = 20`) multiplie le nombre de buts
attendu par `e ≈ 2,72`. L'avantage du terrain (0,25) est ajouté dans l'exposant, donc vaut
`e^0,25 ≈ +28 %` de buts attendus — jamais appliqué à l'équipe visiteuse.

**La correction de Dixon-Coles.** Deux Poisson indépendantes sous-estiment les matchs nuls
serrés : dans la réalité, un 0-0 ou un 1-1 est plus fréquent que ne le prédit
l'indépendance (les équipes s'adaptent au score). La correction repondère les **quatre
scores faibles** :

```
   τ(0,0) = 1 − λμρ        τ(0,1) = 1 + λρ
   τ(1,0) = 1 + μρ         τ(1,1) = 1 − ρ
   τ(x,y) = 1              partout ailleurs

   avec ρ = lowScoreCorrelation = −0.13 (typiquement négatif)
```

Avec ρ négatif : `τ(0,0)` et `τ(1,1)` augmentent (plus de nuls serrés), `τ(1,0)` et
`τ(0,1)` diminuent.

**Le tirage** utilise la grille + inverse de CDF décrite au
[chapitre 05 §7](05-determinisme-et-aleatoire.md#7-le-tirage-sans-rejet--inverse-de-la-cdf) :
la loi corrigée n'est plus normalisée, donc on calcule les 11×11 poids, on somme, et on
inverse. **Un seul appel au générateur par match.**

Deux détails d'implémentation qui valent le coup :

- La pmf de Poisson est calculée **par récurrence** (`p(0) = exp(−λ)`, `p(k) = p(k−1)·λ/k`)
  plutôt que terme à terme. Un seul appel à `exp()` par λ, et pas de factorielle qui
  déborde.
- Un poids négatif (ρ mal calibré) est ramené à 0 : une masse négative n'a pas de sens
  pour un tirage.

### Deux sorties pour un match

`MatchSystem` écrit `MatchResult` **et** émet `MatchPlayed`. Ce n'est pas une duplication :

- `MatchResult` sert la résolution **du jour même** — `CompetitionSystem`, déclaré juste
  après, alimente le classement (canal 1) ;
- `MatchPlayed` sert tout ce qui consomme le journal d'événements sans avoir besoin d'une
  résolution immédiate : narration, digest, métriques du harness (canal 2).

## B.3 `CompetitionSystem` — le classement

Trois événements, trois comportements :

| Événement | Effet |
|---|---|
| `SeasonStarted` | `Standings` remis à vide |
| `FixtureKickoff` | lit le `MatchResult` écrit par `MatchSystem` le même tick, met à jour deux lignes |
| `SeasonEnded` | trie, émet `SeasonConcluded{finalRanking}`, **ne touche pas** `Standings` |

Le classement n'est pas remis à zéro sur `SeasonEnded` : la table doit survivre entre la
dernière journée et le début de la saison suivante, où le harness va la lire pour son
historique.

### L'ordre du classement est un ordre total

```php
usort($entries, fn($a, $b) => $b->points <=> $a->points
    ?: ($b->goalsFor - $b->goalsAgainst) <=> ($a->goalsFor - $a->goalsAgainst)
    ?: $b->goalsFor <=> $a->goalsFor
    ?: $a->clubId <=> $b->clubId);          // ← le départage qui compte
```

Points, puis différence de buts, puis buts marqués, puis **`clubId` croissant**. Ce dernier
critère n'est pas cosmétique : `Standings::$entries` est peuplé paresseusement et keyé par
`clubId`, donc son ordre d'itération est un ordre d'insertion — interdit comme source
d'ordre. En terminant sur `clubId`, l'égalité parfaite devient impossible et le résultat
cesse complètement de dépendre de l'ordre d'entrée.

Si `MatchResult` est absent (ordre du pipeline rompu par erreur), l'événement est ignoré
plutôt que de lever une exception. **Un classement qui rate une mise à jour reste
diagnosticable ; un noyau qui plante au tick 12 000 ne l'est pas.**

---

# C. L'économie

## C.1 `FinanceSystem` — le grand livre

Un système, deux mouvements de nature opposée, réunis parce qu'ils écrivent le même
composant (`Finances`) et qu'un composant n'a qu'un writer.

### Périodique : les salaires

```
   si tick % 7 == wagePaymentDayOfWeek :
       pour chaque Contract :  Finances(club) −= wagePerWeekCents
                               MonetaryMass.puits += wagePerWeekCents
```

Aucun Fait émis. Un versement de salaire est de la comptabilité de routine : ni seuil,
ni irréversibilité, ni racontabilité. Émettre un Fait par joueur et par semaine sur 20
saisons produirait ~520 000 événements de bruit.

### Réactif : la fin de saison

Déclenché par `SeasonConcluded`, **pas** par un `tick % 365` inventé — ça réutilise le
découpage en saisons déjà porté par le calendrier au lieu d'en fabriquer un second.

```
   pot        = clubIncomePerSeasonCents × nombre_de_clubs
   meritPool  = round(pot × meritShare)
   equalPool  = pot − meritPool                 ← somme exacte, aucune dérive

   poids(rang) = N − rang                        (rang 0-indexé : 1er → N, dernier → 1)
   part(club)  = equalPool/N  +  meritPool × poids / (N(N+1)/2)
```

**L'enveloppe totale ne dépend pas du classement — seule sa répartition en dépend.** Faire
varier `clubIncomePerSeasonCents` change la masse monétaire injectée dans le monde, pas
l'inégalité entre clubs. C'est `meritShare` et lui seul qui pilote l'inégalité :

```
   meritShare = 0.0   ──►  tout le monde touche la même chose
                           (modèle Premier League poussé à l'extrême)
   meritShare = 1.0   ──►  mérite pur, échelle linéaire du 1er au dernier
```

Puis, pour chaque club et dans cet ordre :

```
   1. crédit du revenu           → SeasonIncome + Finances
   2. entretien des installations → Finances  (puits)
   3. investissement              → Finances  (puits) + emit ClubInvestedInFacilities
```

**L'entretien est convexe :**

```
   entretien = facilityUpkeepPerQualityPointCents × quality²
```

Le carré, pas le linéaire. C'est **l'amortisseur mécanique de la boucle économique**, et
il vaut son propre chapitre — voir [08](08-boucles-et-retroactions.md).

**L'investissement :**

```
   investi = min( max(0, trésorerie − reserve),  plafond_par_saison )
   et 0 si le club est déjà à Facilities::MAX_QUALITY
```

Ce dernier test évite qu'un club au plafond brûle de l'argent sans contrepartie —
`FacilitiesSystem` clamperait en silence.

### Trois cas particuliers qui disent quelque chose

**Un classement vide annule la part au mérite,** quel que soit `meritShare`. La première
saison d'un monde n'a aucun match joué, donc rien à récompenser. Sans ce cas particulier,
les clubs seraient ordonnés par `clubId` faute de mieux, et **le plus petit identifiant du
monde toucherait plusieurs fois le revenu du plus grand** — une hiérarchie arbitraire
gravée à la création du monde, que le harness mesurerait ensuite comme une vraie
inégalité.

**Le reste des divisions entières n'est pas injecté.** `pot` est un plafond, pas une
quantité à épuiser. `MonetaryMass` accumule les montants **réellement crédités**, jamais le
`pot` théorique — c'est ce qui garde l'invariant de conservation vrai par construction
plutôt que par arrondi chanceux.

**`meritShare` est clampé à `[0, 1]` ici, pas validé à la construction du `Ruleset`.**
Au-delà de 1, `equalPool` deviendrait négatif et le monde injecterait de l'argent négatif
chez les derniers. Un clamp est plus sûr qu'une exception dans un noyau qui doit tourner
1 000 saisons sans surveillance.

## C.2 `FacilitiesSystem` — argent → qualité

Seul writer de `Facilities`. Deux mouvements opposés, réactifs :

```
   SeasonConcluded            →  quality −= qualityDecayPerSeason (0.05)
   ClubInvestedInFacilities   →  quality += cents / centsPerQualityPoint (200 M)

   puis clamp sur [0.5, 2.0]
```

La dégradation est **inconditionnelle**, pas « seulement si le club est dans le rouge ».
La règle binaire créerait une falaise : au calibrage actuel, un club peut passer sous zéro
pour des raisons sans rapport avec ses installations, et tout le monde s'effondrerait
ensemble. Une dérive constante que l'investissement compense donne un **équilibre continu**,
fonction directe des revenus du club.

Les deux mouvements arrivent à un tick d'écart : la dégradation au tick où `SeasonConcluded`
est traité, l'investissement au tick suivant (c'est `FinanceSystem`, traitant le même
`SeasonConcluded`, qui émet le Fait). Un club qui investit exactement de quoi compenser
retrouve son niveau avec un jour de décalage — invisible à l'échelle d'une saison.

**Pourquoi un événement et pas un composant.** `Facilities` est lu par `YouthIntakeSystem`
et `TrainingSystem`, en tête de pipeline ; son writer doit donc passer avant eux.
`Finances` est écrit par `FinanceSystem` ; tout lecteur doit passer après lui. **Aucun
ordre ne satisfait les deux.** Un système unique qui lirait l'argent et écrirait les
installations est structurellement impossible.

## C.3 `ContractSystem` — le mercato

Le système le plus riche, et le seul qui fasse **bouger un joueur d'un club à un autre**.
Il n'écrit **aucun composant** : il décide et émet des Faits (`ContractSigned`,
`ContractExpired`) ; `SquadSystem` applique au tick suivant.

**Quand :** jour 180, le même que l'intake — et volontairement placé après lui dans le
pipeline, pour qu'un club qui vient de promouvoir cinq jeunes en tienne compte
immédiatement dans son effectif plutôt qu'un an plus tard.

### Le prix d'un joueur (`Football\Support\WageModel`)

```
   qualité = moyenne( moyenne(physique), moyenne(technique), moyenne(mental) )
   salaire = baseWagePerWeekCents × clamp(qualité / referenceQuality, 0.4, 2.5)
```

**Un produit borné, jamais une composition libre de facteurs.** Le clamp n'est pas un
garde-fou défensif : c'est le modèle. Il fixe l'écart maximal de salaire entre le pire et
le meilleur joueur du monde, donc **l'amplitude de l'inégalité économique que le monde peut
produire**. Sans lui, le salaire indexé sur la qualité ferait exploser la masse salariale
dès que la distribution de talent dérive.

Les trois blocs pèsent le même tiers. Une pondération plus fine (un gardien n'a pas besoin
de `finishing`) demande `PositionAffinity`, qui n'existe pas — la moyenne plate est la
seule pondération honnête tant qu'aucun poste n'existe.

`WageModel` est une classe partagée, pas une méthode privée, parce qu'elle a **deux
consommateurs réels** : `ContractSystem` et la fabrique de population du harness (le monde
doit démarrer à la même échelle de salaires que celle vers laquelle il convergera). Deux
consommateurs réels, jamais un seul, jamais par anticipation — c'est le seul critère
d'extraction que le projet s'applique.

### L'algorithme, en trois passes

```
   ① RECENSEMENT
      Pour chaque Contract :
        - joueur sans compétences  →  ignoré (retraité du jour)
        - contrat non échu         →  compte dans l'effectif et la masse salariale engagée
        - contrat échu aujourd'hui →  rejoint la liste des expirants du club

   ② RENOUVELLEMENT (club par club)
      Trier les expirants par qualité DÉCROISSANTE (égalités : entityId croissant)
      Pour chacun, dans cet ordre :
        si effectif ≥ targetSquadSize  OU  budget dépassé  →  libéré
        sinon                                              →  prolongé au prix du marché

   ③ MARCHÉ (tour par tour)
      vivier = libérés du jour + joueurs déjà sans contrat, triés par qualité décroissante
      répéter :
        clubs en déficit d'effectif, triés par (déficit décroissant, clé de loterie)
        chacun prend le meilleur joueur qu'il peut encore payer
      jusqu'à ce qu'un tour complet ne produise aucune signature
```

Cinq décisions dans cet algorithme méritent d'être relevées.

**Le tri par qualité décroissante donne son sens à la contrainte.** Un club qui doit
couper coupe par le bas, pas au hasard.

**Le budget est une part d'un revenu, pas un plancher de trésorerie.**
`wageBudgetShare × SeasonIncome`. La règle évidente — « garder N centimes en caisse » — ne
tient pas aux ordres de grandeur du monde : un club démarre à 100 000 € et la réserve
d'investissement vaut 500 000 €, donc plus personne ne signerait jamais rien. Le budget
salarial d'un club de football se raisonne d'ailleurs en pourcentage du chiffre d'affaires.

Un club sans `SeasonIncome` (première année d'un monde) n'est **pas contraint**, plutôt que
contraint à zéro : aucune donnée ne justifie encore de lui refuser quoi que ce soit, et
refuser tout contrat viderait les effectifs avant le premier match.

**L'ordre de service est tiré au sort chaque année.** Les égalités de déficit sont
départagées par une clé de loterie tirée sur `rng(clubId)` — donc rejouée annuellement.
Trier par `clubId` aurait été plus simple et aurait gravé une hiérarchie arbitraire à la
création du monde, que le harness aurait ensuite mesurée comme une vraie inégalité :
exactement le piège documenté sur la répartition des revenus.

**Le déficit d'abord, pas la richesse d'abord.** Choix d'équilibre assumé : le salaire
indexé sur la qualité rouvre déjà un canal « riche → meilleurs joueurs », il n'y avait pas
de raison d'en empiler un second dans le même lot. Un seul levier à la fois.

**Le vivier inclut les chômeurs des années précédentes.** Sans ça, un joueur que personne
n'a repris une année ne pourrait plus jamais revenir : il faudrait qu'il reste sur le
marché exactement le jour où il en sort, ce qui n'arrive jamais.

### Ce que ce système ne fait pas

Ni indemnité de transfert, ni négociation multi-tours, ni agent, ni rupture de contrat en
cours. **Tous les mouvements sont des transferts libres.** Conséquence directe et voulue :
aucun argent ne change de mains entre clubs, donc ce lot ne crée ni ne détruit un centime,
et l'invariant de conservation monétaire reste vrai sans qu'aucune ligne de comptabilité
n'ait besoin d'être écrite ici.

Un club au-dessus de sa cible d'effectif se dégonfle donc lentement, par non-renouvellement,
sur deux à quatre ans.

### ⚠️ La simplification à ne pas prendre pour une décision de conception

La décision de renouvellement lit les compétences **vraies** du joueur. Ce n'est **pas**
une affirmation du type « un club connaît forcément bien ses propres joueurs ».

C'est une simplification de périmètre : aucun rôle non-joueur n'existe encore, et dans le
modèle visé ce n'est jamais « le club » qui évalue, mais une **personne** — scout,
entraîneur, directeur sportif — dont la compétence d'observation détermine l'erreur
d'estimation. Un club au staff médiocre doit pouvoir se tromper sur son propre joueur, le
prolonger trop cher, ou laisser filer le bon.

`ContractSystem::quality()` est donc le **premier consommateur prévu de la perception** :
le jour où `Person` + rôle existeront, c'est cette méthode qui passera de la vérité cachée
à une estimation bruitée, et rien d'autre dans le système n'aura à changer.

## C.4 `SquadSystem` — appliquer

Le plus simple, et volontairement bête. Seul writer et seul remover de `Contract` et
`SquadMembership`. **Il ne décide rien** : il n'a ni `Ruleset` à lire ni condition métier à
évaluer, parce que chaque Fait qu'il reçoit porte déjà la décision prise.

| Événement | Effet |
|---|---|
| `ContractSigned` | écrit `Contract` + `SquadMembership` |
| `ContractExpired` | retire les deux |
| `PlayerRetired` | retire les deux |

C'est ce qui le rend trivialement vérifiable, et ce qui garde toute la politique de
renouvellement à un seul endroit.

---

## Récapitulatif : le flux de données

```
                        ┌──────────────────────────────────────┐
                        │  Facilities  ◄── FacilitiesSystem    │
                        └────┬──────────────────────▲──────────┘
                             │                      │ ClubInvestedInFacilities
              ┌──────────────┼──────────┐           │ (tick+1)
              ▼              ▼          │           │
      YouthIntakeSystem  TrainingSystem │      FinanceSystem
       crée les joueurs   TrainingEffect│      ▲    │  ▲
              │                │        │      │    │  │ SeasonConcluded (tick+1)
              │                ▼        │      │    ▼  │
              │      PlayerDevelopmentSystem   │  Finances / SeasonIncome
              │        compétences ─────┼──────┘       │
              │                │        │              │
              │                ▼        │              ▼
              │        RetirementSystem │        ContractSystem
              │         PlayerRetired ──┼──┐      décide le mercato
              │                         │  │            │
              ▼                         │  ▼            ▼ ContractSigned (tick+1)
      SquadMembership / Contract  ◄─────┴─ SquadSystem ─┘
              │
              ▼
      MatchSystem ──► MatchResult ──► CompetitionSystem ──► SeasonConcluded
         ▲                                    ▲
         │ FixtureKickoff                     │ SeasonStarted / SeasonEnded
         └──────────── CalendarSystem ────────┘
```

---

**Suite :** [08 — Boucles et rétroactions](08-boucles-et-retroactions.md)
</content>
</invoke>
