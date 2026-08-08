<?php

declare(strict_types=1);

namespace Flair\Kernel\Football;

use Flair\Kernel\Core\Pipeline\Pipeline;
use Flair\Kernel\Core\Pipeline\System;
use Flair\Kernel\Core\Pipeline\SystemGraph;
use Flair\Kernel\Football\Intents\NpcBuyerIntentSource;
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
use Flair\Kernel\Football\Systems\TransferSystem;
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
 * L'ordre d'execution n'est plus ecrit, il est **derive** : `SystemGraph`
 * trie `declaration()` selon les dependances de composants declarees. Un
 * ordre qui violerait une dependance est donc corrige, pas seulement
 * detecte, et un cycle leve au montage.
 *
 * La liste ci-dessous garde neanmoins son sens, parce que le tri est stable :
 * la ou aucune dependance ne tranche, un systeme reste ou il a ete mis.
 * Ajouter un systeme revient donc a le deposer n'importe ou - les
 * dependances le placent, le reste ne bouge pas.
 */
final class FootballPipeline
{
    /**
     * L'ordre d'execution reel : la declaration, triee selon ses dependances.
     * C'est ce que tout le monde consomme.
     *
     * Expose separement de build() parce que `Football\PipelineInvariantsTest`
     * a besoin de la liste elle-meme, pas d'un `Pipeline` monte : il verifiait
     * jusqu'ici l'ordre en fouillant `Pipeline::$systems` par reflexion.
     *
     * @return list<System>
     */
    public static function systems(): array
    {
        return SystemGraph::sort(self::declaration());
    }

    /**
     * La liste telle qu'ecrite a la main, avant tri. Publique dans le seul
     * but de permettre a `Football\PipelineInvariantsTest` de prouver qu'elle
     * s'accorde deja avec l'ordre derive : si une edition future la casse, le
     * runtime continue de fonctionner (le tri corrige) mais le test proteste.
     *
     * @return list<System>
     */
    public static function declaration(): array
    {
        return [
            new FacilitiesSystem(),
            new SquadSystem(),
            new TrainingSystem(),
            new RetirementSystem(),
            new FinanceSystem(),
            new PlayerDevelopmentSystem(),
            new YouthIntakeSystem(),
            new CalendarSystem(),
            new MatchSystem(),
            new CompetitionSystem(),
            new ContractSystem(),
            new TransferSystem(new NpcBuyerIntentSource()),
        ];
    }

    public static function build(): Pipeline
    {
        return new Pipeline(self::systems());
    }
}
