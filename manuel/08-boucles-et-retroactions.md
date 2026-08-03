# 08 — Boucles et rétroactions

Ce chapitre ne parle d'aucun système en particulier. Il parle de ce qui émerge quand on
les branche ensemble — et c'est là que se joue la différence entre un monde vivant et un
monde qui se sclérose ou qui oscille.

## 1. Ce qu'est une boucle de rétroaction

> **Définition.** Une boucle de rétroaction existe quand la sortie d'un processus revient
> influencer son entrée. Elle est **positive** (amplificatrice) si l'effet renforce la
> cause, **négative** (stabilisatrice) si l'effet la contrarie.

Une boucle positive non amortie diverge : le riche devient plus riche, jusqu'à ce que le
monde n'ait plus qu'un seul acteur. C'est le mode d'échec numéro un d'un jeu de gestion
sportive — le même club gagne tout, pour toujours, et le monde cesse d'être intéressant
au bout de dix saisons.

## 2. La boucle centrale de Flair

```
        ┌──────────────────────────────────────────────────────────┐
        │                                                          │
        ▼                                                          │
   bons résultats                                                  │
        │                                                          │
        │ FinanceSystem : part au mérite (meritShare)              │
        ▼                                                          │
   plus de revenus                                                 │
        │                                                          │
        │ FinanceSystem : investissement                           │
        ▼                                                          │
   meilleures installations                                        │
        │                                                          │
        ├─────────────► TrainingSystem : meilleur TrainingEffect   │
        │                       │                                  │
        └─────────────► YouthIntakeSystem : plus grosse part       │
                        du vivier national                         │
                                │                                  │
                                ▼                                  │
                          meilleures compétences                   │
                                │                                  │
                                │ MatchSystem                      │
                                └──────────────────────────────────┘
```

C'est une boucle **positive**. Telle quelle, elle diverge. Trois amortisseurs la
contrarient.

## 3. Amortisseur n°1 — l'entretien convexe

Le plus important, et le seul qui ait été introduit **explicitement** comme
contre-réaction.

```
   entretien annuel = facilityUpkeepPerQualityPointCents × quality²
```

Le carré change tout. Comparé au linéaire (montants en **centimes**, `q = 1.0` étant le
point neutre commun aux deux versions) :

```
   quality    entretien linéaire   entretien convexe (×q²)
   ────────   ──────────────────   ───────────────────────
     0,5             7 M                    3,5 M
     1,0            14 M                   14   M
     1,5            21 M                   31,5 M
     2,0            28 M                   56   M
```

Un club qui veut passer de 1,5 à 2,0 ne paie pas 33 % de plus, il paie 78 % de plus.
**Le rendement de l'investissement décroît, et il décroît de plus en plus fort à mesure
qu'on approche du sommet.** C'est ce qui remplace le plafond arbitraire : une borne dure
aurait donné une marche (« au-dessus, rien ne change »), l'entretien convexe donne une
pente qui se redresse continûment.

Ça se défend aussi dans la fiction : un centre de formation de classe mondiale n'est pas
deux fois plus cher qu'un centre correct, il l'est nettement plus.

**Ce que ça a donné, mesuré sur graines appariées :**

| Configuration | Gini des titres, avant | après |
|---|---|---|
| Défaut (`meritShare = 0`) | 0,497 | **0,363** |
| `meritShare = 0.6` | 0,717 | **0,628** |

> **Définition — coefficient de Gini.** Une mesure d'inégalité entre 0 et 1. Appliqué aux
> titres : 0 = tous les clubs gagnent autant de fois, 1 = un club gagne tout. Emprunté à
> l'économie, standard en économie du sport pour mesurer l'équilibre compétitif.

**⚠️ Un Gini lu sur une seule graine est du bruit.** La dispersion entre graines observée
sur ce monde va de 0,363 à 0,614. Toute conclusion tirée d'un run unique est invalide —
c'est précisément pour ça que la comparaison à graines appariées existe
([ch. 09](09-mesurer-le-monde.md)).

### Le choix qui n'a pas été fait

L'alternative naturelle était un mécanisme endogène : un conseil d'administration qui
limoge, des attentes de résultats, une gouvernance de club. Elle a été écartée — hors
périmètre de la phase en cours, et coûteuse. L'entretien convexe fait le même travail
d'amortissement pour trois lignes de code, et il est *lisible* : on peut tracer sa courbe.

## 4. Amortisseur n°2 — le clamp du salaire

```
   salaire = base × clamp(qualité / référence, 0.4, 2.5)
```

