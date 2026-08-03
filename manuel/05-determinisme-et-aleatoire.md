# 05 — Déterminisme et aléatoire

## 1. Pourquoi c'est non négociable

> **Définition — déterminisme.** Mêmes entrées + même graine ⇒ mêmes sorties, bit pour bit.

Le déterminisme n'est pas une élégance d'ingénieur. Sans lui, quatre choses deviennent
impossibles :

1. **Déboguer.** « Le monde part en vrille à l'année 34 » n'est actionnable que si on
   peut rejouer et s'arrêter à l'année 33.
2. **Équilibrer.** Comparer deux jeux de règles n'a de sens que si on peut isoler l'effet
   du paramètre du bruit stochastique. C'est toute la technique des **graines appariées**
   ([ch. 09](09-mesurer-le-monde.md)).
3. **Persister efficacement.** Un event store + un snapshot occasionnel ne suffisent à
   reconstruire l'état que si le rejeu est exact.
4. **Tester.** Un test qui échoue une fois sur cinquante est un test qu'on finit par
   supprimer.

Et surtout : **le déterminisme se décrète mal.** On ne le vérifie pas en relisant le code,
parce qu'une violation ne produit aucune erreur — juste une divergence qu'on découvre des
mois plus tard. Il s'obtient par des règles structurelles qu'on applique sans exception,
et par un test qui compare deux hashs.

## 2. Les quatre sources de non-déterminisme, et leur parade

| Source | Parade dans ce projet |
|---|---|
| Le hasard non contrôlé (`rand()`) | PRNG maison, graine dérivée de manière reproductible (§3) |
| L'ordre d'itération | `ComponentStore::entities()` trie toujours ; files triées par clé totale |
| Le monde extérieur (horloge, env, disque) | Interdits dans `kernel`, le seul temps est `$ctx->tick` |
| L'arithmétique (débordement, flottants) | Arithmétique 32 bits masquée (§5), ordre de sommation fixé (§6) |

## 3. Un flux aléatoire par (monde, tick, système, entité)

C'est la décision la plus structurante de ce chapitre.

**Le piège du PRNG global.** Un générateur unique partagé par tout le monde produit une
séquence dont le n-ième tirage dépend de *combien de tirages ont eu lieu avant*. Ajouter
un joueur, ou faire tirer un système une fois de plus, décale toute la suite. Le monde
devient sensible à des détails d'implémentation sans rapport, et deux calibrages ne sont
plus comparables.

**La parade :** chaque tirage part d'un flux dédié, dérivé de quatre valeurs.

```php
// SystemContext
public function rng(int $entityId): Rng
{
    return Rng::forStream($this->worldSeed, $this->tick, $this->access->systemId, $entityId);
}

// Rng
public static function forStream(int $worldSeed, int $tick, string $systemId, int $entityId): self
{
    return new self(Hash::mix32($worldSeed, $tick, crc32($systemId), $entityId));
}
```

```
   (worldSeed, tick, systemId, entityId)
                │
                ▼  Hash::mix32  — XOR-fold + avalanche murmur3
          graine 32 bits
                │
                ▼  splitMix32 ×4
          état xoshiro128** (s0,s1,s2,s3)
                │
                ▼
          séquence de nextUint32()
```

Conséquences directes :

- **Ajouter une entité ne décale aucun autre flux.** Le joueur 500 tire la même chose,
  qu'il y ait 499 ou 4 999 joueurs avant lui.
- **Ajouter un système ne décale rien non plus** : `systemId` est une chaîne stable
  (`'retirement'`, `'match'`), pas un index de pipeline. Réordonner le pipeline ne change
  aucun tirage. (C'est pour ça que `SystemContext` porte les deux : `systemIndex` pour
  trier les événements, `systemId` pour dériver le hasard.)
- **Un tirage est reproductible à la demande.** On peut rejouer *exactement* le tirage de
  retraite du joueur 42 au tick 8 400 sans rejouer la simulation.

⚠️ Le revers : **renommer l'`id()` d'un système change tous ses tirages**, donc l'histoire
de tous les mondes existants. C'est un changement de version du noyau, pas un refactoring.

### La clé n'est pas toujours l'entité traitée

`YouthIntakeSystem` crée des joueurs. Au moment du tirage, ces joueurs n'ont pas encore
d'identifiant — la clé ne peut donc pas être le joueur produit. C'est le **club
producteur** : `$ctx->rng($clubId)`.

