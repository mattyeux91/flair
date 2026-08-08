<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Snapshot\Fixtures;

/**
 * Types de test du codec de snapshot, volontairement hors du domaine
 * football : ce qu'on eprouve ici, ce sont les *formes* de valeur que le
 * contrat autorise. Un composant reel changerait de forme au gre du jeu et
 * ferait de ces tests une seconde description du domaine.
 */
enum FixtureColour: string
{
    case Red = 'red';
    case Blue = 'blue';
}
