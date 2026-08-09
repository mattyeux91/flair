<?php

declare(strict_types=1);

namespace Flair\Api\Read\View;

/**
 * Un Fait brut de l'event log, tel qu'il a ete journalise.
 *
 * C'est ce qui vit sous les blocs par saison, replie : la synthese repond a
 * « qu'est-ce qui s'est passe », le log repond a « montre-moi ». Les deux ont
 * leur place - une synthese qu'on ne peut pas verifier est une affirmation.
 *
 * `$type` est la **cle stable** du `TypeRegistry`, celle qu'on lit dans
 * `events.type` : elle ne se renomme jamais, donc une page mise en cache ou une
 * capture d'ecran reste lisible dans dix ans.
 *
 * `$role` dit a quel titre le club y figure (`ClubRole`), et `null` quand le
 * Fait est retenu sans nommer le club - il n'y en a pas aujourd'hui, mais un
 * Fait de competition pourrait un jour concerner tout le monde.
 */
final readonly class LoggedFactView
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public int $tick,
        public int $seq,
        public string $type,
        public ?string $role,
        public array $data,
    ) {
    }
}
