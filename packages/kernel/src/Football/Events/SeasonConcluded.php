<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Events;

use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Snapshot\SnapshotArrayOf;
use Flair\Kernel\Football\Components\StandingsEntry;

/**
 * La saison d'une competition est terminee, et voici son classement final :
 * emis par `Football\CompetitionSystem` (canal 2) en reaction a
 * `Football\Events\SeasonEnded`, au lendemain de la derniere journee. Ce
 * detour par deux evenements a une raison : `CalendarSystem` sait *quand* une
 * saison finit (il a planifie toutes ses journees) mais ne connait pas le
 * classement ; `CompetitionSystem` connait le classement mais pas le
 * calendrier. Chacun emet donc ce qu'il sait.
 *
 * Un Fait au sens de docs/16-evenements-et-cascades.md §2, contrairement aux
 * mouvements comptables de routine que `Football\FinanceSystem` ne journalise
 * pas : irreversible (un classement final ne se rejoue pas) et racontable
 * (un champion est ce qu'un monde de football a de plus racontable).
 *
 * ## Pourquoi le classement passe par l'evenement plutot que par `Standings`
 *
 * `Football\FinanceSystem` a besoin du classement de la saison ecoulee pour
 * repartir les droits TV, mais il ne peut pas lire `Standings` : ce composant
 * est ecrit par `CompetitionSystem`, place **plus loin** dans le pipeline, et
 * `Football\PipelineInvariantsTest` interdit toute lecture d'un composant
 * ecrit plus loin (dependance inversee). Deplacer `FinanceSystem` apres
 * `CompetitionSystem` le ferait lire une table fraichement videe. Le
 * classement voyage donc dans le payload, et `FinanceSystem` n'a aucune
 * dependance a la forme de `Standings`.
 *
 * ## La table complete, et pourquoi ce Fait a change d'avis
 *
 * Il n'a longtemps porte qu'une `list<int>` de `clubId`, au motif que publier
 * les points ferait de lui « une copie de `Standings`, avec deux sources de
 * verite pour la meme donnee ». **Cet argument etait faux des qu'on a relu le
 * passe** : `Standings` est ecrase a la saison suivante, donc pour une saison
 * ecoulee il n'y a pas deux sources - il n'y en a aucune, juste un classement
 * sans chiffres. Un lecteur devait recalculer les points depuis les
 * `MatchPlayed` et les baremes, ce qui n'est correct **que par accident** :
 * cela suppose que les points ne viennent que de resultats de match, et le
 * premier retrait de points ou forfait ferait mentir une page sur le passe,
 * sans recours.
 *
 * `Standings` reste l'etat courant, ce Fait est le proces-verbal. Une copie
 * transitoire entre les deux n'est pas une seconde source de verite : c'est ce
 * que journaliser veut dire.
 *
 * Le tri (points, difference de buts, buts marques, puis `clubId` croissant en
 * dernier depart) appartient toujours a `CompetitionSystem`, qui possede le
 * classement - pas a celui qui distribue l'argent. Le **rang** se lit donc par
 * la position, et `ranking()` sert les consommateurs qui n'ont besoin que de
 * l'ordre.
 *
 * Vide si aucun match n'a encore ete joue (premiere saison d'un monde) : les
 * consommateurs doivent traiter ce cas plutot que le supposer impossible.
 */
final class SeasonConcluded implements DomainEvent
{
    /** @param list<StandingsEntry> $finalTable du premier au dernier du classement */
    public function __construct(
        public int $competitionId,
        #[SnapshotArrayOf(StandingsEntry::class)]
        public array $finalTable,
    ) {
    }

    /**
     * L'ordre seul, pour qui n'a besoin que du rang (`Football\FinanceSystem`
     * pondere les droits TV par la position, sans jamais lire un point).
     *
     * @return list<int> clubId du premier au dernier
     */
    public function ranking(): array
    {
        return array_map(
            static fn (StandingsEntry $entry): int => $entry->clubId,
            $this->finalTable,
        );
    }
}