Ce qui tombe juste sur le plan du domaine (un centre de formation appartient à un club) et
permet en prime de moduler la promotion par les installations du club.

## 4. Le générateur : xoshiro128\*\*

> **Définition — PRNG.** Générateur *pseudo*-aléatoire : une fonction déterministe qui,
> depuis un état interne, produit une suite de nombres statistiquement indiscernables du
> hasard. Même état initial ⇒ même suite.

Le choix est `xoshiro128**` (Blackman & Vigna, domaine public) : quatre mots de 32 bits
d'état, très rapide, bonne qualité statistique, et **32 bits natifs** — ce dernier point
est décisif, voir §5.

```php
public function nextUint32(): int
{
    $result = Math32::mul32(self::rotl(Math32::mul32($this->s1, 5), 7), 9);

    $t = ($this->s1 << 9) & self::MASK;

    $this->s2 ^= $this->s0;
    $this->s3 ^= $this->s1;
    $this->s1 ^= $this->s2;
    $this->s0 ^= $this->s3;
    $this->s2 ^= $t;
    $this->s3 = self::rotl($this->s3, 11);

    return $result;
}
```

L'état initial est déroulé depuis la graine unique par **splitMix32** (quatre appels), qui
garantit un état bien distribué même à partir d'une graine dégénérée comme `0` ou `1`.
Une garde explicite existe pour l'état tout-à-zéro, **absorbant** pour xoshiro (il y
resterait à jamais) :

```php
if (($this->s0 | $this->s1 | $this->s2 | $this->s3) === 0) {
    $this->s0 = 1;
}
```

### Les usages du générateur dans le code

`Rng` n'expose qu'une seule primitive, `nextUint32()`. Tout le reste est construit
au-dessus, par les appelants, avec deux idiomes récurrents :

```php
// Un flottant dans [0, 1]
$rng->nextUint32() / 0xFFFFFFFF

// Un tirage de probabilité à 4 décimales
$roll = $rng->nextUint32() % 10_000;
return $roll < (int) ($chance * 10_000);
```

Le second est le motif de tous les événements probabilistes (retraite, progression,
taille de cohorte). La granularité est de 1/10 000, largement suffisante : une probabilité
quotidienne typique de retraite vaut ~0,0004.

## 5. ⚠️ Le piège PHP qui casse tout en silence

C'est le piège documenté comme n°1 du projet, et il mérite son encadré.

**PHP fait basculer silencieusement un dépassement d'`int` en `float`.** Pas d'exception,
pas d'avertissement, pas de troncature : le type change, la précision se perd au-delà de
2⁵³, et le résultat devient dépendant de la plateforme.

Un PRNG naïf en 64 bits *fonctionne* — il produit des nombres — mais ils ne sont plus
reproductibles. Le bug ne se manifeste jamais comme un bug.

**La parade** (`Core\Support\Math32`) : toute multiplication 32×32 passe par une
multiplication par blocs de 16 bits, qui ne dépasse jamais.

```php
public static function mul32(int $a, int $b): int
{
    $aLo = $a & 0xFFFF;  $aHi = ($a >> 16) & 0xFFFF;
    $bLo = $b & 0xFFFF;  $bHi = ($b >> 16) & 0xFFFF;

    $low = $aLo * $bLo;
    $mid = ($aLo * $bHi + $aHi * $bLo) & 0xFFFF;

    return ($low + ($mid << 16)) & self::MASK;
}
```

Pourquoi `($a * $b) & 0xFFFFFFFF` ne suffit pas : le produit intermédiaire de deux
opérandes proches de `0xFFFFFFFF` vaut ~1,8×10¹⁹, au-delà de `PHP_INT_MAX` (~9,2×10¹⁸).
**Il bascule en `float` avant que le masque ne s'applique.** Le masque arrive trop tard.

Le plus grand produit possible dans `mul32` est `0xFFFF × 0xFFFF ≈ 4,3×10⁹`, plus un
décalage de 16 bits : très loin de la limite.

`Core\Support\Hash` applique la même discipline pour dériver la graine d'un flux —
XOR-fold séquentiel des quatre valeurs, avec un finisseur MurmurHash3 (le même que dans
`splitMix32`) entre chaque étape.

## 6. Les flottants et la fonction `exp()`

Deux problèmes distincts, souvent confondus.

### L'addition flottante n'est pas associative

`(a + b) + c ≠ a + (b + c)` en IEEE 754. Sommer une colonne dans un ordre différent donne
un résultat différent au dernier bit — qui peut suffire à faire basculer une comparaison.

