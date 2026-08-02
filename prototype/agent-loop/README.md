# Prototype — la boucle de jeu de l'agent

Prototype **jetable**, hors du monorepo Composer (`packages/`) : pas de `composer.json`, pas de namespace `Flair\`, aucune dépendance au `kernel`. Il ne simule rien du noyau — les clubs, salaires et temps de jeu sont des nombres inventés à la main. Son seul but : vérifier si la tension décrite dans `docs/14-algorithmes.md` §5 est un vrai dilemme de jeu avant d'écrire la moindre ligne du vrai client d'incarnation (`docs/15-roadmap.md`, Phase 5 — le point de rupture du projet si le métier d'agent n'est pas amusant).

## Ce qu'il incarne

```
U(agent) = commission + satisfaction_client + gain_de_reputation - cout_temps
```

Vous suivez **un seul client** sur **4 fenêtres de mercato** successives. À chaque fenêtre, 2 à 3 clubs font une offre (salaire, temps de jeu **annoncé**, prestige, commission). Vous disposez de **2 actions** par fenêtre — c'est le `coût_temps` de la formule, rendu concret : chaque négociation ou vérification consomme une action, donc vous devez choisir où creuser plutôt que tout vérifier partout.

- **`n<numéro>`** — négocier : tente d'améliorer salaire/commission (succès probable variable selon le club, les clubs riches sont rigides).
- **`s<numéro>`** — se renseigner : révèle le **temps de jeu réel** de ce club, qui peut être très inférieur à ce qui est annoncé (l'écart est toujours optimiste, jamais pessimiste — un club ne sous-vend pas son offre).
- **`[numéro]`** — accepter l'offre et terminer la fenêtre.
- **`p`** — passer la fenêtre sans placement (petite pénalité de satisfaction).

**Feedback inter-fenêtre** (le point qu'il fallait vérifier) : la satisfaction et la réputation d'une fenêtre influencent visiblement la suivante. Satisfaction basse → moins d'offres. Réputation haute → apparition plus fréquente d'un club « riche mais banc » — prestige et salaire élevés, mais temps de jeu réel bas. C'est la tentation concrète du plus-offrant contre le bon fit.

Si la satisfaction du client tombe sous 20 %, il change d'agent et la partie s'arrête.

## Comment jouer

```bash
php play.php
php play.php --seed=42   # rejouer une session identique
```

## Ce qu'il faut observer

Rejouez deux fois avec la **même graine** (`--seed=42`), en changeant de style :

1. **Toujours le plus offrant** — accepter systématiquement l'offre au salaire le plus haut, jamais de négociation ni de renseignement.
2. **Toujours le meilleur temps de jeu annoncé** — accepter systématiquement l'offre qui promet le plus de temps de jeu.

Comparez la commission totale, la réputation finale et le récit produit en fin de partie.

**Vérifié pendant le développement de ce prototype** (script de vérification automatisé, 3 graines différentes, logique identique à `play.php`) : les deux styles divergent nettement. « Plus offrant » rapporte davantage de commission mais est volatil — sur une graine, la satisfaction est tombée à 15 % (client presque perdu) à cause d'un club prestigieux qui n'a pas tenu sa promesse de temps de jeu. « Meilleur temps de jeu annoncé » rapporte moins mais maintient satisfaction et réputation hautes et stables sur les trois graines testées. **Le dilemme est réel** : le pari de la Phase 5 tient sur ce premier jet de poids/écarts.

Si en rejouant vous-même vous sentez que les deux styles convergent (scores similaires, aucune tension perçue), c'est le signal qu'il faut retravailler les poids de `applyOutcome()`/les plages de `NegotiationWindow::PROFILES` avant d'aller plus loin — ne pas coder le vrai client tant que ce n'est pas tranché.

## Statut

Prototype de validation, pas un livrable produit. À supprimer ou à requalifier en vrai document de design une fois la question tranchée.
