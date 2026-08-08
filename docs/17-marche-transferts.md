# Chantier — Marché des transferts et inflation (Phase 2, lot 3)

Ce document est le suivi du dernier lot de la Phase 2 (`15-roadmap.md` §4 Phase 2, point 3). Il ne remplace pas `14-algorithmes.md` §5/§6 (la conception) ni `15-roadmap.md` (les critères de sortie de phase) — il découpe le lot en points vérifiables, un par un.

## Méthode de travail

- **Un point à la fois, un commit par point**, une fois le point vérifié. Conventional Commits.
- **Droit de commit accordé par l'utilisateur pour ce chantier (2026-08-06).** Ne s'étend pas au-delà : les autres travaux restent soumis à la règle par défaut (c'est l'utilisateur qui commit).
- Mesure à graines appariées (`13-` §4.0) quand le point a un effet observable en simulation (points 2, 4, 5). Tests unitaires purs suffisants pour la fondation sans effet de bord (points 1, 3).
- Ce document et `15-roadmap.md` §Phase 2 sont mis à jour au fil de l'eau, avec la même honnêteté que les lots précédents : ce qui a marché, ce qui a été mesuré nuisible et retiré, les écarts au plan.
- Rien de ce chantier ne sort du périmètre Phase 2 (pas de gouvernance de club, pas de mécanique d'observation humaine — Phase 5).

Statut global : **5/5** — plus une correction après coup, en fin de document.

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

**Statut.** ☑ Fait le 2026-08-07.

> **Le design est presque entièrement dicté par deux invariants existants.** `Football\PipelineInvariantsTest` impose un seul writer par composant : `FinanceSystem` est le seul de `Finances`/`MonetaryMass`, `SquadSystem` le seul de `Contract`/`SquadMembership`. `TransferSystem` ne peut donc ni payer ni déplacer le joueur lui-même. Il décide, en queue de pipeline, et les deux applicateurs exécutent au tick suivant — le mur « décider tard, appliquer tôt » déjà rencontré par `ContractSigned` et `ClubInvestedInFacilities`.
>
> **Une indemnité n'est ni une injection ni un puits.** `MonetaryMass` ne bouge pas, la somme des `Finances` non plus : l'acheteur est débité, le vendeur crédité du même montant, atomiquement (si l'un des deux clubs a disparu entre la conclusion et son application, rien ne bouge — débiter sans créditer détruirait de la monnaie). C'est ce qui rend `MonetaryConservationTest` non trivial pour la première fois, et un garde-fou y a été ajouté sur le modèle de celui du cas `meritShare = 0.6` : le test exige désormais qu'il ait **réellement** circulé des indemnités, sinon il resterait vert sans rien prouver.
>
> **Écart tranché : pas de Fait `TransferCompleted`**, que ce document prévoyait. `TransferAgreed` porte déjà l'indemnité et les deux clubs, et l'accord émet en plus un `ContractSigned` (`previousClubId` = le vendeur) qui porte le mouvement du joueur — `SquadSystem` savait déjà l'appliquer, et `Harness\Metrics\Sampler` comptait déjà les changements de club par ce biais. Un troisième Fait ne franchirait aucun seuil que ces deux-là ne franchissent pas (`16-` §2).
>
> **Le joueur signe un nouveau contrat**, il n'hérite pas de l'ancien : salaire au prix du marché tel que l'**acheteur** le perçoit, durée tirée comme à un renouvellement (`WageModel::contractDurationYears()`, extraite de `ContractSystem::expiresOn()` — deux consommateurs réels, jamais un seul). Conséquence voulue : `signedOn` repart à zéro, donc l'`observationYears` du nouveau club aussi. Un club vient d'acheter quelqu'un qu'il n'a jamais eu sous les yeux, et il le jugera comme tel l'année suivante.
>
> **Solvabilité : une politique de PNJ, pas une règle.** `NpcBuyerIntentSource` borne son plafond par son solde et s'abstient si son offre d'ouverture le dépasse déjà. Le système n'interdit rien — une source humaine garde le droit de se ruiner, frontière que le point 3 venait d'établir.
>
> **Mesures (500 joueurs, 18 clubs, 40 saisons, graines 42 et 7).**
>
> | | point 3 (graine 42) | point 4, graine 42 | point 4, graine 7 |
> |---|---|---|---|
> | Négociations ouvertes | 715 | 590 | 585 |
> | Accords | 169 | 111 | 101 |
> | Médiane des tours | 2 | 2 | 3 |
> | Part au premier tour | 44,9 % | **39,7 %** | **37,3 %** |
>
> **La réserve du point 2 s'est améliorée toute seule** : la part de négociations résolues au premier tour baisse de 5 points, effet secondaire de la solvabilité (un club pauvre ne conclut plus instantanément l'affaire facile qu'il ne pouvait pas payer). Le critère d'échec du point 2 (médiane à 1) reste très loin.
>
> **Deux constats honnêtes, et ce sont eux qui préparent le point 5.**
>
> 1. **L'indemnité médiane vaut ~4 500 € (453 600 centimes), soit 9 % de `baseValueCents`.** Cause identifiée, et ce n'est pas un bug : le PNJ maximise `qualité perçue / prix estimé`, donc il chasse systématiquement les joueurs **en fin de contrat**, que `MarketValueModel` brade jusqu'à `contractFloorMultiplier = 0.05`. Le marché fonctionne, mais il achète surtout les soldes.
> 2. **Le marché ne concentre rien.** Aucun solde de club négatif, Gini des soldes de fin de run **0,011** (graine 42) et **0,008** (graine 7) — les indemnités totales (74,8 M centimes sur 40 saisons) sont marginales devant la masse en circulation (623 M). Au calibrage par défaut (`meritShare = 0`, revenus égaux), le marché des transferts est **réel mais économiquement inerte**. La boucle « riche s'enrichit » de `14-` §7 n'est pas rouverte par ce point ; elle ne pourra l'être qu'une fois les prix montés à une échelle qui compte, ce qui est exactement le sujet du point 5.
>
> Coût CPU : 74,1 s → 80,2 s sur 40 saisons (+8 %).

---

## Point 5 — `indice_inflation_global` et régulateur

**Objectif.** Fermer la boucle avec `marketInflationTarget` du `Ruleset` (`14-` §6) — critère de sortie officiel de la Phase 2, seconde moitié.

**Contenu.** Mesure de l'inflation réalisée (à préciser précisément au démarrage du point, sur la base de `14-` §6 : évolution du prix moyen des transferts et/ou de la masse monétaire), régulateur ajustant les injections marginales pour tenir la cible, branchement de `indice_inflation_global` dans `MarketValueModel` (point 1, jusqu'ici figé à `1.0`).

**Vérification.** Sur graines appariées, 20 saisons : inflation mesurée dans la cible du `Ruleset`. C'est le critère de sortie de la Phase 2 dans son ensemble (avec le point 4 pour la moitié « conservation »).

**Statut.** ☑ Fait le 2026-08-07 — **et c'est le point qui a le plus dévié de sa conception**, sur mesure à chaque fois.

> ### Ce que les mesures ont interdit
>
> **1. L'inflation ne peut pas se mesurer sur les prix de transfert.** Le monde conclut **~3 transferts par saison** (mesure du point 4). Un indice de prix annuel calculé sur trois transactions est du bruit pur. Ce n'est pas une préférence de conception, c'est une impasse arithmétique.
>
> **2. Elle ne peut pas non plus se mesurer sur la masse monétaire.** La masse est **négative les neuf premières saisons** — une année entière de salaires est versée avant que la première enveloppe n'arrive — donc elle **traverse zéro**, et tout indice bâti dessus explose au passage. Deux implémentations s'y sont cassées : référence capturée à la saison 1 (référence = 1 centime, indice → 0,01, 293 joueurs au chômage, monde mort), puis au passage par zéro (indice → 5,1 d'un coup).
>
> **3. Et surtout : ce monde n'a aucune inflation endogène.** Mesuré sans aucun régulateur, 40 saisons, graine 42 :
>
> | année | masse | masse salariale | sans club |
> |---|---|---|---|
> | 10 | 621 M | 838 M | 58 |
> | 25 | 617 M | 862 M | 23 |
> | 40 | 623 M | 831 M | 45 |
>
> Plat. Trente saisons durant. Et c'est **structurel** : salaires et valeurs sont des formules du `Ruleset` (`base × qualité / référence`), pas des prix d'équilibre. Aucune quantité de monnaie ne peut les déplacer. Le régulateur de `14-` §6 suppose un mécanisme de prix endogène que ce monde n'a pas.
>
> ### La conception retenue
>
> **L'indice est une décision de politique monétaire**, pas une mesure : il avance de `marketInflationTarget` à chaque saison achevée. `14-` §6 l'assume mot pour mot — « C'est artificiel, mais assumé : un monde persistant est une économie administrée, pas une économie libre. » Conséquence directe et à dire franchement : **le taux réalisé égale la cible par construction**, donc le vérifier ne prouve rien.
>
> Il multiplie **tout ce qui est nominal**, pas seulement les indemnités : salaires (`WageModel`), valeurs (`MarketValueModel`), enveloppe des droits TV, entretien et investissement des installations, échelle de détresse financière. C'est ce qui en fait un « changement d'unité monétaire [qui] s'applique uniformément à tout le marché » (`14-` §5) plutôt qu'une distorsion des prix relatifs.
>
> **`FacilitiesSystem` est le cas dur**, et il n'a qu'une solution : il **ne peut pas** lire l'indice, parce qu'il écrit `Facilities` que `FinanceSystem` lit — l'arête inverse ferait un **cycle** que `SystemGraph` lèverait au montage. D'où un second champ sur `ClubInvestedInFacilities` : `cents` (ce qui a quitté la caisse, nominal, ce que le grand livre draine et ce qu'un journal enregistre) et `referenceCents` (le même montant à l'unité de référence, seul utilisé pour la conversion en qualité). Un Fait journalisé ne doit pas mentir sur l'argent dépensé, et un club ne doit pas bâtir plus vite parce que la monnaie a changé d'unité.
>
> ### Le correcteur proportionnel : construit, mesuré instable deux fois, retiré
>
> Un asservissement des injections sur la solvabilité (masse / masse salariale annuelle) a été écrit et mesuré :
>
> | | comportement | chômage final |
> |---|---|---|
> | gain 0,3, sans anticipation | oscillation lente, `trim` entre **1,07 et 1,52** | **0** |
> | gain 0,15, avec anticipation | effondrement sur le plancher, `trim` à **0,25** | **200** |
>
> La cause n'est pas un réglage : la grandeur asservie a un **dénominateur endogène qui bouge dans le mauvais sens**. Moins d'emploi → masse salariale plus petite → solvabilité en hausse → le régulateur coupe encore les revenus. Contre-réaction positive, qu'aucun gain ne rattrape. Retiré, comme le `minSquadSize` du lot des contrats avant lui.
>
> Ce qui reste est en **boucle ouverte, donc stable par construction** : l'indice avance de la cible, et l'enveloppe gagne un terme d'anticipation `cible × masse` — la croissance que le stock de monnaie doit prendre pour suivre l'unité, connue analytiquement plutôt que cherchée par asservissement. `solvency` survit comme **observable**, jamais comme entrée de commande.
>
> ### Mesures
>
> **À la cible par défaut (`0.0`), neutralité stricte** : masse 623 385 000, masse salariale 831 220 000, 45 sans club, 327 actifs sur 40 saisons — **identiques au centime** à la baseline du point 4. Le mécanisme existe, il est testé, et il ne déplace rien tant qu'on ne l'active pas. Même discipline que `meritShare = 0.0` en son temps, et c'est ce qui garde valides toutes les mesures déjà enregistrées.
>
> **À 3 %/an**, le monde reste **stable** — indice ×3,167 sur 40 saisons, solvabilité plate de l'année 15 à 40, masse salariale sur revenus à 0,64 contre 0,66 — mais il **décroche sur l'emploi** : le coussin de trésorerie se stabilise 43 % au-dessus de son niveau naturel, la garde de solvabilité des clubs ne mord plus, et le chômage tombe de ~35 à ~2. Effet mesuré, chiffré, **non corrigé** : d'où le défaut à zéro plutôt qu'un défaut à 3 % qu'il faudrait excuser.
>
> ### Bug trouvé au passage, hors périmètre mais réel
>
> `(int) round(PHP_INT_MAX * 1.0)` rend un entier **négatif** : un `Ruleset` mettant une réserve d'investissement à `PHP_INT_MAX` pour la rendre inatteignable obtenait l'exact inverse — le club investissait tout. Même famille que le piège du PRNG 32 bits (`11-` §6). Le `min`/`max` naïf ne suffit pas non plus, `(float) PHP_INT_MAX` valant déjà 2⁶³ : `FinanceSystem::scaled()` clampe par comparaisons explicites.
>
> ### Le critère de sortie de la Phase 2, redéfini
>
> « Inflation dans la cible » n'était défini **nulle part** — ni la grandeur, ni la fenêtre, ni la tolérance, ni le nombre de graines — et, tel qu'il était formulé, il n'a pas de contenu ici : l'indice étant une décision, le taux réalisé égale la cible toujours. `Harness\Tests\Regression\InflationRegressionTest` mécanise à la place les deux choses qui peuvent réellement casser :
>
> 1. **Neutralité stricte au défaut** — le monde par défaut est inchangé.
> 2. **Stationnarité en termes réels à 3 %** — la solvabilité, grandeur sans dimension donc insensible au changement d'unité, ne s'emballe pas ; les salaires suivent l'unité au lieu de rester nominaux.
>
> Voir `15-roadmap.md` §4 pour l'énoncé réécrit.

---

## Après le chantier — le marché n'échangeait que deux postes sur quatre (2026-08-08)

Le chantier était clos à 5/5 quand la mesure de la dette D5 (`18-dettes.md`) a sorti un chiffre que personne ne cherchait.

### Ce qui a été mesuré

Six graines × 20 ans, transferts payants (`TransferAgreed`) ventilés par poste dérivé :

| Poste | Part des transferts | Part de la population sous contrat |
|---|---|---|
| GK | 26,9 % (53) | 10,6 % |
| DEF | 64,5 % (127) | 33,5 % |
| MID | 8,1 % (16) | 35,7 % |
| ATT | **0,5 % (1)** | 20,3 % |

**Un attaquant transféré sur 197, sur 120 saisons cumulées** — et en réalité **zéro**, la re-mesure sous instrumentation correcte le montrera plus bas.

### La cause : un ordre de déclaration devenu une constante

Trois faits qui, pris séparément, ne disent rien :

1. `NpcBuyerIntentSource::neededPosition()` renvoyait le **premier** poste sous-effectif dans l'ordre de déclaration de `Position` — gardien d'abord. C'était documenté et assumé (« le poste le plus rare est aussi celui dont l'absence coûte le plus cher »).
2. `SquadComposition::targets()` somme à **22** pour un `targetSquadSize` de **20**, l'arrondi par poste étant délibéré.
3. L'effectif réel d'un club tourne autour de **16,5**.

Composés, ils font qu'un club est en déficit **à chaque poste, en permanence**. « Le premier poste en déficit » cesse alors d'être une priorité pour devenir *une constante* : GK si le club en a moins de deux, DEF sinon — et jamais rien d'autre. Comme `TransferSystem::openNegotiations()` n'ouvre **qu'une négociation par club et par an**, cette constante était tout le marché du club.

Le raisonnement prédit GK ~27 % et DEF ~64 % : ce sont exactement les chiffres mesurés. C'est ce qui a permis de conclure sans chercher plus loin.

> **La leçon dépasse ce cas.** Un ordre total sur une énumération est obligatoire (`12-` §2) et parfaitement légitime comme **départage**. Il devient un défaut silencieux dès qu'il sert de **critère** sur une population où le prédicat est vrai partout. Le symptôme n'est visible que si quelqu'un affiche la distribution — et rien ne l'affichait.

### La correction

Le classement passe de « premier poste en déficit » à « tous les postes en déficit, pondérés par l'ampleur relative du manque » :

```
score = (qualité perçue / prix estimé) × (1 + needWeightSpan × déficit/cible)
```

Forme de `14-` §3 : une base qui porte le phénomène, **un seul** modificateur, borné par construction dans `[1, 1 + span]` puisque `déficit/cible ∈ (0, 1]`. Rapporter le déficit à la cible **du poste** est ce qui reste informatif quand tout est déficitaire : il est plus grave de perdre un gardien sur deux qu'un défenseur sur huit.

Les deux facteurs tirent volontairement en sens opposés — `rareté_poste` rend un poste rare **plus cher** (donc son ratio moins bon), le poids d'urgence contrebalance.

C'est aussi le motif déjà tenu par la décision sœur, `ContractSystem::pick()`, qui filtre ses candidats sur « ce poste est en déficit » sans jamais imposer d'ordre entre postes. Le marché adopte le comportement du mercato des sans-club, il n'en invente pas un.

**Un seul champ ajouté au `Ruleset`** : `TransferBalance::$needWeightSpan = 1.0`. Un *span* plutôt qu'un couple `min`/`max` comme ses voisins, parce que les bornes seraient redondantes ici et qu'un span rend les deux régimes atteignables au `--set` — `0.0` étant « le club ignore l'urgence et ne chasse que la bonne affaire ».

### Résultat, à graines appariées dans un même build

Six graines × 20 ans, mesurées par `Metrics\Sampler` de part et d'autre du seul changement de `NpcBuyerIntentSource` (l'ancien fichier remis en place le temps de la passe, empreinte vérifiée dans les deux sens) :

