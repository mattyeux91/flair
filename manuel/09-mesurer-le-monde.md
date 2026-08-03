# 09 — Mesurer le monde

Un monde émergent produit des comportements qu'on n'a pas écrits. Le seul moyen de savoir
s'il est plausible, c'est de le faire tourner et de le mesurer. C'est le rôle de
`packages/harness/`.

## 1. Pourquoi un package séparé

Le harness **dépend** du kernel, jamais l'inverse. Cette direction est structurelle :

```
   kernel   → (rien)
   harness  → kernel
```

Bénéfice concret : le harness peut écrire directement dans un `WorldState` (il n'est pas
un système, il n'a pas de déclarations à respecter), construire des populations
synthétiques, et lire n'importe quel composant sans passer par les gardes du pipeline.
C'est exactement ce que ferait un futur `worldgen`, et exactement ce qu'un système du
noyau n'a pas le droit de faire.

## 2. Le monde synthétique

Le harness fabrique un monde de départ : N joueurs, M clubs, une compétition.

Un détail de conception vaut la peine : **la population initiale et l'intake tirent leur
talent selon la même loi**, via la même `Football\Generation\PlayerFactory`. Ce n'est pas
de la mutualisation de code par confort.

> Si les deux producteurs de joueurs ne tiraient pas selon la même loi, la pyramide des
> âges **ne pourrait pas être stationnaire** : le monde convergerait mécaniquement vers la
> distribution de l'intake, quelle qu'elle soit, et le critère de stationnarité deviendrait
> ininterprétable. La duplication ne serait pas du code en double, elle serait une
> divergence silencieuse du modèle.

Même raisonnement pour les salaires : `PopulationFactory` utilise `WageModel`, pour que le
monde démarre à l'échelle de salaires vers laquelle il convergera. Sinon la masse
salariale dérive pendant les quatre premières années et la ligne de base du grand livre
n'est comparable à rien.

## 3. La technique centrale : les graines appariées

**Le problème.** On change `meritShare` de 0 à 0,6, on relance, le Gini des titres passe de
0,49 à 0,72. Est-ce l'effet du paramètre ? Ou du bruit ? Sur un monde stochastique, la
dispersion entre deux graines dépasse largement l'effet de la plupart des paramètres.

**La solution classique et coûteuse :** lancer 20 runs par configuration et comparer les
moyennes. 5 à 20× plus de calcul.

**La solution retenue :** rejouer **exactement le même jeu de graines** avec les deux
`Ruleset`.

```php
public function compare(PopulationSpec $spec, Ruleset $baseline, Ruleset $modified): array
{
    return [
        'baseline' => $this->runOnce($spec, $baseline),
        'modified' => $this->runOnce($spec, $modified),
    ];
}
```

```
   graine 42 ─┬─► Ruleset baseline  ─► métriques A
              └─► Ruleset modifié   ─► métriques B      Δ = B − A
   graine  7 ─┬─► ...
              └─► ...
```

Même population de départ, mêmes joueurs, mêmes tirages là où le paramètre n'intervient
pas. **Le bruit est en grande partie soustrait**, et un seul run par configuration suffit
à voir un effet.

C'est ce qui rend l'équilibrage praticable en solo : un cycle complet
(baseline + modifié + delta) prend ~1 min 49 s sur ce monde, contre plusieurs dizaines de
minutes pour la méthode par moyennes.

⚠️ Les graines ne se réapparient pas parfaitement : un paramètre qui change le **nombre de
tirages** effectués (par exemple `talentSkew`, qui borne une boucle) désaligne les flux à
partir de ce point. Le gain reste massif, il n'est pas total. D'où l'usage systématique de
**plusieurs graines** pour toute conclusion (les mesures de référence en utilisent six).

## 4. Ce qu'on mesure

### Démographie

- **Effectif actif par année** — la stationnarité de la population. C'est le premier
  critère de santé : un monde qui gonfle ou se vide n'est pas simulable longtemps.
- **Pyramide des âges** de la dernière année.
- **Distribution des âges de retraite.**
- **Courbes de compétence par âge** — attention, c'est une **coupe transversale** (la
  moyenne des joueurs de 25 ans à un instant donné), pas la trajectoire d'un individu. Les
  deux ne coïncident pas quand la composition de la population change.

### Football

