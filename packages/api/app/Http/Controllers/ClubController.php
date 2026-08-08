<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Flair\Api\Read\ClubSheetReader;
use Flair\Api\Read\History\ClubHistoryReader;
use Flair\Api\Read\LoadedWorld;
use Flair\Api\Read\View\ClubHistoryView;
use Flair\Api\Read\View\ClubSheetView;
use Flair\Api\Read\WorldReader;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * La fiche d'un club - l'ecran de ce lot.
 *
 * Meme structure que `WorldController` : deux presentations, une seule source
 * (`sheet()`), et aucune lecture ici.
 *
 * ⚠️ Ce que cette fiche montre est la **verite cachee** du monde : la note au
 * meilleur poste calculee sur les competences reelles. C'est legitime parce que
 * la seule surface qui existe aujourd'hui est celle d'**exploitation** - un
 * exploitant voit son monde, sinon il ne peut pas l'inspecter (docs/15- §4
 * Phase 4). Le jour ou un client de **jeu** lira ces fiches, c'est
 * `ClubSheetReader::qualityOf()` qui basculera vers
 * `Football\Support\PerceptionModel`, et rien d'autre - voir son docblock.
 */
final class ClubController extends Controller
{
    public function __construct(
        private readonly WorldReader $reader,
        private readonly ClubSheetReader $sheets,
        private readonly ClubHistoryReader $histories,
    ) {
    }

    public function show(string $world, int $club): View
    {
        return view('clubs.show', ['worldId' => $world, 'club' => $this->sheet($world, $club)]);
    }

    public function showJson(string $world, int $club): JsonResponse
    {
        return response()->json($this->sheet($world, $club));
    }

    public function history(string $world, int $club): View
    {
        return view('clubs.history', ['history' => $this->historyOf($world, $club)]);
    }

    public function historyJson(string $world, int $club): JsonResponse
    {
        return response()->json($this->historyOf($world, $club));
    }

    private function historyOf(string $worldId, int $clubId): ClubHistoryView
    {
        $history = $this->histories->read($this->world($worldId), $clubId);

        if ($history === null) {
            abort(404, "Aucun club {$clubId} dans le monde \"{$worldId}\".");
        }

        return $history;
    }

    private function sheet(string $worldId, int $clubId): ClubSheetView
    {
        $world = $this->world($worldId);
        $sheet = $this->sheets->read($world, $clubId);

        if ($sheet === null) {
            abort(404, "Aucun club {$clubId} dans le monde \"{$worldId}\" au tick {$world->tick}.");
        }

        return $sheet;
    }

    private function world(string $worldId): LoadedWorld
    {
        $world = $this->reader->load($worldId);

        if ($world === null) {
            abort(404, "Monde \"{$worldId}\" inconnu, ou sans snapshot pour le reconstituer.");
        }

        return $world;
    }
}
