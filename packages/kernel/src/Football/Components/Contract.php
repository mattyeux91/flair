<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Components;

use Flair\Kernel\Core\Support\SimDate;

/**
 * L'engagement salarial d'un joueur envers un club : vit sur l'entite
 * **joueur**, pointe vers l'entite club (`$clubId`) - meme forme que
 * `SquadMembership`, avec lequel il doit rester coherent (meme `clubId`,
 * invariant mecanise par `Harness\Tests\Regression\SquadIntegrityTest`,
 * docs/12-modele-du-monde.md §1).
 *
 * `expiresOn` est ce qui fait exister un marche : sans terme, aucun joueur
 * n'a jamais de raison de changer de club et le monde est fige. Une date
 * absolue plutot qu'une duree restante - un composant `readonly` ne se
 * decremente pas a chaque tick, et `Football\ContractSystem` n'a besoin que
 * de la comparer au tick courant une fois par an. C'est aussi la forme dont
 * le `facteur_contrat` de la valorisation aura besoin (docs/14- §5 : un
 * joueur a six mois du terme s'effondre), derivable sans rien stocker de plus.
 *
 * `signedOn` porte l'anciennete du joueur au club, et par la
 * l'`observationCount` de la perception (docs/12- §4) : le staff d'un club
 * observe en continu l'effectif de ce club, donc « depuis combien de temps ce
 * joueur est ici » est exactement « combien de fois il a ete observe ». C'est ce
 * qui evite d'inventer une structure par paire (observateur, sujet), qui serait
 * en O(observateurs x joueurs) pour une propriete qu'un seul champ suffit a
 * porter. Un joueur d'un autre club reste a zero observation, sans rien stocker.
 *
 * Volontairement **sans valeur par defaut** : un `signedOn` oublie par un futur
 * writer vaudrait zero, donc une anciennete maximale, donc une connaissance
 * parfaite - le contraire silencieux de l'effet cherche. Il est aussi le
 * dernier parametre, pour qu'aucun appel positionnel existant ne puisse
 * l'echanger avec `expiresOn` sans erreur de type (les deux sont des `SimDate`).
 *
 * Pas de `releaseClause`/`agentId` (docs/12- §6 les prevoit) : toujours
 * aucune negociation ni agent pour les consommer, meme precedent de catalogue
 * reduit que `Club`. Ils rejoindront ce composant avec le marche des
 * transferts, pas avant (docs/15-roadmap.md §4).
 *
 * Cree par `Football\YouthIntakeSystem` (joueur promu) et
 * `Worldgen\WorldFactory` (joueur du genesis), ecrit et retire
 * par `Football\SquadSystem` - seul proprietaire de la relation d'emploi.
 */
final readonly class Contract
{
    public function __construct(
        public int $clubId,
        public int $wagePerWeekCents,
        public SimDate $expiresOn,
        public SimDate $signedOn,
    ) {
    }
}
