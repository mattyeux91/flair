<?php

declare(strict_types=1);

namespace Flair\Api\Read;

use Flair\Api\Read\View\StandingsRowView;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Standings;
use Flair\Kernel\Football\Components\StandingsEntry;

/**
 * Le classement, trie et rangue.
 *
 * `Football\Components\Standings` porte ses entrees **keyees par `clubId`** et
 * ne connait ni rang ni nom de club : le rang est un tri, pas une donnee, et
 * le stocker le rendrait faux des la journee suivante. Ce tri-la vit donc a la
 * lecture, ce qui est exactement l'usage que `Position` documente pour sa
 * valeur de secours - « le Ruleset et les projections ».
 *
 * Ordre : points, puis difference de buts, puis buts marques, puis `clubId`
 * croissant. Le dernier critere n'est pas cosmetique - il rend le classement
 * **total**, donc stable d'un affichage a l'autre, meme entre deux clubs
 * parfaitement a egalite.
 */
final readonly class StandingsReader
{
    /** @return list<StandingsRowView> */
    public function read(LoadedWorld $world): array
    {
        $state = $world->state;
        $entries = [];

        foreach ($state->components(Standings::class)->entities() as $competitionId) {
            $standings = $state->components(Standings::class)->get($competitionId);

            foreach ($standings->entries ?? [] as $entry) {
                $entries[] = $entry;
            }
        }

        // `$a->clubId` face a `$b->clubId` alors que tout le reste est inverse :
        // les trois premiers criteres descendent, l'identifiant monte.
        usort($entries, static fn (StandingsEntry $a, StandingsEntry $b): int =>
            [$b->points, $b->goalsFor - $b->goalsAgainst, $b->goalsFor, $a->clubId]
            <=> [$a->points, $a->goalsFor - $a->goalsAgainst, $a->goalsFor, $b->clubId]);

        $rows = [];
        foreach ($entries as $rank => $entry) {
            $rows[] = new StandingsRowView(
                rank: $rank + 1,
                clubId: $entry->clubId,
                clubName: $state->components(Club::class)->get($entry->clubId)->name ?? "Club {$entry->clubId}",
                played: $entry->played,
                won: $entry->won,
                drawn: $entry->drawn,
                lost: $entry->lost,
                goalsFor: $entry->goalsFor,
                goalsAgainst: $entry->goalsAgainst,
                points: $entry->points,
            );
        }

        return $rows;
    }
}
