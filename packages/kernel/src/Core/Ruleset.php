<?php

declare(strict_types=1);

namespace Flair\Kernel\Core;

/**
 * Bundle de regles parametriques, versionne (docs/12-modele-du-monde.md §6).
 * Un monde est epingle a (kernelVersion, rulesetVersion) - changer les regles
 * d'un monde vivant est une migration explicite, jamais un hot reload.
 *
 * Volontairement minimal pour l'instant : seul `version` est decide. Le
 * schema JSON, sa validation et son versioning vivront dans le futur package
 * packages/ruleset/, qui produira les donnees alimentant ce value object -
 * sans jamais que le kernel en depende (kernel -> rien). Les champs
 * parametriques (competitions, fenetres de transfert, balance...) rejoindront
 * cette classe au fur et a mesure qu'un systeme du domaine football les lira
 * reellement, jamais par anticipation.
 *
 * A la racine de Core/ plutot que dans une famille dediee : ni Core/Pipeline
 * ni Core/Simulation ne doivent dependre l'un de l'autre pour ce type.
 */
final readonly class Ruleset
{
    public function __construct(public string $version)
    {
    }
}
