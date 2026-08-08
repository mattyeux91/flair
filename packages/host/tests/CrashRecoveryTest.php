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
 * **Le critere de sortie de la Phase 3** (docs/15- §4), pris au mot : « tuer
 * le processus au hasard, le relancer, et le monde reprend sans
 * incoherence ».
 *
 * Pas de simulation de panne, pas de transaction qu'on ferait echouer a la
 * main : un vrai sous-processus `bin/host.php advance`, un vrai **SIGKILL**
 * en plein vol. SIGKILL et non SIGTERM, deliberement - un processus ne peut
 * pas l'intercepter, donc aucun code de nettoyage ne tourne. C'est le pire
 * cas, et c'est le seul qui prouve quelque chose : si la coherence dependait
 * d'un `finally`, elle ne survivrait pas a une coupure de courant.
 *
 * Deux proprietes distinctes sont verifiees, et les deux sont necessaires :
 *
 * 1. **La base n'est jamais a moitie avancee.** Le tick de `worlds`, celui du
 *    dernier snapshot et le dernier tick journalise sont d'accord. Un tick
 *    s'est produit en entier ou pas du tout.
 * 2. **La suite du monde est celle qu'elle aurait ete.** On reprend jusqu'a
 *    un tick cible et l'etat obtenu est identique a celui d'un monde qui n'a
 *    jamais ete interrompu. Une base coherente mais un monde qui a saute un
 *    tick serait une reprise « sans incoherence » au sens le plus creux.
 *
 * ## Limite a connaitre : ce test detecte, il ne prouve pas
 *
 * Un SIGKILL ne tombe dans la fenetre vulnerable qu'au hasard, et la taille de
 * cette fenetre depend de la gravite du defaut. Mesure en sabotant
 * volontairement `AdvanceWorld`, trois cycles de mise a mort par execution :
 *
 * | Defaut introduit | Detection |
 * |---|---|
 * | Aucune transaction du tout | **4 / 4** |
 * | Faits et snapshot valides en deux transactions successives | **2 / 6** |
 *
 * Plus le defaut est grossier, plus le filet l'attrape - c'est la bonne
 * propriete, mais un test probabiliste reste un test probabiliste, et
 * celui-ci ne prouve rien a lui seul. Ce qui **garantit** la coherence est la
 * structure d'`AdvanceWorld` : une seule transaction. Ce test est le filet
 * pour le jour ou quelqu'un l'en fera sortir sans y penser.
 *
 * Sur le code correct : **0 echec sur 10 executions**.
 *
 * D'ou aussi les trois cycles plutot qu'un : ils multiplient les chances de
 * tomber dans la fenetre, et verifient au passage que la reprise tient
 * plusieurs fois de suite et pas seulement une.
 */
final class CrashRecoveryTest extends DatabaseTestCase
{
    private const int SEED = 42;
    /**
     * Le delai avant la mise a mort doit couvrir le demarrage du
     * sous-processus - autoload, connexion, lecture du premier snapshot - plus
     * quelques ticks, sans quoi on tue un processus qui n'a encore rien fait
     * et l'assertion « il a avance » echoue pour une raison qui n'a rien a
     * voir avec la coherence. Observe une fois a 0,25 s sur machine chargee.
     */
    private const float KILL_AFTER_SECONDS = 0.4;
    private const int CRASHES = 3;
    private const int TICKS_AFTER_RECOVERY = 50;

