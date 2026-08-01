<?php

declare(strict_types=1);

namespace Flair\Harness\Metrics;

/**
 * Un releve : la valeur moyenne des attributs d'une categorie pour un joueur
 * a un age donne. On pool par categorie plutot que par attribut individuel
 * (pace vs stamina, etc.) parce que PlayerDevelopmentSystem fait aujourd'hui
 * vieillir tous les attributs d'une meme categorie de facon identique (meme
 * ageFactor, meme declineMultiplier) - ce sont statistiquement la meme
 * distribution.
 */
final readonly class SkillSample
{
    public function __construct(
        public int $playerId,
        public float $ageYears,
        public string $category,
        public float $value,
    ) {
    }
}
