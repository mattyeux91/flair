<?php

declare(strict_types=1);

/**
 * Genere les offres d'une fenetre de mercato. C'est ici que se joue le
 * feedback inter-fenetre demande par le prototype (docs/14- §5, docs/15-
 * Phase 5) : satisfaction basse -> offres plus rares, reputation haute ->
 * apparition plus frequente d'une offre "piege" (prestige/salaire eleves,
 * mais temps de jeu reel bas) - la tentation concrete du plus-offrant contre
 * le bon fit.
 *
 * `honesty` pilote l'ecart entre `announcedPlayingTime` (ce que le club
 * annonce) et `realPlayingTime` (ce qui se passera vraiment) - toujours
 * optimiste, jamais pessimiste : un club ne sous-vend pas son offre. "Se
 * renseigner" (une action) revele le reel avant de signer.
 */
final class NegotiationWindow
{
    /**
     * @var array<string, array{salary: array{0: int, 1: int}, prestige: array{0: float, 1: float}, playingTime: array{0: float, 1: float}, commission: array{0: int, 1: int}, honesty: float, flexibility: float, clubNames: list<string>}>
     */
    private const array PROFILES = [
        'petit club, temps de jeu garanti' => [
            'salary' => [18000, 30000],
            'prestige' => [0.10, 0.30],
            'playingTime' => [0.75, 0.95],
            'commission' => [1500, 3000],
            'honesty' => 0.95,
            'flexibility' => 0.70,
            'clubNames' => ['FC Bregenz', 'Malmo IK', 'AS Vallee'],
        ],
        'ambitieux moyen' => [
            'salary' => [35000, 60000],
            'prestige' => [0.35, 0.55],
            'playingTime' => [0.50, 0.75],
            'commission' => [4000, 7000],
            'honesty' => 0.75,
            'flexibility' => 0.50,
            'clubNames' => ['Girondins Nova', 'Sporting Halden', 'CD Estrella'],
        ],
        'riche mais banc' => [
            'salary' => [90000, 160000],
            'prestige' => [0.70, 0.95],
            'playingTime' => [0.15, 0.35],
            'commission' => [12000, 22000],
            'honesty' => 0.35,
            'flexibility' => 0.25,
            'clubNames' => ['Real Corona', 'Atletico Prestige', 'Olympia United'],
        ],
    ];

    /** @return list<ClubOffer> */
    public static function generate(GameState $state): array
    {
        $profiles = ['ambitieux moyen'];

        if ($state->clientSatisfaction >= 0.35) {
            $profiles[] = 'petit club, temps de jeu garanti';
        }

        if (self::roll() < $state->agentReputation) {
            $profiles[] = 'riche mais banc';
        }

        $offers = [];
        foreach ($profiles as $index => $profileKey) {
            $offers[] = self::buildOffer($profileKey, $index);
        }

        return $offers;
    }

    public static function negotiate(ClubOffer $offer): bool
    {
        $flexibility = self::PROFILES[$offer->profile]['flexibility'];

        if (self::roll() >= $flexibility) {
            return false;
        }

        $offer->annualSalary = (int) round($offer->annualSalary * 1.15);
        $offer->commissionOffered = (int) round($offer->commissionOffered * 1.15);
        $offer->negotiated = true;

        return true;
    }

    private static function buildOffer(string $profileKey, int $index): ClubOffer
    {
        $spec = self::PROFILES[$profileKey];

        $realPlayingTime = self::randomFloat($spec['playingTime'][0], $spec['playingTime'][1]);

        // L'annonce n'est jamais pessimiste : honesty basse -> ecart plus grand, toujours vers le haut.
        $exaggeration = (1.0 - $spec['honesty']) * self::randomFloat(0.1, 0.5);
        $announcedPlayingTime = min(1.0, $realPlayingTime + $exaggeration);

        $clubNames = $spec['clubNames'];

        return new ClubOffer(
            clubName: $clubNames[$index % \count($clubNames)],
            profile: $profileKey,
            annualSalary: random_int($spec['salary'][0], $spec['salary'][1]),
            announcedPlayingTime: $announcedPlayingTime,
            realPlayingTime: $realPlayingTime,
            prestige: self::randomFloat($spec['prestige'][0], $spec['prestige'][1]),
            commissionOffered: random_int($spec['commission'][0], $spec['commission'][1]),
        );
    }

    private static function randomFloat(float $min, float $max): float
    {
        return $min + self::roll() * ($max - $min);
    }

    private static function roll(): float
    {
        return mt_rand() / mt_getrandmax();
    }
}
