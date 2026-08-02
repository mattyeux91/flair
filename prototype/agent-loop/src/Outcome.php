<?php

declare(strict_types=1);

final readonly class Outcome
{
    public function __construct(
        public bool $gotPromisedPlayingTime,
        public bool $performedWell,
    ) {
    }
}
