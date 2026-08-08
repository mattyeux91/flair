{{--
    Mise en page unique de la surface d'administration.

    Le CSS est **en ligne**, et c'est delibere : la chaine de build de Laravel
    (`package.json`, `vite.config.js`, `resources/js`, `resources/css`) a ete
    retiree du paquet. Un pipeline npm qu'on n'execute jamais est exactement le
    genre de piece morte qui pourrit sans qu'on le voie - ce qui est arrive a
    `packages/harness/public/`, casse depuis le lot worldgen parce qu'il etait
    hors PHPStan et hors test. Une page d'admin lisible ne demande pas de build.
--}}
<!DOCTYPE html>
<html lang="fr" data-theme-support>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Flair') — administration</title>
    <style>
        :root {
            --ground: #fbfaf8;
            --panel: #ffffff;
            --ink: #1b1a17;
            --muted: #6b6862;
            --line: #e3e0d9;
            --accent: #1f6f5c;
            --warn: #9a4b2f;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --ground: #16171a;
                --panel: #1e1f23;
                --ink: #eceae5;
                --muted: #9a978f;
                --line: #2e3036;
                --accent: #6fbfa5;
                --warn: #d98b6a;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 2rem 1.5rem 4rem;
            background: var(--ground);
            color: var(--ink);
            font: 15px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
        }
        main { max-width: 76rem; margin: 0 auto; }
        a { color: var(--accent); }
        h1 { font-size: 1.5rem; margin: 0 0 .25rem; letter-spacing: -.01em; }
        h2 { font-size: 1rem; margin: 2rem 0 .75rem; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); }
        .crumbs { font-size: .85rem; color: var(--muted); margin-bottom: 1.5rem; }
        .crumbs a { text-decoration: none; }
        .facts { display: flex; flex-wrap: wrap; gap: .5rem 2rem; margin: 0 0 .5rem; padding: 0; list-style: none; font-size: .9rem; color: var(--muted); }
        .facts b { color: var(--ink); font-variant-numeric: tabular-nums; font-weight: 600; }
        .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: .75rem; }
        .tile { background: var(--panel); border: 1px solid var(--line); border-radius: .5rem; padding: .8rem .9rem; }
        .tile span { display: block; font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
        .tile strong { display: block; margin-top: .2rem; font-size: 1.2rem; font-variant-numeric: tabular-nums; }
        .scroll { overflow-x: auto; background: var(--panel); border: 1px solid var(--line); border-radius: .5rem; }
        table { width: 100%; border-collapse: collapse; font-size: .9rem; }
        th, td { padding: .45rem .7rem; text-align: left; white-space: nowrap; }
        th { font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); border-bottom: 1px solid var(--line); }
        tbody tr + tr td { border-top: 1px solid var(--line); }
        td.n, th.n { text-align: right; font-variant-numeric: tabular-nums; }
        .empty { padding: .8rem .9rem; color: var(--warn); font-size: .9rem; }
        .note { font-size: .85rem; color: var(--muted); margin: .5rem 0 0; }
        code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .85em; }
    </style>
</head>
<body>
<main>
    @yield('content')
</main>
</body>
</html>
