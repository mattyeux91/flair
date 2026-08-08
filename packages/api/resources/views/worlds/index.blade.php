@extends('layout')
@section('title', 'Les mondes')

@section('content')
    <h1>Les mondes</h1>
    <p class="note">Aucun snapshot n'est decode sur cette page : tout vient de <code>worlds</code> et du compte de <code>events</code>. Un decodage complet coute 14&nbsp;ms et 18&nbsp;Mo, et lister n'en a pas besoin.</p>

    <div class="scroll">
        @if ($worlds === [])
            <p class="empty">Aucun monde. En creer un : <code>php packages/host/bin/host.php create alpha --players=500 --clubs=18 --seed=42</code></p>
        @else
            <table>
                <thead>
                <tr>
                    <th>monde</th>
                    <th class="n">tick</th>
                    <th class="n">saison</th>
                    <th class="n">graine</th>
                    <th class="n">faits</th>
                    <th>noyau / ruleset</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($worlds as $world)
                    <tr>
                        <td><a href="{{ route('worlds.show', $world->id) }}">{{ $world->id }}</a></td>
                        <td class="n">{{ number_format($world->tick, 0, ',', "\u{202f}") }}</td>
                        <td class="n">{{ $world->season }}</td>
                        <td class="n">{{ $world->seed }}</td>
                        <td class="n">{{ number_format($world->eventCount, 0, ',', "\u{202f}") }}</td>
                        <td>{{ $world->kernelVersion }} / {{ $world->rulesetVersion }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
