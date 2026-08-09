<?php

declare(strict_types=1);

namespace Flair\Host\Tests;

use Flair\Host\AdvanceWorld;
use Flair\Host\CreateWorld;
use Flair\Host\Rules\RulesetForWorld;
use Flair\Host\Rules\UnsupportedRulesetVersion;
use Flair\Host\WorldLock;
use Flair\Worldgen\WorldSpec;

/**
 * Un monde ne tourne jamais selon des regles qui ne sont pas les siennes.
 *
 * `AdvanceWorld` faisait `new Ruleset($world->rulesetVersion)`, ce qui rendait
 * les defauts du noyau **quelle que soit** la version epinglee : un monde
 * epingle a d'autres regles aurait tourne selon celles-la, correctement en
 * apparence, faux en realite, et sans qu'aucun test ne puisse s'en apercevoir.
 * C'est le mode de panne que ces trois cas excluent.
 *
 * Ce garde vit dans `host` et non dans le noyau parce que `rulesetVersion` y
 * est une **etiquette** libre dont le harness se sert comme telle
 * (`'harness'`, `'ci'`, `'snapshot-continuity'`...) - voir le docblock de
 * `Rules\RulesetForWorld`.
 */
final class RulesetForWorldTest extends DatabaseTestCase
{
    public function testTheSupportedVersionRebuildsARuleset(): void
    {
        self::assertTrue(RulesetForWorld::supports(RulesetForWorld::VERSION));
        self::assertSame(RulesetForWorld::VERSION, RulesetForWorld::for(RulesetForWorld::VERSION)->version);
    }

    public function testAnUnknownVersionRaisesRatherThanFallingBackOnDefaults(): void
    {
        self::assertFalse(RulesetForWorld::supports('v2-inflation-forte'));

        $this->expectException(UnsupportedRulesetVersion::class);
        RulesetForWorld::for('v2-inflation-forte');
    }

    public function testAWorldCannotBeCreatedPinnedToAnUnknownVersion(): void
    {
        $worldId = $this->newWorldId('ruleset-inconnu');

        try {
            (new CreateWorld($this->database, $this->worlds, $this->snapshots))(
                $worldId,
                new WorldSpec(playerCount: 20, seed: 42, clubCount: 2),
                'v2-inflation-forte',
            );
            self::fail('Un monde epingle a une version inconnue a pu etre cree.');
        } catch (UnsupportedRulesetVersion) {
            // Attendu. Et rien ne doit avoir ete ecrit : le garde passe avant
            // le genesis, pas seulement avant la transaction - engendrer un
            // monde pour le jeter ensuite serait du travail perdu, mais
            // surtout un monde a moitie ecrit serait pire.
            self::assertNull($this->worlds->find($worldId));
            self::assertNull($this->snapshots->latest($worldId));
        }
    }

    public function testAWorldAlreadyPinnedToAnUnknownVersionRefusesToAdvance(): void
    {
        $worldId = $this->newWorldId('ruleset-derive');
        (new CreateWorld($this->database, $this->worlds, $this->snapshots))(
            $worldId,
            new WorldSpec(playerCount: 20, seed: 42, clubCount: 2),
        );

        // Le cas reel : un monde ecrit par un autre build, ou par le
        // `packages/ruleset` de demain. On le fabrique en touchant la colonne
        // directement, parce que c'est precisement ce que `CreateWorld`
        // n'accepte plus de produire.
        $this->database->connection()->table('worlds')
            ->where('id', $worldId)
            ->update(['ruleset_version' => 'v2-inflation-forte']);

        $advance = new AdvanceWorld(
            $this->database,
            $this->worlds,
            $this->events,
            $this->snapshots,
            new WorldLock($this->database),
        );

        try {
            $advance($worldId);
            self::fail('Un monde epingle a une version inconnue a pu avancer.');
        } catch (UnsupportedRulesetVersion) {
            // Attendu, et le monde n'a pas bouge : le garde leve **dans** la
            // transaction, qui est donc annulee en entier.
            self::assertSame(0, $this->worlds->find($worldId)?->tick);
            self::assertSame(0, $this->snapshots->latest($worldId)?->tick);
            self::assertSame(0, $this->events->countFor($worldId));
        }
    }
}