D'où ce commentaire dans `YouthIntakeSystem::averageQuality()` :

```php
// Somme dans l'ordre de $clubIds (déjà trié par EntityId croissant) :
// une somme de flottants n'est pas associative, l'ordre fait donc partie
// du déterminisme, pas seulement de la convention.
```

L'itération triée n'est donc pas *seulement* une bonne pratique : ici c'est une condition
de correction.

### La portabilité des fonctions transcendantes

`+`, `-`, `*`, `/` sont **exactes au bit près** en IEEE 754 : elles donnent le même
résultat sur toutes les plateformes. `exp`, `log`, `sqrt`, `cos` viennent de la libm et
peuvent différer d'un ulp (dernier bit) d'une version de libc à l'autre.

Le projet tranche ce point en deux endroits, dans deux sens opposés — et c'est cohérent :

| Endroit | Décision | Raison |
|---|---|---|
| `PoissonMatchEngine` | **`exp()` autorisé** | `docs/14-` §1 spécifie `λ = exp(...)` au pied de la lettre. Une seule machine exécute le noyau (pas de lockstep multijoueur), donc seule la reproductibilité *même machine, même PHP* est requise — et là, `exp()` est parfaitement déterministe. |
| `PlayerFactory` | **`exp()` évité** | La loi de talent demandée est une log-normale, qui exige `exp`/`log`/`sqrt`/`cos` (Box-Muller). Elle est remplacée par une `Beta(1, k)` purement arithmétique, qui en reproduit la forme qualitative. |

La différence n'est pas une incohérence : dans le premier cas on accepte un risque de
**portabilité cross-machine** qu'on n'a pas aujourd'hui ; dans le second on préserve
gratuitement une propriété qu'on pourrait vouloir plus tard (comparer des hashs de monde
entre une CI et un serveur de prod). Si ce besoin apparaît, le §6 de ce chapitre est la
première chose à relire.

## 7. Le tirage sans rejet : inverse de la CDF

Un motif qui revient et qui vaut d'être compris, parce qu'il est le contre-exemple d'une
technique répandue.

**Le problème.** Le moteur de match a besoin de tirer un score `(x, y)` selon une
distribution qui n'est pas une loi standard : c'est un produit de deux Poisson repondéré
par une correction. Elle n'est même pas normalisée.

**La solution qu'on n'a pas prise : le tirage par rejet.** On tire au hasard et on
recommence tant que le tirage n'est pas accepté. Simple, mais la boucle n'est pas bornée :
mauvais pour le déterminisme du budget de tirages, mauvais pour les tests, mauvais pour un
noyau qui doit tourner 1 000 saisons sans surveillance.

**La solution retenue : la grille + l'inverse de la fonction de répartition.**

```
   1. Calculer le poids de chaque score possible sur une grille finie [0,10]²
      (au-delà, la masse de probabilité est négligeable)

      poids[x][y] = P(x buts domicile) × P(y buts extérieur) × τ(x, y)

   2. Sommer le tout            →  total
   3. Un seul tirage uniforme   →  cible = U × total
   4. Parcourir la grille en cumulant, s'arrêter quand cumul ≥ cible

      poids :  0.08  0.14  0.11  0.05 ...
      cumul :  0.08  0.22  0.33  0.38 ...
                            ▲
                          cible = 0.29  →  on rend ce score
```

Borné (121 cases), **un seul appel au générateur par match**, et c'est une traduction
directe de la formule documentée plutôt qu'une astuce d'échantillonnage.

## 8. Comment on vérifie que c'est vrai

Le déterminisme ne se relit pas, il se teste. Deux niveaux :

**Vecteurs figés** (côté `kernel`) — `RngTest` et `HashTest` comparent les premières
sorties à des valeurs écrites en dur. Toute modification de l'arithmétique casse ces
tests immédiatement.

**Test de bout en bout** (côté `harness`) — `Tests\Determinism\DeterministicRunTest` fait
tourner deux simulations complètes avec la même graine et compare **deux hashs** :

1. le hash de l'état final du monde (`WorldHasher`, qui énumère explicitement tous les
   types de composants football connus) ;
2. le hash de **la séquence complète des événements émis**.

Le second n'est pas redondant. Deux mondes peuvent finir identiques en étant passés par
des chemins différents — et pour un moteur dont le journal d'événements sera la source de
vérité persistée, c'est le chemin qui compte autant que l'arrivée.

---

**Suite :** [06 — Le Ruleset](06-le-ruleset.md)
</content>
</invoke>
