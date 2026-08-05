# Algorithmes de simulation

## 1. Moteur de match : le LOD (niveau de détail)

Le piège n°1 des clones de FM est de commencer par un moteur positionnel 2D. Il consomme 80 % du temps de développement et n'apporte du plaisir que sur les matchs qu'on regarde vraiment.

**Réponse : une interface, plusieurs implémentations, choisies selon l'importance du match.**

```php
interface MatchEngine
{
    public function play(TeamSheet $home, TeamSheet $away, MatchContext $ctx): MatchResult;
}
```

| Niveau | Implémentation | Coût | Sortie | Quand |
|---|---|---|---|---|
| **L0** | `PoissonEngine` (Dixon-Coles) | µs | score + stats agrégées synthétisées | ligues lointaines, histoire pré-simulée, runs d'équilibrage |
| **L1** | `PossessionMarkovEngine` | < 1 ms | **flux d'événements complet** (passes, tirs, xG, cartons, blessures, minute par minute) | **défaut** — tout match visible par un joueur |
| **L2** | `TacticalAgentEngine` | ~100 ms | positions, trajectoires | **hors périmètre v1** |

Le choix se fait par une politique, pas en dur :

```php
$engine = $this->importance($fixture) >= Importance::Observed
    ? $this->markovEngine    // L1
    : $this->poissonEngine;  // L0
```

### L0 — Dixon-Coles

Buts de chaque équipe suivant une loi de Poisson bivariée :

```
λ_home = exp(attaque_home − défense_away + avantage_terrain)
λ_away = exp(attaque_away − défense_home)
```

avec la correction `τ(x, y, λ, μ, ρ)` sur les scores faibles (0-0, 1-0, 0-1, 1-1), qui règle le défaut connu du Poisson indépendant : il sous-estime les matchs nuls serrés.

`attaque` et `défense` ne sont **pas** des paramètres libres ajustés sur des données réelles : ils sont **dérivés des attributs des joueurs alignés**, de la tactique, de la forme et de la fatigue. C'est ce qui rebranche le modèle statistique sur la simulation.

> **Comment ils sont dérivés aujourd'hui (2026-08-04).** `Football\MatchSystem` compose **le onze qu'un club alignerait** — les places de la formation (`Ruleset\PositionBalance`, un 4-4-2 unique pour l'instant) remplies gloutonnement du poste le plus spécialisé au moins spécialisé, chaque place prenant le meilleur joueur restant *à ce poste-là*. Les deux notes sont ensuite des **moyennes pondérées sur ce même onze**, chaque place contribuant selon son poste (`PositionModel::sectorWeights()` : un gardien pèse 0,30 en défense et rien en attaque, un attaquant l'exact miroir).
>
> Deux points qui ne sont pas des détails :
>
> - **Un seul onze pour les deux notes.** En composer deux — les onze meilleurs attaquants pour l'attaque, les onze meilleurs défenseurs pour la défense — laisserait un club aligner vingt-deux joueurs et rendrait mécaniquement meilleur un gros effectif.
> - **Le profil de valeur marginale est désormais correctement signé** : positif sur les onze premiers, **nul** au-delà. Le système notait auparavant la *moyenne de tout l'effectif*, ce qui donnait une valeur marginale **négative** à tout joueur sous le niveau du groupe — recruter un joueur de rotation faisait baisser la note du club. C'est une précondition du marché (§5) : un acheteur doit pouvoir répondre « ce joueur vaut-il son prix pour moi ? ». La profondeur ne vaut aujourd'hui rien plutôt que de coûter ; sa vraie valeur suppose blessures et rotation, qui n'existent pas.
>
> Tactique, forme et fatigue restent à brancher. Un club incapable d'aligner onze joueurs voit ses places vides comptées au plancher de l'échelle — dégénérescence assumée, le forfait réglementaire n'étant pas modélisé (ni pyramide où reléguer, ni défaillance de club, §7).

### L1 — Chaîne de Markov de possession

Le modèle recommandé par défaut. Rapport réalisme/coût imbattable, et il produit gratuitement toute la matière narrative.

- **État** = `(zone du terrain, équipe en possession, phase)`. Une grille de 6×4 zones suffit largement.
- **Transitions** = passe vers une zone adjacente, dribble, perte de balle, tir, faute — dont les probabilités sont modulées par les attributs des joueurs impliqués dans la zone concernée.
- Un match = ~2 000 pas de chaîne, soit deux mi-temps de possessions enchaînées.

