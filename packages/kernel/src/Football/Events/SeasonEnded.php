<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Events;

use Flair\Kernel\Core\Messaging\DomainEvent;

/**
 * La derniere journee d'une saison a ete jouee : programme par
 * `Football\CalendarSystem` via le `Scheduler`, au lendemain du tick de la
 * derniere journee qu'il vient de planifier.
 *
 * ## Pourquoi ce signal existe
 *
 * Le monde n'avait aucune fin de saison. `CalendarSystem` n'emettait que
 * `SeasonStarted`, et le seul moment ou le noyau savait qu'une saison etait
 * terminee etait le demarrage de la suivante - `Football\CompetitionSystem`
 * y emettait donc `SeasonConcluded`, ce qui sacrait le champion **120 jours
 * apres son dernier match** au calibrage de reference (18 clubs : 34
 * journees, derniere journee au jour 245 d'une saison de 365). Sans
 * consequence tant que seul `Football\FinanceSystem` ecoutait, mais l'event
 * log est ce que la Phase 3 persiste et la Phase 4 rejoue : une date fausse
 * s'y serait gravee, et le digest de retour d'absence (docs/14- §9) aurait
 * annonce un champion quatre mois trop tard.
 *
 * ## Un marqueur, pas un porteur de donnees
 *
 * Ne porte que `competitionId`. Le classement final ne peut pas y figurer :
 * `CalendarSystem` ne lit pas `Standings` et ne le peut pas (son writer
 * `CompetitionSystem` est place plus loin dans le pipeline, ce que
 * `Football\PipelineInvariantsTest` interdit de lire). C'est
 * `CompetitionSystem`, qui possede le classement, qui traduit ce marqueur en
 * `SeasonConcluded` porteur du classement.
 *
 * ## Programme, pas emis
 *
 * Le `Scheduler` plutot que l'OutQueue (docs/13- §3) : l'evenement vise un
 * tick precis, connu a la generation du calendrier, des centaines de ticks
 * plus tard. Exactement le meme usage que `FixtureKickoff`.
 *
 * `Standings` n'est **pas** remis a zero ici : la table doit survivre entre
 * la derniere journee et le debut de la saison suivante, ou
 * `Harness\Metrics\Sampler` va la lire pour son historique. La remise a zero
 * reste sur `SeasonStarted`.
 */
final class SeasonEnded implements DomainEvent
{
    public function __construct(
        public int $competitionId,
    ) {
    }
}
