<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Soigner•Vivre•Sourire Comptabilité' }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    @livewireStyles
</head>
<body>
    @auth
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center gap-2" href="{{ route('dashboard') }}">
                <img src="{{ asset('images/logo.png') }}" alt="Soigner•Vivre•Sourire" height="45">
                <span class="d-inline-block lh-sm">
                    <span class="d-block">Soigner•Vivre•Sourire</span>
                    <span class="d-block small opacity-75">Comptabilité</span>
                </span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                    data-bs-target="#navbarNav" aria-controls="navbarNav"
                    aria-expanded="false" aria-label="Ouvrir la navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    @php
                        $navItems = [
                            ['route' => 'depenses.index',      'icon' => 'arrow-down-circle',      'label' => 'Dépenses'],
                            ['route' => 'recettes.index',      'icon' => 'arrow-up-circle',        'label' => 'Recettes'],
                            ['route' => 'virements.index',     'icon' => 'arrow-left-right',       'label' => 'Virements'],
                            ['route' => 'budget.index',        'icon' => 'piggy-bank',             'label' => 'Budget'],
                            ['route' => 'rapprochement.index', 'icon' => 'bank',                   'label' => 'Rapprochement'],
                            ['route' => 'membres.index',       'icon' => 'people',                 'label' => 'Membres'],
                            ['route' => 'dons.index',          'icon' => 'heart',                  'label' => 'Dons'],
                            ['route' => 'rapports.index',      'icon' => 'file-earmark-bar-graph', 'label' => 'Rapports'],
                        ];
                    @endphp
                    @foreach ($navItems as $item)
                        @if (Route::has($item['route']))
                            <li class="nav-item">
                                <a class="nav-link {{ request()->routeIs(str_replace('.index', '.*', $item['route'])) ? 'active' : '' }}"
                                   href="{{ route($item['route']) }}">
                                    <i class="bi bi-{{ $item['icon'] }}"></i> {{ $item['label'] }}
                                </a>
                            </li>
                        @endif
                    @endforeach

                    {{-- Dropdown Paramètres --}}
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->routeIs('parametres.*') || request()->routeIs('operations.*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear"></i> Paramètres
                        </a>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('parametres.categories.*') ? 'active' : '' }}"
                                   href="{{ route('parametres.categories.index') }}">
                                    <i class="bi bi-tags"></i> Catégories
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('parametres.sous-categories.*') ? 'active' : '' }}"
                                   href="{{ route('parametres.sous-categories.index') }}">
                                    <i class="bi bi-tag"></i> Sous-catégories
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('operations.*') ? 'active' : '' }}"
                                   href="{{ route('operations.index') }}">
                                    <i class="bi bi-calendar-event"></i> Opérations
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('parametres.comptes-bancaires.*') ? 'active' : '' }}"
                                   href="{{ route('parametres.comptes-bancaires.index') }}">
                                    <i class="bi bi-bank"></i> Comptes bancaires
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('parametres.utilisateurs.*') ? 'active' : '' }}"
                                   href="{{ route('parametres.utilisateurs.index') }}">
                                    <i class="bi bi-people"></i> Utilisateurs
                                </a>
                            </li>
                        </ul>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <div class="dropdown">
                            <button class="btn btn-outline-light btn-sm dropdown-toggle" type="button"
                                    data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-person-circle"></i> {{ auth()->user()->nom }}
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li>
                                    <a class="dropdown-item" href="{{ route('profil.index') }}">
                                        <i class="bi bi-person"></i> Mon profil
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <form method="POST" action="{{ route('logout') }}">
                                        @csrf
                                        <button type="submit" class="dropdown-item text-danger">
                                            <i class="bi bi-box-arrow-right"></i> Déconnexion
                                        </button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    @endauth

    <div class="container-fluid px-4">
        <x-flash-message />
        {{ $slot }}
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    @livewireScripts
</body>
</html>
