<?php

declare(strict_types=1);

namespace Flair\Kernel\Football;

use Flair\Kernel\Core\Pipeline\Pipeline;
use Flair\Kernel\Core\Pipeline\System;
use Flair\Kernel\Football\Systems\CalendarSystem;
use Flair\Kernel\Football\Systems\CompetitionSystem;
use Flair\Kernel\Football\Systems\ContractSystem;
use Flair\Kernel\Football\Systems\FacilitiesSystem;
use Flair\Kernel\Football\Systems\FinanceSystem;
use Flair\Kernel\Football\Systems\MatchSystem;
use Flair\Kernel\Football\Systems\PlayerDevelopmentSystem;
use Flair\Kernel\Football\Systems\RetirementSystem;
use Flair\Kernel\Football\Systems\SquadSystem;
use Flair\Kernel\Football\Systems\TrainingSystem;
use Flair\Kernel\Football\Systems\YouthIntakeSystem;

/**
 * **Le** registre des systemes qui composent la simulation football, et le
 * seul endroit ou cette liste est ecrite (docs/13- §2 : la composition du
 * pipeline est une donnee d'architecture, versionnee avec le noyau).
 *
 * Elle etait auparavant recopiee dans quatre fichiers, dont deux se
 * declaraient chacun "seule source de verite" - et `bin/demo.php` avait
 * silencieusement diverge a neuf systemes sur onze, faisant tourner une
 * economie que la simulation reelle n'a pas.
 *
 * ## Pourquoi la liste n'est pas auto-decouverte
 *
 * Scanner `Football/Systems/` pour tout ce qui implemente `System` serait de
 * l'I/O, interdite dans le noyau (docs/11- §1). Mais la vraie raison est
 * ailleurs : un monde est epingle a `(kernelVersion, rulesetVersion)`.
 * Avec la decouverte automatique, deposer un fichier changerait le
 * comportement de **tous** les mondes existants sans qu'aucun diff ne le
 * montre, et la comparaison a graines appariees du harness perdrait sa
 * variable de controle. Ajouter un systeme doit rester un acte explicite,
 * visible dans un diff.
 *
 * ## Portee
 *
 * Cote `Football\` et non `Core\` : `Core\Pipeline\Pipeline` est generique
 * et ne doit rien savoir du football. Cote kernel et non harness : le graphe
 * du monorepo impose `harness -> kernel`, jamais l'inverse, et `bin/demo.php`,
 * le harness et le futur `host` doivent tous pouvoir le lire.
 *
 * Ce registre est un **defaut, pas un verrou** : les tests unitaires montent
 * legitimement des pipelines partiels (un ou deux systemes) pour isoler ce
 * qu'ils mesurent.
 *
 * ## Ordre
 *
 * L'ordre ci-dessous est significatif et contraint (`SquadSystem` doit ecrire
 * `SquadMembership` avant ses lecteurs ; `ContractSystem` doit lire les
 * competences et `Finances` apres leurs writers). Il reste ecrit a la main
 * pour l'instant ; le lot suivant le derivera d'un tri topologique des
 * declarations `reads()`/`writes()`/`removes()`, et `Football\PipelineInvariantsTest`
 * figera l'ordre calcule.
 */
final class FootballPipeline
{
    /**
     * @return list<System>
     *
     * Expose separement de build() parce que `Football\PipelineInvariantsTest`
     * a besoin de la liste elle-meme, pas d'un `Pipeline` monte : il verifiait
     * jusqu'ici l'ordre en fouillant `Pipeline::$systems` par reflexion.
     */
    public static function systems(): array
    {
        return [
            new FacilitiesSystem(),
            new YouthIntakeSystem(),
            new SquadSystem(),
            new TrainingSystem(),
            new RetirementSystem(),
            new FinanceSystem(),
            new PlayerDevelopmentSystem(),
            new CalendarSystem(),
            new MatchSystem(),
            new CompetitionSystem(),
            new ContractSystem(),
        ];
    }

    public static function build(): Pipeline
    {
        return new Pipeline(self::systems());
    }
}
