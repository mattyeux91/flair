<?php

declare(strict_types=1);

namespace Flair\Host\Tests;

use Flair\Host\AdvanceWorld;
use Flair\Host\CreateWorld;
use Flair\Host\Store\Row;
use Flair\Host\WorldLock;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Core\Simulation\Simulation;
use Flair\Kernel\Core\Simulation\TickContext;
use Flair\Kernel\Core\Snapshot\SnapshotCodec;
use Flair\Kernel\Football\FootballPipeline;
use Flair\Kernel\Football\FootballTypes;
use Flair\Worldgen\WorldFactory;
use Flair\Worldgen\WorldSpec;

/**
 * Persister ne doit **rien** changer au monde.
 *
 * Le meme monde avance de N ticks par le Host, avec un aller-retour complet en
 * base a chaque tick, doit etre identique a celui d'un processus qui n'a
 * jamais rien ecrit. Sans cette garantie, tout ce qui a ete mesure jusqu'ici
 * dans le harness cesse de valoir pour un monde de production, et on
 * n'aurait aucun moyen de s'en apercevoir.
 *
 * La comparaison porte sur l'**etat serialise complet**, pas sur un hash :
 * `Harness\Support\WorldHasher` vit dans un package que `host` n'a pas le
 * droit d'importer (docs/11- §7), et de toute facon un diff de JSON dit *ou*
 * ca diverge la ou un hash dit seulement *que* ca diverge.
 *
 * ## Pourquoi le run doit depasser une fin de saison
 *
 * Ce test a tourne sur **120 ticks** jusqu'au 2026-08-08, et c'etait un trou.
 * `Core\Ruleset\CalendarBalance::$seasonStartDayOfYear` vaut 0 et un monde
 * nait au tick 0 (`Host\CreateWorld`) : la premiere saison n'est generee
 * qu'au **tick 365**, la premiere journee au 379, la cloture au 415. En 120
 * ticks, l'aller-retour en base n'avait donc jamais traverse un match, une
 * fin de saison, un renouvellement de contrat ni une negociation de
 * transfert - tout ce que la Phase 2 a construit etait hors du seul test qui
 * garantit que persister ne change rien au monde.
 *
 * ## Et pourquoi la couverture est **verifiee**, pas supposee
 *
 * Le nombre de ticks ci-dessous est derive de valeurs du `Ruleset` qui
 * peuvent changer : deplacer `seasonStartDayOfYear` ou `renewalDayOfYear`
 * ramenerait ce test a une parite sur un monde ou il ne se passe rien, sans
 * qu'aucune assertion ne rougisse. `self::MUST_COVER` l'interdit - c'est
 * l'idiome deja employe par `Harness\Tests\Regression\MonetaryConservationTest`
 * (qui exige que des indemnites aient reellement circule) et par
 * `Harness\Tests\Regression\SnapshotContinuityTest` (qui echoue si l'une des
 * trois structures n'a jamais ete couverte).
 */
final class PersistedWorldMatchesMemoryTest extends DatabaseTestCase
{
    /**
     * Mesure, pas estimation (4 clubs / 60 joueurs, graine 42) - premiere
     * occurrence de chaque Fait :
     *
     *   120  PlayerRetired
     *   180  ContractSigned, YouthPlayerPromoted   (mercato + intake, jour 180)
     *   365  SeasonStarted
     *   379  MatchPlayed         ... jusqu'au 414, six journees
     *   415  SeasonConcluded     (le revenu de saison suit au 416)
     *   565  TransferNegotiationOpened
     *   566  TransferAgreed, TransferCounterDemanded
     *   568  TransferNegotiationBroken   ... jusqu'au 569
     *
     * D'ou 575 : le marche n'ouvre qu'au **jour 200 de l'annee 2**, jamais de
     * l'annee 1 - au genesis aucun club n'est en manque, il faut une saison de
     * retraites et de fins de contrat pour que des besoins apparaissent. C'est
     * ce qui rend ce test le premier a faire traverser la base a une
     * `Negotiation` **en cours de vol** : le seul etat multi-tick du noyau.
     */
    private const int TICKS = 575;

    private const int SEED = 42;

