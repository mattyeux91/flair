# Chantier — Marché des transferts et inflation (Phase 2, lot 3)

Ce document est le suivi du dernier lot de la Phase 2 (`15-roadmap.md` §4 Phase 2, point 3). Il ne remplace pas `14-algorithmes.md` §5/§6 (la conception) ni `15-roadmap.md` (les critères de sortie de phase) — il découpe le lot en points vérifiables, un par un.

## Méthode de travail

- **Un point à la fois, un commit par point**, une fois le point vérifié. Conventional Commits.
- **Droit de commit accordé par l'utilisateur pour ce chantier (2026-08-06).** Ne s'étend pas au-delà : les autres travaux restent soumis à la règle par défaut (c'est l'utilisateur qui commit).
- Mesure à graines appariées (`13-` §4.0) quand le point a un effet observable en simulation (points 2, 4, 5). Tests unitaires purs suffisants pour la fondation sans effet de bord (points 1, 3).
- Ce document et `15-roadmap.md` §Phase 2 sont mis à jour au fil de l'eau, avec la même honnêteté que les lots précédents : ce qui a marché, ce qui a été mesuré nuisible et retiré, les écarts au plan.
- Rien de ce chantier ne sort du périmètre Phase 2 (pas de gouvernance de club, pas de mécanique d'observation humaine — Phase 5).

Statut global : **3/5**

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

**Statut.** ☑ Fait le 2026-08-06.

> **Écart tranché au moment du code : contradiction entre le bloc formule et la prose de `14-` §5.** Le bloc met `facteur_contrat` *dans* le `clamp(·, 0.4, 2.5)` ; la prose dit l'inverse (« la seule exception admise à la borne basse [...] appliquée après le clamp »). Un joueur à six mois du terme doit pouvoir tomber sous 0.4× — la prose l'emporte, `facteur_contrat` multiplie après le clamp, sans plancher partagé avec `rareté_poste`/`richesse_acheteur`.
>
> **Essai rejeté sur le pic d'âge.** `PlayerPotentials` porte trois pics (physique/technique/mental). Première idée : pondérer par la catégorie dominante du poste (`PositionModel::weights()`). Vérifié à la main sur les quatre postes et abandonné — la table de poids range `defending`/`passing`/`finishing`/`positioning`/`technique` sous « technique », qui domine sur les **quatre** postes sans exception. La pondération dégénère en « toujours `technicalPeakAge` ». Retenu à la place : la moyenne simple des trois pics, cohérente avec le fait qu'aucun système ne fait varier ces plages par poste aujourd'hui.
>
> `rareté_poste`, `richesse_acheteur` et `indice_inflation_global` sont reçus en paramètres déjà résolus, neutres à `1.0` : rien ne les calcule encore (points 2, 4, 5). `MarketValueBalance` rejoint `Balance` et est reconduit explicitement (non surchargeable pour l'instant) dans `RulesetOverride::withFields()`, avec un test de non-régression dédié — même précaution que celle qui manquait à `PositionBalance`.

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

**Statut.** ☑ Fait le 2026-08-06.

> **Mesuré sur la population synthétique standard** (500 joueurs, 18 clubs, 40 saisons, graine 42, script ad hoc reproduisant la boucle de `Harness\Metrics\Sampler::run()`) : **715 négociations ouvertes, médiane à 2 tours, moyenne 2,94, de 1 à 7 tours** (169 accords, 546 ruptures — cohérent avec des coefficients de premier jet, non calibrés pour maximiser les accords). Le critère d'échec du point (médiane à 1 tour) n'est **pas** atteint.
>
> **Réserve honnête, à noter pour une future calibration.** 315 négociations sur 715 (44 %) se résolvent quand même au premier tour — l'offre initiale (`openingOfferShare` × valorisation de l'acheteur) couvre parfois déjà la réserve du vendeur, surtout quand celle-ci est remisée par la détresse financière ou un surplus d'effectif au poste (`financialDistressWeight`/`squadDepthDiscountPerSurplusPlayer`) et que le bruit de perception (`baseErrorPoints = 10` par défaut) fait diverger les deux valorisations. Pas un échec du critère du point (la médiane est bien à 2, pas à 1), mais un signal que les coefficients de `TransferBalance` — tous des premiers jets, comme documenté sur chaque champ — mériteront un vrai passage de calibration avant que la distribution des tours serve de donnée de jeu.
>
> **Extraction préalable faite en même temps : `Football\Support\SquadComposition`.** `ContractSystem::squadByPosition()`/`positionTargets()` (privées) sont devenues des méthodes statiques partagées, `TransferSystem` en étant le second consommateur réel — refactor mécanique, vérifié par la suite de tests existante de `ContractSystem` restant verte sans modification.
>
> **Limites assumées, documentées dans le code (`Football\TransferSystem`) :** pas de réputation (aucun composant n'existe, la richesse relative du club en tient lieu) ; pas d'agence indépendante du joueur/agent (repliée dans le prix de réserve du vendeur) ; pas d'enchère concurrente (premier club à cibler un joueur le verrouille pour l'année) ; pas de fenêtre à bornes (un seul jour d'ouverture fixe, `maxRounds` garantit à lui seul la clôture) ; aucun argent réel — `TransferAgreed` est émis, le grand livre se branche au point 4.
>
> **Écart tranché en cours de route : `rareté_poste`/`richesse_acheteur` sont câblés pour de vrai dans ce point**, alors que le point 1 les recevait neutres à 1.0 — c'était nécessaire dès l'analyse de besoin (étape 1 de `14-` §5), pas différable à un point ultérieur comme le prévoyait la première version de ce document.
>
> **Réouvert le 2026-08-06 : patience individuelle du club vendeur.** `Football\Components\BoardPatience` (échelle absolue 1-100, semé au genesis par `Harness\Population\ClubFactory::disperseBoardPatience()`, dispersé autour de `boardPatienceMean`/`boardPatienceSpread` comme `Scout::$judgement`) multiplie la probabilité de rupture d'un tour par `clamp(patienceReference / niveau, patienceFactorMin, patienceFactorMax)` — un club deux fois plus patient que la référence (niveau 100) voit sa probabilité de rupture divisée par deux. Un club sans ce composant est lu comme neutre (`patienceReference`, facteur 1.0), donc **strictement sans effet sur le comportement d'avant cette réouverture** — vérifié par la suite de tests existante restant verte à l'identique. Vérifié statistiquement (200 essais indépendants par niveau, même méthode que `ContractSystemTest::mispricing()`) : un vendeur de patience 10 rompt significativement plus souvent qu'un vendeur de patience 100, toutes choses égales par ailleurs.
>
> **Piège évité, documenté dans `ClubFactory`** : les tirages de dispersion ne pouvaient pas rester dans `ClubFactory::create()` (appelée *avant* la boucle des joueurs dans `PopulationFactory::populate()`) sans décaler le flux RNG partagé de toute la population de joueurs. Une méthode séparée (`disperseBoardPatience()`), appelée après les joueurs — au même endroit et pour la même raison que `StaffFactory::create()` — évite ce décalage.
>
> **Remesuré sur la même population (500/18/40 ans, graine 42)** : 715 négociations, médiane toujours à 2 tours, moyenne 2,81 (contre 2,94 avant ce point), **44,9 % au premier tour (contre 44,0 %)** — essentiellement inchangé. Attendu, pas un échec de la mesure : la dispersion est symétrique autour de la même référence (50) que la probabilité plate d'avant, donc les vendeurs plus patients et plus impatients se compensent en moyenne sur la population. Ce que ce point ajoute est de la **variance individuelle** (vérifiée statistiquement ci-dessus), pas un déplacement de la médiane globale — les deux mesures ne répondaient pas à la même question.

---

## Point 3 — `IntentSource` côté club acheteur

**Objectif.** Brancher l'étape « offre / contre-offre » de l'acheteur sur l'interface `IntentSource` (`11-` §3, `16-` §1), pour que l'agent PNJ du point 2 et le futur agent humain (Phase 5) soient deux implémentations de la même interface — pas une réécriture le jour où un humain joue.

**Contenu.** Le comportement PNJ du point 2 devient une implémentation d'`IntentSource` parmi d'autres possibles, pas un cas spécial câblé en dur dans `TransferSystem`.

**Vérification.** Test que l'injection d'intents manuels (simulant un humain) via l'API `IntentSource`, à la place de l'implémentation PNJ, produit le même effet observable côté système — aucune divergence de comportement selon la source de l'intent.

**Statut.** ☑ Fait le 2026-08-07.

> **Deux hypothèses de ce document corrigées par le code.**
>
> 1. **`IntentSource` n'existait pas.** Ni l'interface, ni le `WorldView` de son esquisse (`11-` §3). Seuls `Core\Messaging\Intent` et `Core\Messaging\DecisionRequest` existaient : deux marqueurs vides, zéro implémentation en production. Ce point ne « branche » donc rien sur de l'existant, il crée le point d'articulation de zéro.
> 2. **Le contenu réel du point n'est pas l'interface, c'est le découpage en ticks.** `advance()` faisait le tour complet en un tick : le vendeur contre-proposait *et* l'acheteur répondait, trois lignes plus bas. Un humain ne peut pas répondre à une contre-offre qu'il n'a pas vue — les Faits d'un tick ne sont visibles qu'au suivant (`13-` §2). Sans ce découpage, une source « humaine » est une fiction et le test de substituabilité serait creux : il n'exercerait qu'une valeur pré-calculée qui coïncide avec celle du PNJ.
>
> **Livré.** `Football\Intents\` : `BidForPlayer` et `RaiseTransferOffer` (les deux premières intentions concrètes du monde), l'interface `BuyerIntentSource`, `TransferMarketView` (la vue partagée), `NpcBuyerIntentSource` (la logique du point 2, déplacée telle quelle) et `SubmittedBuyerIntentSource` (**premier consommateur réel de `TickContext::$intents`**, qui cesse d'être de la plomberie morte). `Negotiation` porte `pendingCounterCents`/`pendingSinceTick`, `TransferBalance` porte `responseGraceTicks`.
>
> **Écart tranché : interface de domaine, pas `Core\IntentSource`.** L'esquisse de `11-` §3 est générique et suppose un `WorldView` dont le contenu (projection ? filtre par acteur ?) ne se décide pas avec un seul consommateur pour en juger — c'est la règle « deux consommateurs réels, jamais un seul, jamais par anticipation ». La propriété que le doc réclame vraiment (PNJ et humain indiscernables du noyau, LSP, `11-` §8) est tenue en entier par `BuyerIntentSource` ; la généralisation attendra le second domaine qui en aura besoin.
>
> **Ce qui a changé de nature : de la règle à la politique.** Trois comportements ne sont plus imposés par le noyau mais choisis par `NpcBuyerIntentSource`, et une autre source a le droit d'en décider autrement : n'acheter qu'au premier poste sous-effectif, viser le meilleur rapport qualité perçue / prix, et renoncer dès que la contre-demande dépasse le plafond. Ce dernier point sort littéralement de `TransferSystem`. En regard, le système gagne une **validation** des intentions reçues (`11-` §3 : « mises en file, validées, puis consommées ») : un acheteur déjà engagé, un joueur déjà ciblé, un joueur déjà au club acheteur, un joueur sans compétences ou sans potentiel sont rejetés. Un PNJ respecte ces règles par construction, une intention soumise de l'extérieur non.
>
> **Le délai de grâce (`responseGraceTicks`, défaut `0`).** Un PNJ répond toujours dans le tick où il voit la contre-demande — il calcule, il n'attend pas. Un humain, lui, lit le Fait à la fin du tick N et ne peut répondre qu'au N+1 ; « je n'ai rien envoyé » veut alors dire « je réfléchis », pas « je me retire ». Mais l'attente doit être bornée : `maxRounds` compte les tours, pas les ticks, et un tour n'avance que quand l'acheteur répond. C'est la version minimale de l'`expiresAtTick` que `16-` §1 attache aux `DecisionRequest` — l'échéance sans le canal. À `0`, strictement sans effet sur un monde 100 % PNJ.
>
> **Mesuré, et c'est le point important : rien n'a bougé.** Même population que le point 2 (500 joueurs, 18 clubs, 40 saisons, graine 42) : **715 négociations, médiane 2 tours, moyenne 2,81, 44,9 % au premier tour, 74,1 s** — identique au chiffre près à la mesure du point 2 réouvert. Attendu et vérifié à la main avant l'implémentation : le découpage déplace *où dans le tick* le nombre de l'acheteur est décidé (fin du tick N → début du tick N+1), pas le nombre de ticks ni de tours, ni les ticks où le tirage de rupture a lieu. Un écart aurait signalé un bug, pas une nouveauté.
>
> **Seule divergence de comportement assumée** : une rupture sur plafond arrive un tick plus tard qu'avant (l'acheteur prend un tick pour décliner), avec le même `round` dans le Fait. Trop rare pour déplacer la distribution ci-dessus.
>
> **Non fait, délibérément.** Pas de composite « humain d'abord, PNJ en repli » : `11-` §3 en fait une propriété, mais rien ne peuple `TickContext::$intents` en production aujourd'hui — c'est du câblage `host`, Phase 5. Pas de `DecisionRequest` : il n'a aucun canal (`SystemContext` ne sait émettre qu'un `DomainEvent`), lui en créer un est une addition d'architecture à part entière. Pas de `NpcBuyerIntentSourceTest` séparé : les treize tests de `TransferSystemTest` *sont* l'assertion de ce comportement, un test unitaire en doublon aurait été écrit par anticipation.

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