| Poste | Avant | Après | Population |
|---|---|---|---|
| GK | 28,4 % (74) | 18,1 % (50) | 10,4 % |
| DEF | 62,5 % (163) | **37,0 %** (102) | 33,6 % |
| MID | 9,2 % (24) | **23,6 %** (65) | 36,5 % |
| ATT | **0,0 % (0)** | **21,4 %** (59) | 19,4 % |
| total | 261 | 276 | — |

**Zéro attaquant sur 261 transferts**, sur les six graines sans exception — la première mesure, faite avec une instrumentation moins fine, en avait trouvé un seul sur 197. Ce n'était donc pas une sous-représentation, c'était une **absence**.

Volume total quasi inchangé (261 → 276), ce qui est attendu : le plafond structurel d'une négociation par club et par an n'a pas bougé. La distribution suit désormais la population, avec deux écarts qui ont un sens — les gardiens restent sur-représentés (leur manque est le déficit le plus sévère), les milieux sous-représentés (ce sont les plus abondants, donc ceux dont on manque le moins).

**Club-années sans gardien : 2,41 % → 2,50 %** (52 → 54 sur 2 160), et la disette maximale reste **1 an** sur les six graines. C'était le risque principal du lot, et la prédiction écrite avant l'implémentation (« ça monte, mais reste sous 4 % ») tient largement.

