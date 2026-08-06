# Chantier — Marché des transferts et inflation (Phase 2, lot 3)

Ce document est le suivi du dernier lot de la Phase 2 (`15-roadmap.md` §4 Phase 2, point 3). Il ne remplace pas `14-algorithmes.md` §5/§6 (la conception) ni `15-roadmap.md` (les critères de sortie de phase) — il découpe le lot en points vérifiables, un par un.

## Méthode de travail

- **Un point à la fois, un commit par point**, une fois le point vérifié. Conventional Commits.
- **Droit de commit accordé par l'utilisateur pour ce chantier (2026-08-06).** Ne s'étend pas au-delà : les autres travaux restent soumis à la règle par défaut (c'est l'utilisateur qui commit).
- Mesure à graines appariées (`13-` §4.0) quand le point a un effet observable en simulation (points 2, 4, 5). Tests unitaires purs suffisants pour la fondation sans effet de bord (points 1, 3).
- Ce document et `15-roadmap.md` §Phase 2 sont mis à jour au fil de l'eau, avec la même honnêteté que les lots précédents : ce qui a marché, ce qui a été mesuré nuisible et retiré, les écarts au plan.
- Rien de ce chantier ne sort du périmètre Phase 2 (pas de gouvernance de club, pas de mécanique d'observation humaine — Phase 5).

Statut global : **0/5**

---

## Point 1 — Valorisation (`MarketValueModel`)

**Objectif.** La fonction pure de prix, fondation des points suivants. Aucun effet de bord, aucun branchement au moteur tant que le point 2 n'existe pas.

**Contenu** (`14-` §5, forme bornée de `14-` §3) :

```
base   = f(qualité perçue) × courbe_âge(âge, pic)
modif  = clamp( facteur_contrat × rareté_poste × richesse_acheteur, 0.4, 2.5 )
valeur = base × modif × indice_inflation_global
```

- `facteur_contrat` : seule exception à la borne basse, appliqué **après** le clamp, courbe explicite (joueur à 6 mois de fin de contrat).
- `indice_inflation_global` : hors clamp, figé à `1.0` jusqu'au point 5 (pas encore piloté).
- Qualité perçue : réutilise le scouting existant (`Football\Support\PerceptionModel`), pas la vérité cachée.

**Fichiers** : `packages/kernel/src/Football/Support/MarketValueModel.php` + tests unitaires purs.

**Vérification.** Tests unitaires (bornes, monotonie qualité/âge, `facteur_contrat` hors clamp). Pas de graines appariées nécessaires : fonction pure sans consommateur en simulation à ce stade.

**Statut.** ☐

---

## Point 2 — Négociation multi-tours (agents PNJ uniquement)

**Objectif.** Vérifier que la négociation **ne converge pas instantanément** — c'est le risque ludique explicitement identifié par `14-` §5 (« économiquement correct et ludiquement mort »). Aucun humain, aucun argent réel encore : le but est d'observer le comportement du mécanisme.

**Contenu** (séquence `14-` §5) :

1. Analyse de besoin par poste (gap analysis club vs budget/board).
2. Sélection de cibles (`valeur perçue / prix estimé`, sous contrainte budget/masse salariale).
3. Offre au club vendeur.
4. Évaluation vendeur (prix de réserve : contrat restant, profondeur au poste, besoin financier, pression du board, réputation acheteur).
5. Évaluation joueur + agent PNJ (salaire, temps de jeu attendu, réputation, ambition, commission de l'agent).
6. Contre-offre ou rupture, **avec mémoire des tours précédents**.

**Risque architectural principal du lot.** Premier système du noyau avec état persistant **multi-tick** — jusqu'ici chaque système traite un tick en un passage. Modélisé comme une entité « négociation » à composants propres (offres, historique, parties), avancée d'un tour par tick par `TransferSystem`. Ne nécessite pas de nouveau mécanisme de scheduler : l'OutQueue existant (`13-` §2) suffit, un Fait par étape.

**Vérification.** Sur graines appariées : distribution du nombre de tours par négociation (aboutie ou rompue). Si la médiane est à 1 tour, le point a échoué à son objectif et la conception (probabilités d'acceptation, écart d'évaluation vendeur/acheteur) doit être revue avant de continuer. Pas de comparaison d'équilibre compétitif nécessaire ici — pas encore d'argent réel qui change de mains.

**Statut.** ☐

---

## Point 3 — `IntentSource` côté club acheteur

**Objectif.** Brancher l'étape « offre / contre-offre » de l'acheteur sur l'interface `IntentSource` (`11-` §3, `16-` §1), pour que l'agent PNJ du point 2 et le futur agent humain (Phase 5) soient deux implémentations de la même interface — pas une réécriture le jour où un humain joue.

**Contenu.** Le comportement PNJ du point 2 devient une implémentation d'`IntentSource` parmi d'autres possibles, pas un cas spécial câblé en dur dans `TransferSystem`.

**Vérification.** Test que l'injection d'intents manuels (simulant un humain) via l'API `IntentSource`, à la place de l'implémentation PNJ, produit le même effet observable côté système — aucune divergence de comportement selon la source de l'intent.

**Statut.** ☐

---

## Point 4 — Grand livre : indemnités et conservation monétaire

**Objectif.** L'argent change réellement de mains à la conclusion d'une négociation. Premier effet réel de ce chantier sur `MonetaryConservationTest` (`15-roadmap.md` §4 le note déjà : jusqu'ici ce test reste vert *trivialement*, faute de tout transfert d'argent entre clubs).

**Contenu.** Écriture `Finances` des deux clubs à la conclusion, Fait `TransferCompleted`, extension de `Harness\Tests\Regression\MonetaryConservationTest` pour couvrir le chemin transfert (`14-` §6 : les transferts entre clubs conservent la monnaie, ni source ni puits).

**Vérification.** `MonetaryConservationTest` vert sur 20 saisons avec négociations actives et indemnités réellement versées.

**Statut.** ☐

---

## Point 5 — `indice_inflation_global` et régulateur

**Objectif.** Fermer la boucle avec `marketInflationTarget` du `Ruleset` (`14-` §6) — critère de sortie officiel de la Phase 2, seconde moitié.

**Contenu.** Mesure de l'inflation réalisée (à préciser précisément au démarrage du point, sur la base de `14-` §6 : évolution du prix moyen des transferts et/ou de la masse monétaire), régulateur ajustant les injections marginales pour tenir la cible, branchement de `indice_inflation_global` dans `MarketValueModel` (point 1, jusqu'ici figé à `1.0`).

**Vérification.** Sur graines appariées, 20 saisons : inflation mesurée dans la cible du `Ruleset`. C'est le critère de sortie de la Phase 2 dans son ensemble (avec le point 4 pour la moitié « conservation »).

**Statut.** ☐