Le meilleur joueur du monde coûte 6,25 fois le pire (2,5 / 0,4), jamais cinquante fois.
Combiné à `wageBudgetShare` (un club n'engage que 70 % de son revenu), ça borne la
capacité d'un club riche à accumuler du talent.

Sans ce clamp, la boucle « riche → meilleurs joueurs » rouvrirait par la porte du marché
juste après avoir été amortie par l'entretien. Les deux amortisseurs ne sont pas
redondants : ils ferment deux canaux différents de la même boucle.

## 5. Amortisseur n°3 — la normalisation du vivier de jeunes

Déjà vu au [chapitre 07](07-algorithmes-football.md#a1-youthintakesystem--lentrée-dans-le-monde),
mais il mérite d'être relu ici parce qu'il illustre un mode d'échec différent.

```
   part(club) = qualité(club) / qualité_moyenne_du_monde
   → le total mondial reste  baseIntakePerClub × nombre_de_clubs,  toujours
```

Sans cette normalisation, les installations moduleraient le **volume total** de jeunes du
monde. La boucle deviendrait :

```
   installations ─► plus de jeunes ─► effectif plus gros ─► masse salariale plus grosse
        ▲                                                            │
        └──── moins d'argent pour investir ◄─────────────────────────┘
```

Une boucle **négative**, donc a priori stabilisatrice. Sauf qu'elle a un **délai de retour
d'une carrière entière** (~15 ans) : un jeune promu aujourd'hui ne pèse sur la masse
salariale qu'à son premier renouvellement, et sur les résultats que cinq ans plus tard.

> **Le principe général, emprunté à la théorie du contrôle.** Une boucle de contre-réaction
> **retardée** et de gain suffisant n'amortit pas : elle **oscille**. Le système corrige
> une situation qui n'existe déjà plus, et sur-corrige dans l'autre sens.

Mesure à l'appui : la population balançait entre 224 et 381 individus sur 60 saisons. Deux
calibrages successifs ont changé l'amplitude de l'oscillation, jamais son existence — le
signe qu'on s'attaquait au symptôme.

La normalisation **coupe le lien** entre installations et effectif total, tout en gardant
l'effet entre clubs. La boucle disparaît au lieu d'être calibrée.

**Leçon transposable :** quand une oscillation résiste au calibrage, le problème est
structurel. Chercher la boucle, pas le coefficient.

## 6. Ce que le monde produit aujourd'hui

Mesures empiriques du 2026-08-02, sur 40 saisons, graines 42 et 7, population de 500
joueurs et 18 clubs :

| Indicateur | Mesure | Interprétation |
|---|---|---|
| Effectif stationnaire | ~313-329 dès l'année ~13 | La démographie converge et tient |
| Domicile / nul / extérieur | 41,8 % / 29,6 % / 28,6 % | Proche du football réel (~42/29/29) |
| Champions différents | 11 clubs sur 18 en 19 saisons | Pas de quasi-monopole (deux clubs à 4 titres) |
| Rotation du top 5 (mercato activé) | 47,8 % → 53,3 %, sur 6 graines sur 6 | Le mercato fait réellement bouger la hiérarchie |
| Gini des titres (effet du mercato) | 0,528 → 0,557 | **Aucun effet détectable** : bien sous la dispersion entre graines |

La dernière ligne est la plus instructive. Le mercato améliore nettement une métrique (la
rotation), et n'en bouge pas une autre (le Gini). Conclure « le mercato n'a pas d'effet »
serait faux ; conclure « il augmente l'inégalité » le serait tout autant. **Il fait
tourner les places sans changer la concentration des titres**, et il fallait deux
métriques pour le voir.

### Une découverte de calibrage qui vaut d'être retenue

Le critère de sortie initial demandait une population stationnaire sur **20 saisons**.
Elle ne l'était pas — mais elle l'est dès l'année ~13, et le reste sur 40. La fenêtre de
20 saisons était trop courte pour une population de départ qui n'était pas encore à
l'équilibre d'âge : le monde passait ses premières années à digérer sa propre condition
initiale.

**Une métrique de convergence doit être lue sur un horizon plus long que le transitoire
qu'elle mesure.** Le critère a été révisé plutôt que le monde.

## 7. Les boucles qui n'existent pas encore

Utile à savoir pour ne pas les chercher dans le code :

| Boucle | Statut |
|---|---|
| Résultats → moral → performance | Aucun composant `Morale` |
| Temps de jeu → progression | `MatchSystem` ne sait pas qui joue (pas de sélection) |
| Réputation → recrutement | Aucun composant `Reputation` |
| Inflation du marché → prix | `marketInflationTarget` et son régulateur restent à faire |
| Résultats → pression du conseil → limogeage | Aucune gouvernance de club |
| Indemnités de transfert | Hors périmètre : tous les mouvements sont libres |

La quatrième est la prochaine — c'est la moitié manquante du critère de sortie de la
phase en cours (« conservation monétaire **et** inflation dans la cible »). La première
moitié est mécanisée et verte.

## 8. Comment on décide qu'un amortisseur est bon

Une règle de méthode, visible partout dans les docblocks de ce projet :

1. **Un seul levier à la fois.** Ajouter deux amortisseurs dans le même lot rend leur
   effet respectif inobservable.
2. **Un nouveau paramètre arrive avec un défaut neutre.** `meritShare = 0.0` laisse le
   monde bit-identique ; l'effet se mesure par comparaison avant de devenir un défaut.
3. **Continu plutôt que par paliers.** Un seuil crée une falaise dans l'espace des
   paramètres (juste au-dessus il se passe quelque chose, juste en dessous rien). Le
   continu garde les métriques monotones et lisses, donc lisibles.
4. **Mesuré sur graines appariées, jamais sur un run unique.**
5. **Un résultat négatif se documente.** Le plancher d'effectif essayé et retiré est
   décrit dans le docblock de `ContractBalance::$targetSquadSize`, avec ses chiffres.
   Sans ça, la prochaine personne qui trouve l'idée bonne refait l'expérience.

---

**Suite :** [09 — Mesurer le monde](09-mesurer-le-monde.md)
</content>
</invoke>