**Équilibre compétitif : aucun effet détectable**, comme aux lots des postes et de la perception.

| | Gini des titres | Rotation du top 5 | Champions distincts |
|---|---|---|---|
| test du signe (après vs avant) | 4 hausses / 2 baisses | 4 hausses / 2 baisses | 2 hausses / 4 baisses |

> ⚠️ **Ne pas sur-lire ce 4/2.** La prédiction écrite avant l'implémentation annonçait 3/3 ; le résultat n'est pas ce partage exact, mais il n'est pas non plus un signal : sous une pièce équilibrée, obtenir au moins 4 résultats sur 6 dans un sens arrive une fois sur trois. Et le fait que Gini **et** rotation montent ensemble, alors qu'ils vont d'ordinaire en sens inverse, est un indice de plus qu'on lit du bruit. Ce qu'on peut affirmer : rien dans cette campagne ne permet de conclure à un effet sur l'équilibre.

### Ce que le paramètre change, et ce qu'il ne change pas

Campagne appariée par le `Ruleset` seul, `needWeightSpan=0` contre le défaut `1.0`, six graines :

| | défaut (1.0) | `span=0` |
|---|---|---|
| GK | 18,1 % (50) | **12,5 %** (35) |
| DEF | 37,0 % (102) | 41,8 % (117) |
| MID | 23,6 % (65) | 26,1 % (73) |
| ATT | 21,4 % (59) | 19,6 % (55) |
| club-années sans gardien | 2,50 % | 2,18 % |