    /**
     * Les Faits que ce run doit avoir produits, en **cles stables** du
     * `Core\Snapshot\TypeRegistry` - celles-la memes qu'ecrit `EventStore`
     * dans `events.type`, et qui par contrat ne se renomment jamais
     * (`Football\FootballTypes`).
     *
     * Quatorze types sont enregistres, dix sont exiges ici. Les quatre
     * absents ne sont pas un oubli : `season_ended` et `fixture_kickoff`
     * passent par le Scheduler (`SystemContext::schedule()`) et ne sont donc
     * **jamais** journalises - seuls les Faits emis le sont ;
     * `club_invested_in_facilities` demande un seuil d'investissement qu'un
     * monde de quatre clubs n'atteint pas ; et `contract_expired` manque non
     * par manque de temps mais parce qu'a quinze joueurs par club **aucun
     * club ne laisse filer un contrat** - il renouvelle, faute de mieux. Le
     * meme monde a vingt-cinq joueurs par club en produit dix-neuf.
     */
    private const array MUST_COVER = [
        'football.event.player_retired',
        'football.event.contract_signed',
        'football.event.youth_player_promoted',
        'football.event.season_started',
        'football.event.match_played',
        'football.event.season_concluded',
        'football.event.transfer_negotiation_opened',
        'football.event.transfer_counter_demanded',
        'football.event.transfer_agreed',
        'football.event.transfer_negotiation_broken',
    ];

    public function testAWorldAdvancedThroughTheDatabaseIsIdenticalToOneAdvancedInMemory(): void
    {
        $worldId = $this->newWorldId('parity');

        // Quatre clubs pour que la saison tienne en six journees, et quinze
        // joueurs par club - un chiffre a ne pas monter a la legere. Dix par
        // club (l'ancien 40/4) ne compose pas un onze ; vingt-cinq par club
        // sature les effectifs, plus aucun club n'est en manque et **le
        // marche des transferts ne s'ouvre jamais** (mesure : 4/100, 4/140,
        // 6/150 et 8/200 n'ouvrent aucune negociation en 600 ticks). Quinze
        // est le point ou les deux tiennent.
        $spec = new WorldSpec(playerCount: 60, seed: self::SEED, clubCount: 4);
        (new CreateWorld($this->database, $this->worlds, $this->snapshots))($worldId, $spec);

        $advance = new AdvanceWorld(
            $this->database,
            $this->worlds,
            $this->events,
            $this->snapshots,
            new WorldLock($this->database),
        );

        for ($i = 0; $i < self::TICKS; $i++) {
            $advance($worldId);
        }

        $persisted = $this->snapshots->latest($worldId);
        self::assertNotNull($persisted);
        self::assertSame(self::TICKS, $persisted->tick);

        self::assertSame(
            $this->inMemoryState($spec),
            $persisted->state,
            'Le monde persiste a divergé du monde en memoire.',
        );

        $covered = $this->eventTypesWritten($worldId);
        foreach (self::MUST_COVER as $type) {
            self::assertContains($type, $covered, sprintf(
                'Aucun Fait "%s" en %d ticks : la parite ne couvre plus ce qu\'elle croit couvrir. '
                . 'Un jour-de-l\'annee du Ruleset a bouge - relire le docblock plutot que gonfler TICKS au hasard. '
                . 'Types rencontres : %s.',
                $type,
                self::TICKS,
                implode(', ', $covered),
            ));
        }
    }

    /**
     * Les types de Faits distincts journalises pour ce monde.
     *
     * Requete directe plutot que `EventStore::tail()` : ce dernier est une
     * lecture de debug bornee par une limite, et lui demander « tout » pour
     * en deduire un ensemble serait detourner son intention.
     *
     * @return list<string>
     */
    private function eventTypesWritten(string $worldId): array
    {
        $rows = $this->database->connection()->table('events')
            ->where('world_id', $worldId)
            ->distinct()
            ->orderBy('type')
            ->get(['type']);

        $types = [];
        foreach ($rows as $row) {
            $types[] = Row::string($row, 'type');
        }

        return $types;
    }

    /** @return array<string, mixed> */
    private function inMemoryState(WorldSpec $spec): array
    {
        $world = new WorldState();
        (new WorldFactory())->populate($world, $spec, atTick: 1);

        $simulation = new Simulation(FootballPipeline::build());
        $ruleset = new Ruleset('default');

        for ($tick = 1; $tick <= self::TICKS; $tick++) {
            $simulation->step($world, new TickContext(
                tick: $tick,
                seed: self::SEED,
                intents: [],
                ruleset: $ruleset,
            ));
        }

        return (new SnapshotCodec(FootballTypes::registry()))->encode($world);
    }
}
