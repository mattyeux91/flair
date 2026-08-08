<?php

declare(strict_types=1);

namespace Flair\Api\Tests\Architecture;

use Flair\Api\Read\History\ClubMentions;
use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Football\FootballTypes;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Le test qui rend l'oubli impossible, cote histoire.
 *
 * Un Fait ajoute au noyau et non traite par `Read\History\ClubMentions`
 * n'echouerait nulle part : il disparaitrait simplement de l'histoire de tous
 * les clubs, en silence, et personne ne le verrait avant de chercher pourquoi
 * une saison semble vide. C'est le meme mode de panne que
 * `Kernel\Tests\Core\Snapshot\SnapshotConformanceTest` exclut cote
 * serialisation - et le meme remede : **balayer le registre plutot que tenir
 * une liste a la main**.
 *
 * La regle est « place **ou** explicitement exclu ». L'exclusion n'est pas une
 * echappatoire : elle demande d'inscrire le type dans
 * `ClubMentions::NOT_ABOUT_A_CLUB` avec sa raison, ce qui transforme un oubli
 * en decision relisible. Deux des cinq exclusions actuelles y sont marquees
 * « dette connue », ce qui est precisement l'information qu'on veut voir.
 */
final class EveryFactIsPlacedOrExcludedTest extends TestCase
{
    public function testEveryRegisteredFactIsEitherMappedOrExplicitlyExcluded(): void
    {
        $mentions = new ClubMentions();
        $events = FootballTypes::registry()->events;

        self::assertGreaterThan(10, count($events), 'Le registre des Faits semble vide : FootballTypes a bouge.');

        foreach ($events as $key => $class) {
            $excluded = array_key_exists($class, ClubMentions::NOT_ABOUT_A_CLUB);
            $mapped = $mentions->of($this->fabricate($class)) !== [];

            self::assertTrue(
                $mapped || $excluded,
                sprintf(
                    "Le Fait \"%s\" (%s) n'est ni traite par ClubMentions::of() ni inscrit dans NOT_ABOUT_A_CLUB.\n"
                    . "Il disparaitrait en silence de l'histoire de tous les clubs.\n"
                    . 'Soit il nomme un club et il faut un cas dans le `match`, soit il n\'en nomme pas et il faut '
                    . 'l\'inscrire dans la liste **avec sa raison**.',
                    $key,
                    $class,
                ),
            );

            self::assertFalse(
                $mapped && $excluded,
                sprintf(
                    'Le Fait "%s" est a la fois traite et exclu : la liste NOT_ABOUT_A_CLUB ment.',
                    $key,
                ),
            );
        }
    }

    public function testEveryExclusionCarriesAReason(): void
    {
        foreach (ClubMentions::NOT_ABOUT_A_CLUB as $class => $reason) {
            self::assertArrayHasKey(
                $class,
                array_flip(FootballTypes::registry()->events),
                "{$class} est exclu mais n'est plus un Fait enregistre : la liste a survecu a son sujet.",
            );

            self::assertNotSame('', trim($reason), "L'exclusion de {$class} n'a pas de raison ecrite.");
        }
    }

    /**
     * Un exemplaire du Fait, avec des identifiants distincts et non nuls.
     *
     * Fabrique par reflexion plutot qu'a la main : une liste d'exemplaires
     * ecrite en dur aurait exactement le defaut que ce test corrige - elle
     * vieillirait sans bruit. Les entiers valent 1, 2, 3... pour qu'un cas de
     * `ClubMentions` qui confondrait deux champs (acheteur et vendeur, par
     * exemple) ne passe pas inapercu.
     *
     * @param class-string $class
     */
    private function fabricate(string $class): DomainEvent
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        $arguments = [];
        $counter = 1;

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof ReflectionNamedType ? $type->getName() : 'int';

            $arguments[] = match ($name) {
                'int' => $counter++,
                'float' => (float) $counter++,
                'string' => 'x' . $counter++,
                'bool' => true,
                // `SeasonConcluded::$finalRanking` : un classement plausible,
                // dont les identifiants ne recoupent pas ceux des autres
                // champs de facon ambigue.
                'array' => [10, 11, 12],
                default => null,
            };
        }

        $event = $reflection->newInstanceArgs($arguments);

        return $event instanceof DomainEvent
            ? $event
            : self::fail("{$class} est enregistre comme Fait mais n'implemente pas DomainEvent.");
    }
}