Ce qu'on obtient sans effort supplémentaire :
- un **flux d'événements minute par minute** (matière du client et de la narration) ;
- des **statistiques cohérentes** (possession, tirs, xG) puisqu'elles sont émergentes et non synthétisées ;
- des **notes de joueurs gratuites** via l'*expected threat* : la valeur d'une action est la variation de probabilité de marquer entre deux états. C'est exactement le principe de VAEP/xT, et il tombe naturellement de la structure du modèle.

### ⚠️ Le contrat de substitution (LSP appliqué)

> L0 et L1 doivent produire les **mêmes distributions agrégées**.

Sinon, promouvoir une ligue de L0 à L1 parce qu'un joueur y arrive **change l'histoire du monde** : les scores, les classements et les champions deviennent différents selon qui regarde. C'est une violation de Liskov qui se voit en jeu.

Test de calibration obligatoire en CI :

```
Pour 10 000 confrontations identiques :
  distribution des scores L0 ≈ distribution des scores L1   (test de Kolmogorov-Smirnov)
  taux de victoire/nul/défaite : écart < 1 point de %
  moyenne de buts par match     : écart < 0.05
```

---

## 2. Développement des joueurs

### Potentiel : une courbe, pas un plafond

FM utilise un `PA` fixe, plafond dur. C'est mauvais design : la progression devient binaire et le joueur en veut au jeu (« il avait un PA de 120, tout ce travail pour rien »).

On modélise le potentiel comme une **trajectoire** :

```php
final readonly class Potential
{
    public function __construct(
        public int   $ceiling,     // asymptote SOUPLE, pas un mur
        public int   $peakAge,     // 26-29 typiquement, variable
        public float $growthRate,  // vitesse d'approche
        public float $fragility,   // sensibilité aux blessures et à l'inactivité
    ) {}
}
```

Progression par tick, sous la forme **base × modificateurs bornés** (voir §3, qui explique pourquoi la forme purement multiplicative est à proscrire) :

```
base    = f(écart au plafond) × g(âge)          ← le moteur de la progression
modif   = clamp( h(entraînement) × i(temps de jeu) × j(moral), 0.5, 2.0 )
Δskill  = base × modif + bruit
```

avec :
- `f` décroissant vers 0 près du plafond (asymptotique, jamais bloquant net) ;
- `g` : forte avant 23 ans, plate jusqu'au pic, négative après ;
- `i` : **le temps de jeu réel compte** — c'est ce qui donne du sens au métier d'agent (placer son client là où il jouera) ;
- bruit à queue épaisse : quelques éclosions inattendues et quelques échecs par saison. C'est ce qui produit les histoires.

Le découpage entre `base` et `modif` n'est pas cosmétique : `base` répond à « ce joueur peut-il encore progresser ? », `modif` à « son environnement l'aide-t-il ? ». Le second est borné parce qu'**un environnement ne doit jamais pouvoir annuler ni décupler le potentiel** — un joueur mal encadré progresse lentement, il ne stagne pas à zéro.

### Éviter le déterminisme narratif

Si le potentiel est intégralement fixé à la naissance, les meilleurs scouts « résolvent » le monde. Ajoute une composante **révélée par l'expérience** : une partie du potentiel ne se cristallise qu'après un certain nombre de matchs professionnels. Personne ne peut la connaître à l'avance — pas même le moteur, tant que ça n'est pas arrivé.

---

## 3. Composer des facteurs sans rendre le modèle inéquilibrable

Une règle transverse, qui s'applique à toutes les formules du projet. Elle mérite sa propre section parce que **c'est le défaut le plus répandu dans les simulations amateurs — et la doc initiale de ce projet en souffrait aussi**.

### Le piège du multiplicatif à N facteurs

`ressource2.md` §6 propose, pour la progression d'un joueur :

```
Progression = Entraînement × Talent × Coach × Installations
            × Météo × Professionnalisme × Fatigue
```

L'intuition est juste : le même entraînement ne produit pas le même effet selon l'environnement. **La forme, elle, est inexploitable**, pour trois raisons.

**1. Les extrêmes explosent.** Sept facteurs variant entre 0.8 et 1.2 donnent un résultat entre ×0.21 et ×3.58 — un rapport de 17 entre le pire et le meilleur cas. Le gros de la distribution reste entre ×0.5 et ×2, mais les queues sont ingérables et produiront des trajectoires aberrantes plusieurs fois par saison.

**2. Aucun facteur n'est réglable indépendamment.** L'effet d'un changement sur `Coach` dépend de la valeur des six autres. Tu ne peux plus raisonner sur un levier isolé, ni interpréter un run d'équilibrage : tout interagit avec tout. C'est le vrai tueur, plus encore que les extrêmes.

