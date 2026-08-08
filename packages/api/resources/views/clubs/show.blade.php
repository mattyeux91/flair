@extends('layout')
@section('title', $club->name)

@php
    use Flair\Api\Format\Money;

    $positionLabels = ['GK' => 'Gardiens', 'DEF' => 'Defenseurs', 'MID' => 'Milieux', 'ATT' => 'Attaquants'];
@endphp

@section('content')
    <p class="crumbs">
        <a href="{{ route('worlds.index') }}">les mondes</a> ›
        <a href="{{ route('worlds.show', $worldId) }}">{{ $worldId }}</a>
    </p>

    <h1>{{ $club->name }}</h1>
    <ul class="facts">
        <li>entite <b>{{ $club->id }}</b></li>
        @if ($club->standing !== null)
            <li><b>{{ $club->standing->rank }}<sup>e</sup></b> — {{ $club->standing->points }} pts en {{ $club->standing->played }} journees</li>
        @endif
        <li><a href="{{ route('clubs.history', [$worldId, $club->id]) }}">son histoire →</a></li>
        <li><a href="{{ route('clubs.digest', [$worldId, $club->id]) }}">qu'ai-je raté ? →</a></li>
    </ul>

    <h2>Le club</h2>
    <div class="tiles">
        <div class="tile"><span>Solde</span><strong>{{ Money::roundEuros($club->balanceCents) }}</strong></div>
        <div class="tile"><span>Revenu de saison</span><strong>{{ Money::roundEuros($club->seasonIncomeCents) }}</strong></div>
        <div class="tile"><span>Masse salariale / sem.</span><strong>{{ Money::roundEuros($club->wageBillPerWeekCents) }}</strong></div>
        <div class="tile"><span>Installations</span><strong>{{ number_format($club->facilitiesQuality, 2, ',', ' ') }}</strong></div>
        <div class="tile"><span>Patience du conseil</span><strong>{{ $club->boardPatience }}</strong></div>
        <div class="tile">
            <span>Recruteur</span>
            <strong>{{ $club->scout?->judgement ?? '—' }}</strong>
        </div>
    </div>
    <p class="note">
        @if ($club->scout !== null)
            Recruteur : <b>{{ $club->scout->name }}</b>, jugement {{ $club->scout->judgement }}/100. C'est lui qui determine a quel point ce club se trompe
            sur les joueurs qu'il evalue (<code>PerceptionModel::sigma()</code>).
        @else
            <b>Ce club n'emploie aucun recruteur</b> — ce qui n'en fait pas un omniscient mais le pire observateur du monde.
        @endif
        Les notes ci-dessous sont en revanche la <b>verite</b> du monde : cette page est une surface d'exploitation, pas un client de jeu.
    </p>

    <h2>Effectif — {{ $club->squadSize }} joueurs</h2>
    @foreach ($club->squadByPosition as $position => $players)
        <h2 style="margin-top:1.25rem">{{ $positionLabels[$position] ?? $position }} <span style="text-transform:none;letter-spacing:0">({{ count($players) }})</span></h2>
        <div class="scroll">
            @if ($players === [])
                <p class="empty">Aucun joueur a ce poste. C'est l'information la plus interessante qu'une fiche puisse porter — c'est de la que naissent les negociations.</p>
            @else
                <table>
                    <thead>
                    <tr>
                        <th class="n">id</th>
                        <th>joueur</th>
                        <th class="n">age</th>
                        <th class="n">note</th>
                        <th class="n">plafond</th>
                        <th>archetype</th>
                        <th class="n">salaire / sem.</th>
                        <th class="n">contrat jusqu'au jour</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($players as $player)
                        <tr>
                            <td class="n">{{ $player->id }}</td>
                            <td>{{ $player->name }}</td>
                            <td class="n">{{ number_format($player->age, 1, ',', ' ') }}</td>
                            <td class="n"><b>{{ $player->quality }}</b></td>
                            <td class="n">{{ $player->ceiling }}</td>
                            <td>{{ $player->archetype }}{{ $player->archetype !== $position ? ' → ' . $position : '' }}</td>
                            <td class="n">{{ Money::euros($player->wagePerWeekCents) }}</td>
                            <td class="n">{{ number_format($player->contractExpiresOnDay, 0, ',', "\u{202f}") }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endforeach

    <p class="note">
        Le <b>poste</b> est derive des competences du moment (<code>PositionModel::bestPosition()</code>), jamais stocke.
        L'<b>archetype</b>, lui, est fixe a la naissance (<code>PlayerPotentials::$archetype</code>) : quand les deux
        divergent, le joueur ne joue pas la ou il a ete concu pour jouer.
    </p>
@endsection