- **Distribution des buts par match**, scores exacts triés par fréquence.
- **Répartition domicile / nul / extérieur** — la métrique la plus directement comparable
  au réel (~42/29/29 %).
- **Classement et matchs** de la dernière saison.

### Équilibre compétitif

- **Gini des titres** — 0 = tous les clubs gagnent autant, 1 = un club rafle tout.
- **Rotation du top 5** — 0 = les mêmes cinq clubs chaque saison, 1 = renouvellement total.
- **Nombre de champions différents** sur le run.
- **Gini des revenus** — calculé sur les revenus cumulés relevés dans `SeasonIncome`.

> **Pourquoi le Gini des revenus se calcule sur `SeasonIncome` et pas sur `Finances`.**
> `Finances` est un **stock** qui dérive vers le négatif au calibrage actuel (le revenu
> d'une saison est légèrement inférieur à la masse salariale annuelle), et **un Gini sur
> des valeurs négatives n'a pas de sens**. `SeasonIncome` est un **flux**, et c'est le flux
> qui porte l'inégalité qu'on veut mesurer.

### Graphe d'événements (`--event-graph`)

Deux signaux, choisis pour ce qui est réellement mesurable sans changer le contrat du
noyau :

- **Volume par type d'événement** sur tout le run. Une explosion du nombre d'occurrences
  d'un type, saison après saison, est le premier symptôme visible d'une cascade non
  amortie.
- **Backlog annuel du `Scheduler`.** Une file qui grossit sans jamais redescendre est le
  signe d'une accumulation non amortie.

Pourquoi seulement le `Scheduler` : l'`OutQueue` se vide entièrement à chaque tick (c'est
la garantie structurelle du [chapitre 04](04-messages-et-files.md)). Son `count()` après un
`step()` est donc mécaniquement le nombre d'événements de ce tick — déjà capturé par le
tally par type. Seul le `Scheduler`, dont les échéances sont arbitrairement lointaines,
peut réellement grossir sans se vider.

**Ce qui n'est pas mesurable, et pourquoi.** La **profondeur de cascade** et les **entités
sur-modifiées** demanderaient un lien causal sur les événements (`causedBy`,
`correlationId`, `depth`). `StepResult::$events` ne renvoie qu'une `list<DomainEvent>` nue.
Ajouter ce lien toucherait `SystemContext::emit()`/`schedule()`, `OutQueueEntry`,
`ScheduledEntry` et tous les sites d'émission : **un changement de contrat du noyau, pas
une extension du harness.** C'est documenté comme limitation assumée plutôt que contourné.

## 5. Les invariants testés en continu

Trois filets, de nature différente.

### Déterminisme (`Tests\Determinism\DeterministicRunTest`)

Deux runs, même graine, pipeline complet. On compare **deux hashs** :

1. le hash de l'état final du monde ;
2. le hash de la **séquence complète des événements émis**.

Le second n'est pas redondant : deux mondes peuvent arriver au même état par des chemins
différents, et pour un moteur dont le journal sera la source de vérité persistée, le
chemin compte autant que l'arrivée.

`WorldHasher` **énumère explicitement** les types de composants football qu'il connaît —
`WorldState` n'exposant volontairement aucun balayage global ([ch. 02](02-le-modele-de-donnees.md)).
Conséquence à connaître : **un nouveau composant n'est pas hashé tant qu'on ne l'ajoute
pas à cette liste.** C'est un point de la checklist du [chapitre 10](10-etendre-le-moteur.md).

⚠️ Ce hash vaut pour **la même machine et la même version de PHP**. Ce n'est pas une forme
canonique cross-machine — voir [ch. 05 §6](05-determinisme-et-aleatoire.md).

### Conservation monétaire (`Tests\Regression\MonetaryConservationTest`)

```
   Σ injections − Σ puits  =  Δ masse monétaire totale
```

Vérifié sur 20 saisons, avec `assertSame` sur des **entiers**, pas
`assertEqualsWithDelta`. Cette rigueur n'est possible que parce que tout l'argent du monde
est en **centimes entiers**, jamais en flottants — avec des flottants, une égalité exacte
après des millions d'additions ne serait pas vérifiable à coup sûr.

Le test ne recalcule **pas** `MonetaryMass` indépendamment : il compare la variation réelle
de `Finances` au bookkeeping du singleton, qui est un sous-produit direct de la même
boucle. Une divergence entre les deux signale un système qui crée ou détruit de l'argent
sans le comptabiliser — pas une erreur de recopie de la logique métier dans le test.

