<?php

declare(strict_types=1);

namespace Flair\Api\Read\View;

/**
 * L'histoire d'un club, en blocs par saison, de la plus recente a la plus
 * ancienne.
 *
 * `$factsRead` et `$factsKept` disent ce que la lecture a brasse : le premier
 * est le nombre de Faits du monde sur la fenetre, le second ceux qui nommaient
 * ce club. L'ecart est la mesure du filtre - et le chiffre a surveiller le jour
 * ou il faudra pousser les predicats en SQL plutot que de filtrer en PHP.
 */
final readonly class ClubHistoryView
{
    /** @param list<SeasonBlockView> $seasons */
    public function __construct(
        public string $worldId,
        public int $clubId,
        public string $clubName,
        public int $tick,
        public array $seasons,
        public int $factsRead,
        public int $factsKept,
    ) {
    }
}
