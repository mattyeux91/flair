<?php

declare(strict_types=1);

/**
 * Pas readonly : la negociation modifie annualSalary/commissionOffered,
 * "se renseigner" revele realPlayingTime en passant scouted a true.
 * $announcedPlayingTime peut mentir (voir NegotiationWindow) - c'est ce qui
 * rend "se renseigner" utile plutot que decoratif.
 */
final class ClubOffer
{
    public function __construct(
        public readonly string $clubName,
        public readonly string $profile,
        public int $annualSalary,
        public readonly float $announcedPlayingTime,
        public readonly float $realPlayingTime,
        public readonly float $prestige,
        public int $commissionOffered,
        public bool $scouted = false,
        public bool $negotiated = false,
    ) {
    }

    public function displayPlayingTime(): string
    {
        return $this->scouted
            ? sprintf('%d%% (reel confirme)', (int) round($this->realPlayingTime * 100))
            : sprintf('%d%% (annonce par le club)', (int) round($this->announcedPlayingTime * 100));
    }
}