Deux cas, et le second est celui qui compte :

| Cas | Ce qu'il attrape |
|---|---|
| `meritShare = 0` (plat) | Presque rien — il ne peut pratiquement pas échouer |
| `meritShare = 0.6` | La répartition au mérite découpe l'enveloppe par divisions entières successives : **exactement le genre de calcul qui perd ou invente des centimes** |

Le second cas embarque en prime un garde-fou : il vérifie qu'un écart de revenus réel
existe bien entre clubs. Sans ça, il ne prouverait rien de plus que le cas plat.

### Non-régression de calibrage (`Tests\Regression\CalibrationRegressionTest`)

Assertions numériques directes sur un run complet (500 joueurs, 18 clubs, 25 saisons), avec
des **bornes larges** (±8 points, 250-400 joueurs). Le dosage est délibéré : attraper une
vraie régression de calibrage sans réagir au bruit normal entre graines. Un test serré
ici serait un test qu'on finirait par désactiver.

Et `Tests\Regression\SquadIntegrityTest` vérifie l'invariant de cohérence entre `Contract`
et `SquadMembership` (même `clubId`) — une relation portée par deux composants distincts
n'est pas garantie par le type système.

## 6. Les invariants côté kernel

`Football\PipelineInvariantsTest` est un test peu ordinaire : il ne teste pas un
comportement, il teste **la structure du pipeline**.

- Un seul writer par composant.
- Un seul remover par composant.
- Aucun système ne lit un composant écrit ou retiré par un système placé plus loin
  (dépendance inversée).
- L'ordre écrit à la main dans `FootballPipeline::declaration()` s'accorde déjà avec
  l'ordre dérivé par `SystemGraph`.

Ce dernier point est subtil : si quelqu'un casse l'ordre de la déclaration, **le runtime
continue de fonctionner** (le tri topologique corrige) mais le test proteste. On garde
ainsi une liste écrite lisible *et* une garantie d'exécution.

⚠️ Ce test compare des **déclarations entre elles**. Il ne peut structurellement pas voir
un accès non déclaré — c'est le rôle des gardes de `SystemContext`
([ch. 03](03-le-tick-et-le-pipeline.md)). Les deux mécanismes sont complémentaires, et
aucun ne remplace l'autre.

## 7. Les deux outils

```bash
# Rapport agrégé : « est-ce que le monde est plausible sur N saisons ? »
php packages/harness/bin/aggregate.php --years 40 --seed 42

# Comparaison à graines appariées : « quel est l'effet de ce paramètre ? »
php packages/harness/bin/aggregate.php --set meritShare=0.6 --years 40

# Stepper interactif : « que se passe-t-il exactement à cet instant ? »
php packages/harness/bin/sandbox.php
```

La présence d'au moins un `--set` bascule automatiquement en mode comparaison. Un champ
inconnu ou une valeur hors bornes fait échouer la commande **avant toute simulation** —
plutôt qu'après quarante minutes de calcul.

`composer serve` expose la même chose que `aggregate.php` sous forme d'interface web, pour
qui préfère cliquer que taper des `--set`.

## 8. La méthode, en une checklist

Ce qui distingue un calibrage exploitable d'une impression :

1. **Formuler l'hypothèse avant de lancer.** « L'entretien convexe devrait faire baisser
   le Gini des titres. » Sans hypothèse, on trouve toujours quelque chose dans un rapport
   de trente métriques.
2. **Comparer à graines appariées**, jamais deux runs indépendants.
3. **Plusieurs graines.** Six pour les mesures de référence de ce projet.
4. **Regarder plusieurs métriques.** Le mercato améliore la rotation du top 5 sans toucher
   le Gini : une seule métrique aurait donné une conclusion fausse dans les deux sens.
5. **Comparer l'effet à la dispersion entre graines.** Un delta de Gini de 0,03 quand la
   dispersion va de 0,363 à 0,614 est du bruit, pas un résultat.
6. **Documenter les résultats négatifs.** Ils coûtent aussi cher à obtenir que les
   positifs, et sans trace ils seront repayés.

---

**Suite :** [10 — Étendre le moteur](10-etendre-le-moteur.md)
</content>
</invoke>
