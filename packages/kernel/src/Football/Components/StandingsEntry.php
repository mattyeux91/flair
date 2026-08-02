<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Components;

/**
 * La ligne d'un club dans un classement (`Standings`). Valeur pure, jamais
 * stockee seule dans un `ComponentStore` - toujours a l'interieur de
 * `Standings::$entries`.
 */
final readonly class StandingsEntry
{
    public function __construct(
        public int $clubId,
        public int $played = 0,
        public int $won = 0,
        public int $drawn = 0,
        public int $lost = 0,
        public int $goalsFor = 0,
        public int $goalsAgainst = 0,
        public int $points = 0,
    ) {
    }
}