**3. Un facteur nul annule tout.** Un joueur épuisé ne progresserait *pas du tout*. C'est faux, et ça crée des états absorbants dont on ne sort plus.

### La forme recommandée

```
résultat = base × clamp( Π modificateurs, min, max )
```

Avec quatre disciplines :

| Discipline | Pourquoi |
|---|---|
| **Séparer `base` et `modificateurs`** | `base` porte le phénomène, les modificateurs le nuancent. Deux natures, deux traitements. |
| **Borner le produit** (`clamp(·, 0.5, 2.0)` typiquement) | garantit qu'aucune combinaison d'environnement ne peut annuler ni décupler le phénomène |
| **Peu de modificateurs** (3-4 max) | au-delà, l'interprétabilité disparaît |
| **Documenter la plage de chaque facteur et ce que vaut `1.0`** | sans ça, personne — toi compris dans six mois — ne sait si `0.9` est un petit ou un gros malus |

Quand il faut vraiment beaucoup de facteurs, passer en **somme pondérée** (éventuellement en espace logarithmique) : les contributions redeviennent additives, donc lisibles et réglables une par une.

### Où appliquer la règle

Partout où plusieurs influences se combinent : progression (§2), probabilité de blessure, valorisation d'un joueur (§5), affluence, décision d'un PNJ. À chaque fois qu'une formule de ce document contient plus de trois `×`, elle doit être reformulée.

---

## 4. Scouting et information

Traité en détail dans `12-modele-du-monde.md` §4. Le principe algorithmique :

```
estimation = vraie_valeur + bruit(σ)
σ = σ₀ / √(1 + observations × qualité_du_scout)
```

C'est un modèle d'observation bayésien dégradé — suffisant, et beaucoup plus simple à équilibrer qu'un vrai filtre de Kalman.

Deux effets recherchés :
- **La confiance a un coût** : réduire σ demande du temps et de l'argent.
- **Les erreurs de perception créent le marché** : sans divergence d'estimation entre clubs, il n'y a pas d'échange profitable. C'est mathématiquement le moteur de tout le jeu d'agent.

---

## 5. Marché des transferts

### Ce qu'il ne faut pas faire

Une enchère qui converge instantanément. Le marché se « résout », les prix sont justes, il ne se passe rien. C'est correct économiquement et mort ludiquement.

### Négociation séquentielle multi-tours

Pendant une fenêtre de mercato, le `TransferSystem` exécute un **tour de marché par tick** :

1. **Analyse de besoin** — chaque club évalue son effectif par poste (`gap analysis`) contre les attentes du board et son budget.
2. **Sélection de cibles** — classement par `valeur perçue / prix estimé`, sous contrainte de budget et de masse salariale.
3. **Offre** — au club vendeur, éventuellement via l'agent du joueur.
4. **Évaluation vendeur** — comparaison au **prix de réserve**, dérivé de : durée de contrat restante, profondeur d'effectif au poste, besoin financier, pression du board, réputation de l'acheteur.
5. **Évaluation joueur + agent** — salaire, temps de jeu attendu, réputation du club, ambition, pays, relation avec l'entraîneur, **commission de l'agent**.
6. **Contre-offre ou rupture** — avec mémoire des tours précédents (les négociations ont une histoire).

Chaque étape émet des événements → matière narrative et fil d'actualité du marché.

> Si tu as besoin d'une garantie de convergence (par exemple pour la période de pré-simulation historique), un algorithme d'acceptation différée (Gale-Shapley) donne un appariement stable. Mais pour le jeu, la négociation stochastique est meilleure : elle produit des surprises et des histoires.

### Valorisation

Sous la forme bornée du §3, et non en produit de cinq facteurs libres :

```
base   = f(qualité perçue) × courbe_âge(âge, pic)      ← ce que vaut le joueur
modif  = clamp( facteur_contrat × rareté_poste × richesse_acheteur, 0.4, 2.5 )
valeur = base × modif × indice_inflation_global
```

Trois précisions sur ce découpage :

- **`facteur_contrat` est la seule exception admise à la borne basse.** Un joueur à 6 mois de la fin de contrat s'effondre réellement — c'est un fait du football, pas un artefact. On l'applique donc **après** le clamp, avec sa propre courbe explicite, plutôt que de relâcher la borne pour tout le monde.
- **`indice_inflation_global` est hors du clamp** : ce n'est pas un modificateur de situation mais un changement d'unité monétaire. Il s'applique uniformément à tout le marché.
- La borne haute à 2.5 autorise qu'un club riche paie deux fois et demie le prix « juste » pour un gardien titulaire rare — c'est réaliste — sans permettre les surenchères ×10 qui font exploser l'économie en trois saisons.

