@php
    $nomAsso = $portailAssociation->nom;
    $logoUrl = \App\Support\PortailRoute::to('logo', $portailAssociation);
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $nomAsso }} — Portail</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    @livewireStyles
    <style>
        body { background: #f4f6f9; }

        /* Barre de tête compacte */
        .portail-topbar {
            background: #fff;
            border-bottom: 1px solid #dee2e6;
            padding: .6rem 1.25rem;
            display: flex;
            align-items: center;
            gap: .75rem;
        }
        .portail-topbar img { height: 36px; }
        .portail-topbar .asso-name {
            font-size: .95rem;
            font-weight: 600;
            color: #3d5473;
            margin: 0;
        }
        .portail-topbar .portail-label {
            font-size: .78rem;
            color: #6c757d;
            border-left: 1px solid #dee2e6;
            padding-left: .75rem;
            margin-left: .25rem;
        }

        /* Sidebar */
        .portail-sidebar {
            width: 220px;
            flex-shrink: 0;
        }
        .sidebar-nav {
            background: #fff;
            border-radius: .5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,.05);
            padding: .5rem 0;
        }
        .sidebar-nav .nav-link {
            color: #495057;
            padding: .65rem 1rem;
            border-left: 3px solid transparent;
            border-radius: 0;
            display: flex;
            align-items: center;
            gap: .5rem;
            text-decoration: none;
        }
        .sidebar-nav .nav-link.active {
            background: #eef2f7;
            color: #1f2d3d;
            border-left-color: #3d5473;
            font-weight: 600;
        }
        .sidebar-nav .nav-link:hover:not(.active) { background: #f8f9fa; }
        .sidebar-nav .nav-link i { width: 20px; color: #6c757d; flex-shrink: 0; }
        .sidebar-nav .nav-link.active i { color: #3d5473; }
        .sidebar-nav .nav-link.text-danger i { color: currentColor; }
        .sidebar-nav hr { margin: .5rem 1rem; }
        .sidebar-nav .nav-section-label {
            font-size: .72rem;
            text-transform: uppercase;
            color: #adb5bd;
            padding: .6rem 1rem .2rem;
            letter-spacing: .05em;
        }

        /* Zone principale */
        .portail-main {
            flex: 1;
            min-width: 0;
        }

        /* Footer */
        .portail-footer {
            text-align: center;
            padding: 1.25rem 0 1rem;
        }
    </style>
</head>
<body>
    {{-- Barre de tête --}}
    <div class="portail-topbar">
        <img src="{{ $logoUrl }}" alt="{{ $nomAsso }}">
        <span class="asso-name">{{ $nomAsso }}</span>
        <span class="portail-label">Portail</span>
    </div>

    {{-- Corps : sidebar + contenu --}}
    <div class="container-fluid px-3 py-3">
        <div class="d-flex gap-3 align-items-start">

            {{-- Sidebar --}}
            <aside class="portail-sidebar d-none d-md-block">
                @include('portail.layouts.partials.sidebar', [
                    'tiers' => $tiers ?? null,
                    'portailAssociation' => $portailAssociation,
                ])
            </aside>

            {{-- Contenu principal --}}
            <main class="portail-main">
                {{ $slot }}
            </main>
        </div>
    </div>

    {{-- Footer --}}
    <div class="portail-footer">
        <img src="{{ asset('images/agora-gestion.svg') }}" alt="AgoraGestion" height="50" class="opacity-75 d-block mx-auto">
        <small class="text-muted">{{ config('version.tag', '') }}</small>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    @livewireScripts
</body>
</html>
