<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Events;

use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Snapshot\SnapshotArrayOf;

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
 * ## `finalRanking`, et pas la table complete
 *
 * Une `list<int>` de `clubId` du premier au dernier. Le seul consommateur a
 * ce jour ne pondere que par le rang ; publier points/buts en plus ferait de
 * cet evenement une copie de `Standings`, avec deux sources de verite pour la
 * meme donnee. Le tri (points, difference de buts, buts marques, puis
 * `clubId` croissant en dernier depart) appartient a `CompetitionSystem`, qui
 * possede le classement - pas a celui qui distribue l'argent.
 *
 * Vide si aucun match n'a encore ete joue (premiere saison d'un monde) : les
 * consommateurs doivent traiter ce cas plutot que le supposer impossible.
 */
final class SeasonConcluded implements DomainEvent
{
    /** @param list<int> $finalRanking clubId du premier au dernier du classement */
    public function __construct(
        public int $competitionId,
        #[SnapshotArrayOf('int')]
        public array $finalRanking,
    ) {
    }
}