L'`indice_inflation_global` est piloté par la masse monétaire du monde, ce qui referme la boucle avec l'économie (§6).

### L'agent comme intermédiaire

C'est le rôle incarné par le joueur humain. Son utilité :

```
U(agent) = commission + satisfaction_client + gain_de_réputation − coût_temps
```

Ces trois termes sont en tension — placer un client au club le plus offrant peut le rendre malheureux (pas de temps de jeu), ce qui détruit la relation et donc les revenus futurs. **Cette tension est la boucle de jeu.** Elle doit être vérifiable sur un prototype papier avant d'être codée (voir `15-roadmap.md`).

---

## 6. Économie : la boucle fermée

**C'est ici que meurent les mondes persistants.** Hattrick l'a appris à ses dépens.

Il faut un **grand livre explicite** de tous les flux :

| Injections (argent créé) | Puits (argent détruit) |
|---|---|
| Droits TV | Salaires versés hors du système clubs |
| Sponsors | Impôts |
| Billetterie (argent des supporters) | Frais de fonctionnement |
| Merchandising | Commissions d'agents (partiellement) |
| Primes de compétition | Amortissement des infrastructures |

Les transferts entre clubs **conservent** la monnaie : ils ne sont ni source ni puits. Ce sont les injections et les puits qui déterminent l'inflation.

### Invariant testé en continu

```
Σ injections − Σ puits = Δ masse monétaire totale     (à l'euro près)
```

Cet invariant est un test automatisé du harness. S'il casse, un système crée de l'argent en douce — et le monde mourra d'inflation dans deux ans de temps simulé.

### Cible de régulation

Le ruleset définit un `marketInflationTarget` (ex. 3 %/an). Un régulateur simple ajuste les injections marginales pour tenir la cible. C'est artificiel, mais assumé : **un monde persistant est une économie administrée, pas une économie libre.**

---

## 7. Équilibre compétitif : empêcher la sclérose

Le comportement naturel du système — confirmé par la littérature multi-agents sur les cinq grands championnats — est que **les riches gagnent et s'enrichissent**. Sans force de rappel, au bout de 15 saisons simulées, trois clubs se partagent tout et le monde est mort.

### La boucle à amortir, et sa contre-réaction

Toutes les boucles ne sont pas des bugs. Celle-ci est **voulue** — c'est elle qui donne un sens à la réussite sportive :

```
Bons résultats → Réputation → Argent → Meilleurs joueurs → Bons résultats
```

Le problème n'est pas qu'elle existe, c'est qu'elle est **positive et non amortie** : laissée seule, elle diverge. Il lui faut une contre-réaction explicite, et la meilleure est celle que `ressource.md` pt. 6 identifie :

```
Succès → Attentes du board ↑ → Pression ↑ → Risque de crise ↑
                                              ↓
                          limogeage → nouvelle stratégie → rupture de dynamique
```

Élégante parce qu'elle est **narrative avant d'être mécanique** : le club qui gagne devient exigeant, donc fragile. On ne bride pas artificiellement les riches, on rend leur position instable. Le même mécanisme produit de la régulation *et* des histoires.

Mécanismes de régulation à intégrer, tous paramétrés par le ruleset :

| Mécanisme | Effet | Nature |
|---|---|---|
| **Attentes qui suivent le succès** | le vainqueur devient fragile | contre-réaction endogène |
| Impatience des dirigeants | licenciements → instabilité des meilleurs clubs | contre-réaction endogène |
| Vieillissement + retraite | empêche l'accumulation infinie de talent | contre-réaction endogène |
| Partage des droits TV | comprime l'écart de revenus | régulation exogène |
| Promotion / relégation | renouvelle le peloton en permanence | régulation exogène |
| Formation locale | les clubs pauvres produisent de la valeur | régulation exogène |
| Fenêtres de mercato | empêche la consolidation continue | régulation exogène |

Privilégier les contre-réactions **endogènes** : elles se justifient dans la fiction (« la pression monte »), là où les régulations exogènes se ressentent comme des règles arbitraires imposées de l'extérieur.

### Le test qui compte

```
Simuler 20 saisons × 100 graines, puis mesurer :
  Gini des titres            < seuil   (aucun club ne domine)
  Gini des revenus           < seuil
  taux de rotation du top 5  > seuil   (le sommet bouge)
  probabilité de remontée d'un promu   > 0
```

