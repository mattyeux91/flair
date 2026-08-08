<?php

declare(strict_types=1);

namespace Flair\Api\Tests\Architecture;

use Flair\Api\Read\History\ClubMentions;
use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Snapshot\SnapshotArrayOf;
use Flair\Kernel\Football\FootballTypes;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

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
 * en decision relisible.
 *
 * Cette liste a compte cinq entrees, dont **deux marquees « dette connue »** -
 * `PlayerRetired` et `TransferCounterDemanded`, deux Faits qui ne portaient pas
 * de quoi les attribuer a un club. C'etait exactement l'information qu'on
 * voulait voir : les Faits ont ete corriges, et il ne reste que trois
 * exclusions, toutes de vraies decisions.
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
                'array' => $this->fabricateList($parameter, $counter),
                default => null,
            };
        }

        $event = $reflection->newInstanceArgs($arguments);

        return $event instanceof DomainEvent
            ? $event
            : self::fail("{$class} est enregistre comme Fait mais n'implemente pas DomainEvent.");
    }

    /**
     * Trois elements du type que le parametre **declare deja** via
     * `SnapshotArrayOf` - l'attribut dont le codec se sert pour serialiser ce
     * meme champ.
     *
     * Suivre cette declaration plutot qu'ecrire `[10, 11, 12]` en dur est ce
     * qui empeche ce test de vieillir : le jour ou un Fait porte une liste
     * d'objets (`SeasonConcluded::$finalTable` est le premier), une constante
     * d'entiers ferait tomber `ClubMentions` sur une erreur de type, et le
     * test accuserait le code au lieu de lui-meme.
     *
     * @return list<mixed>
     */
    private function fabricateList(ReflectionParameter $parameter, int &$counter): array
    {
        $attributes = $parameter->getAttributes(SnapshotArrayOf::class);
        $of = $attributes === [] ? 'int' : $attributes[0]->newInstance()->type;

        if ($of === 'int') {
            return [10, 11, 12];
        }

        self::assertTrue(class_exists($of), "SnapshotArrayOf({$of}) ne designe ni 'int' ni une classe.");

        // Le premier parametre porte l'identifiant dans tous les types de
        // valeur du domaine (`StandingsEntry::$clubId`), et les suivants ont
        // un defaut : trois lignes distinctes suffisent a ce test.
        return [
            new $of($counter++),
            new $of($counter++),
            new $of($counter++),
        ];
    }
}