Le paramètre a donc une **autorité réelle et lisible sur qui est acheté** — 43 % de gardiens achetés en plus quand l'urgence compte — mais c'est bien la **mise en commun des postes** qui a corrigé la distorsion, pas la pondération. Le span est un réglage fin ; à `0` la distribution reste saine.

> À noter, et non expliqué : `span=0` achète moins de gardiens **et** laisse légèrement moins de club-années sans gardien (47 contre 54 sur 2 160). L'écart est au niveau du bruit, mais il rappelle que « acheter des gardiens » et « avoir un gardien » ne sont pas la même grandeur — le vivier des sans-club et le centre de formation en fournissent aussi.

### Ce que le lot ne corrige pas

Le déficit permanent lui-même — cibles à 22, effectif souhaité à 20, effectif réel à 16,5. Inscrit en **D6** dans `18-dettes.md` avec son déclencheur. Il n'est pas forcément à corriger (un monde aux effectifs maigres est un monde qui a un marché), mais tant qu'il tient, **toute décision fondée sur un booléen « il me manque quelqu'un ici » est une décision fondée sur `true`**.

### La mesure est devenue permanente

C'est la moitié du lot qui n'est pas dans le noyau. `Metrics\Sampler` compte les `TransferAgreed` par poste et les club-années sans gardien, `Report\TextReport` les affiche, donc toute campagne les remet à jour. Avant ça, la ventilation n'existait nulle part et les gardiens n'existaient que comme plafond muet dans un test.

> **Piège de méthode rencontré en instrumentant.** Le script jetable qui a servi à la première mesure dérivait le poste du joueur **en fin d'année** et perdait ceux partis à la retraite entre-temps : 38 transferts comptés contre 51 réels sur une graine. Les comparaisons avant/après restent valides (même méthode des deux côtés), mais c'est précisément l'argument pour sortir une mesure d'un script : `Sampler` dérive le poste **au tick du Fait**.