    public function testKillingTheProcessMidFlightLeavesAConsistentWorldThatResumesIdentically(): void
    {
        $worldId = $this->newWorldId('crash');
        $spec = new WorldSpec(playerCount: 40, seed: self::SEED, clubCount: 4);

        (new CreateWorld($this->database, $this->worlds, $this->snapshots))($worldId, $spec);

        $crashedAt = 0;

        for ($crash = 1; $crash <= self::CRASHES; $crash++) {
            $this->killMidAdvance($worldId);

            // --- 1. La base est coherente, apres chaque mise a mort ----------
            $previous = $crashedAt;
            $crashedAt = $this->worlds->find($worldId)->tick ?? 0;

            self::assertGreaterThan(
                $previous,
                $crashedAt,
                "Le processus n°{$crash} est mort sans avoir avance : le test ne prouverait rien.",
            );

            self::assertSame(
                $crashedAt,
                $this->snapshots->latest($worldId)?->tick,
                'Le snapshot et le compteur de tick ne sont pas d\'accord : une ecriture est sortie de la transaction.',
            );

            $lastLoggedTick = Row::toInt($this->database->connection()->table('events')
                ->where('world_id', $worldId)
                ->max('tick'));

            self::assertLessThanOrEqual(
                $crashedAt,
                $lastLoggedTick,
                'Des Faits ont ete journalises pour un tick que le monde n\'a pas atteint.',
            );
        }

        // --- 2. Le monde reprend, et sa suite est celle qu'elle aurait ete ---
        $target = $crashedAt + self::TICKS_AFTER_RECOVERY;

        $advance = new AdvanceWorld(
            $this->database,
            $this->worlds,
            $this->events,
            $this->snapshots,
            new WorldLock($this->database),
        );

        // On avance **jusqu'a atteindre le tick vise**, en tolerant les Busy,
        // au lieu de compter des iterations. La difference n'est pas
        // cosmetique : un `advance()` refuse n'ecrit rien, et une boucle qui
        // compte ses tours croirait avoir avance alors qu'elle a tourne a
        // vide. C'est exactement ce que fait un cron - il repasse.
        $attempts = 0;
        while (($this->worlds->find($worldId)->tick ?? 0) < $target) {
            $advance($worldId);

            self::assertLessThan(
                self::TICKS_AFTER_RECOVERY * 10,
                ++$attempts,
                'Le monde refuse d\'avancer : le verrou n\'a jamais ete relache.',
            );
        }

        $resumed = $this->snapshots->latest($worldId);
        self::assertNotNull($resumed);
        self::assertSame($target, $resumed->tick);

        self::assertSame(
            $this->uninterrupted($spec, $target),
            $resumed->state,
            'Le monde repris diverge de celui qui n\'a jamais ete interrompu.',
        );
    }

    /**
     * Lance un vrai `bin/host.php advance` et le tue en plein tick. Le nombre
     * de ticks demande est volontairement hors d'atteinte : ce qui doit
     * arreter ce processus est le signal, jamais la fin de son travail.
     */
    private function killMidAdvance(string $worldId): void
    {
        $command = [PHP_BINARY, __DIR__ . '/../bin/host.php', 'advance', $worldId, '--ticks=100000'];

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        usleep((int) (self::KILL_AFTER_SECONDS * 1_000_000));

        $running = proc_get_status($process)['running'];
        self::assertTrue($running, 'Le sous-processus s\'est arrete tout seul : rien n\'a ete tue.');

        proc_terminate($process, SIGKILL);

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        proc_close($process);
        $this->waitForLockRelease($worldId);
    }

    /**
     * **Le verrou survit brievement au processus mort.** PostgreSQL ne libere
     * un verrou lie a une transaction qu'apres avoir constate que la connexion
     * a disparu, ce qui n'est pas instantane : mesure entre **0,7 et 3,4 ms**
     * apres un SIGKILL sur cette machine.
     *
     * Ce n'est ni un bug ni un probleme d'exploitation - un cron qui repasse
     * dans l'heure ne verra jamais la difference. Mais un test qui enchaine
     * une mise a mort et une reprise immediate, lui, tombe en plein dedans, et
     * confondrait « refuse par le verrou » avec « incoherent ». On attend donc
     * que le verrou retombe avant de conclure quoi que ce soit.
     */
    private function waitForLockRelease(string $worldId): void
    {
        $lock = new WorldLock($this->database);
        $connection = $this->database->connection();
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            $connection->beginTransaction();
            $free = $lock->tryAcquire($worldId);
            $connection->rollBack();

            if ($free) {
                return;
            }

            usleep(2000);
        }

        self::fail('Le verrou du monde n\'a jamais ete relache apres la mort du processus.');
    }

    /**
     * L'etat d'un monde jamais interrompu au meme tick, calcule en memoire -
     * `host` n'a pas le droit d'importer le harness (docs/11- §7), et de
     * toute facon un diff de structure dit *ou* ca diverge la ou un hash dit
     * seulement *que* ca diverge.
     *
     * @return array<string, mixed>
     */
    private function uninterrupted(WorldSpec $spec, int $target): array
    {
        $world = new WorldState();
        (new WorldFactory())->populate($world, $spec, atTick: 1);

        $simulation = new Simulation(FootballPipeline::build());
        $ruleset = new Ruleset('default');

        for ($tick = 1; $tick <= $target; $tick++) {
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
