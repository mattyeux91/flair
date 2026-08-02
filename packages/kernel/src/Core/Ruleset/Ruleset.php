<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Ruleset;

/**
 * Bundle de regles parametriques, versionne (docs/12-modele-du-monde.md §6).
 * Un monde est epingle a (kernelVersion, rulesetVersion) - changer les regles
 * d'un monde vivant est une migration explicite, jamais un hot reload.
 *
 * Structure imbriquee par sous-domaine, pas une liste plate de scalaires -
 * ca correspond a la forme du JSON documente (12- §6 : `competitions`,
 * `transferWindows`, `contracts`, `finance`, `balance`) et ca reste lisible
 * quand le nombre de champs grandit. Chaque groupe est une classe dediee
 * dans ce meme namespace (`Balance` aujourd'hui), une propriete nommee sur
 * `Ruleset` avec une valeur par defaut - jamais un sac generique/associatif.
 *
 * Le schema JSON, sa validation et son versioning vivront dans le futur
 * package packages/ruleset/, qui produira les donnees alimentant ce value
 * object - sans jamais que le kernel en depende (kernel -> rien).
 *
 * Famille a part entiere (`Core/Ruleset/`) plutot qu'un fichier isole a la
 * racine de `Core/` : ni `Core/Pipeline` ni `Core/Simulation` ne doivent
 * dependre l'un de l'autre pour ce type, et les deux en ont besoin - meme
 * raisonnement qu'avant, la famille grandit juste au meme titre que
 * `Pipeline`/`Simulation`.
 */
final readonly class Ruleset
{
    public function __construct(
        public string $version,
        public Balance $balance = new Balance(),
    ) {
    }
}
