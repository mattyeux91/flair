<?php

declare(strict_types=1);

namespace Flair\Host\Rules;

use RuntimeException;

/**
 * Un monde est epingle a une version de regles que ce Host ne sait pas
 * reconstruire (docs/12- §6).
 *
 * Une exception plutot qu'un repli sur les defauts, pour la meme raison que
 * `Store\UnexpectedColumn` et `Core\Snapshot\SnapshotFormatException` : un
 * monde qu'on ne sait pas faire tourner **selon ses propres regles** est un
 * monde faux, et le faire tourner quand meme sans bruit est le seul
 * comportement inacceptable. C'est exactement le mode de panne que
 * `Host\AdvanceWorld` documentait comme dette avant ce garde - il tournait
 * avec les defauts du kernel quelle que soit la version epinglee.
 */
final class UnsupportedRulesetVersion extends RuntimeException
{
    public function __construct(string $version, string $supported)
    {
        parent::__construct(
            "Ruleset \"{$version}\" inconnu de ce Host, qui ne sait reconstruire que \"{$supported}\". "
            . 'Faire tourner ce monde demande le package `ruleset`, pas un repli sur les defauts.',
        );
    }
}