Ce test est **la définition opérationnelle de « le monde fonctionne »**. Il doit exister avant l'API, avant les clients, avant tout.

---

## 8. Le harness d'équilibrage : ton vrai avantage

Le noyau étant pur et rapide (une saison en quelques dizaines de secondes), tu peux :

- lancer **1 000 saisons en parallèle** sur une machine de dev ;
- faire du **balayage de paramètres** sur les valeurs de `ruleset.balance` ;
- comparer les distributions produites à des **données réelles** (répartition des scores en L1 française, distribution des montants de transfert, courbes d'âge) ;
- détecter une régression d'équilibrage à chaque commit, comme un test unitaire.

Sortie : un rapport (Markdown ou HTML) avec les métriques de santé du monde et leur évolution.

**Un dev solo qui construit ça a un avantage décisif sur un studio qui ne l'a pas.** C'est la seule façon d'équilibrer une simulation sans une équipe de testeurs.

---

## 9. Narration émergente

`NarrativeSystem` est en lecture seule sur le flux d'événements. Il n'invente rien : il **détecte** des motifs et les habille.

Détecteurs déclaratifs (données, comme les événements de Crusader Kings) :

```jsonc
{
  "id": "wonderkid.debut",
  "when": { "event": "PlayerDebut", "playerAge": { "<": 18 },
            "competitionTier": 1 },
  "weight": 0.8,
  "template": "À {age} ans, {player} fait ses débuts pour {club} contre {opponent}."
}
```

Motifs à haute valeur narrative : victoire dans les arrêts de jeu contre un rival, sauvetage du maintien à la dernière journée, entraîneur limogé après cinq défaites, ancien joueur revenu comme entraîneur, transfert record battu, légende de club qui raccroche.

C'est **peu coûteux et à très fort rendement** : c'est ce qui transforme une base de données qui tourne en un monde qu'on a envie de suivre. À faire tôt, pas à la fin.

### Le digest de retour d'absence

Le monde tourne sans le joueur — c'est le principe même d'un monde persistant. Il faut donc répondre à la question qu'il se pose en revenant :

> **« Il s'est passé trois mois. Qu'est-ce que j'ai raté ? »**

Le digest est la sortie de premier rang du `NarrativeSystem`, pas un raffinement tardif :

```
Depuis ta dernière connexion (14 mars → 2 juin) :

  Tes clients
    ▸ M. Diallo a marqué 7 buts en 9 matchs — sa valeur a doublé
    ▸ K. Novak s'est blessé (6 semaines), il rate la fin de saison
    ▸ 2 clubs se sont renseignés sur T. Bakker

  Ton monde
    ▸ Le FC Rennes est relégué à la dernière journée
    ▸ Transfert record du championnat : 47 M€
```

Trois raisons d'en faire un livrable et non une option :

1. **C'est quasi gratuit** une fois l'event log et les seuils d'émission en place (`16-` §2). Les faits notables sont déjà filtrés et journalisés ; il ne reste qu'à les trier par pertinence pour *les entités du joueur* — ses clients, ses clubs, ses rivaux.
2. **C'est ce qui sert le pilier « respect du temps du joueur ».** Sans digest, un monde persistant punit l'absence : on revient perdu. Avec, l'absence devient une ellipse narrative — un atout plutôt qu'une dette.
3. **C'est le meilleur test de la qualité des seuils.** Si le digest est illisible ou insignifiant, c'est que les seuils d'émission sont mal réglés. Il fait office de contrôle qualité de tout le flux d'événements.

Le tri par pertinence est le seul vrai travail : classer par `(proximité au joueur × amplitude du fait × fraîcheur)`, en respectant la règle de composition du §3 — peu de facteurs, bornés.

---

## 10. Résumé — priorité d'implémentation

| Ordre | Algorithme | Pourquoi ce rang |
|---|---|---|
| 1 | L0 Dixon-Coles | débloque tout le reste, coûte deux jours |
| 2 | Développement + vieillissement | sans ça, le monde n'évolue pas |
| 3 | Finance + invariant monétaire | sans ça, le monde meurt et tu ne le sais pas |
| 4 | Compétitions (formats en données) | structure le temps du monde |
| 5 | **Harness d'équilibrage** | à partir d'ici, tu pilotes au lieu de deviner |
| 6 | Marché + perception | c'est le jeu de l'agent |
| 7 | L1 Markov | qualité perçue et matière narrative |
| 8 | Narration | ce qui fait revenir les joueurs |
| — | L2 positionnel | **ne le fais pas** (avant très longtemps) |
