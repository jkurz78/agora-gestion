@php
    $association   = \App\Models\Association::find(1);
    $nomAsso       = $association?->nom ?? 'Soigner•Vivre•Sourire';
    $logoAsset     = ($association?->logo_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($association->logo_path))
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($association->logo_path)
        : asset('images/logo.png');
    $exerciceService = app(\App\Services\ExerciceService::class);
    $exerciceActif   = $exerciceService->current();
    $exerciceLabel   = $exerciceService->label($exerciceActif);
    $exercicesDispos = $exerciceService->available(6);
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? $nomAsso.' Comptabilité' }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/fr.js"></script>
    <script>
    window.svsParseFlatpickrDate = function(str) {
        str = (str || '').trim();
        const y = new Date().getFullYear();
        function make(d, m, yr) {
            d = parseInt(d, 10); m = parseInt(m, 10) - 1; yr = parseInt(yr, 10);
            const dt = new Date(yr, m, d);
            return (!isNaN(dt) && dt.getDate()===d && dt.getMonth()===m && dt.getFullYear()===yr) ? dt : null;
        }
        if (/^\d{8}$/.test(str)) return make(str.slice(0,2), str.slice(2,4), str.slice(4,8));
        if (/^\d{6}$/.test(str)) return make(str.slice(0,2), str.slice(2,4), 2000 + parseInt(str.slice(4,6), 10));
        if (/^\d{4}$/.test(str))  return make(str.slice(0,2), str.slice(2,4), y);
        const p = str.split(/[\/\-\.]/);
        if (p.length === 2) return make(p[0], p[1], y);
        if (p.length === 3) return make(p[0], p[1], p[2].length <= 2 ? 2000 + parseInt(p[2], 10) : p[2]);
        return null;
    };
    </script>
    @livewireStyles
    <style>
        .navbar-svs {
            background-color: #722281;
        }
        .navbar-svs .navbar-brand,
        .navbar-svs .nav-link,
        .navbar-svs .navbar-toggler {
            color: rgba(255, 255, 255, 0.9);
        }
        .navbar-svs .nav-link:hover,
        .navbar-svs .nav-link:focus {
            color: #fff;
            background-color: rgba(255, 255, 255, 0.12);
            border-radius: 6px;
        }
        .navbar-svs .nav-link.active {
            color: #fff;
            background-color: rgba(255, 255, 255, 0.2);
            border-radius: 6px;
            font-weight: 600;
        }
        .navbar-svs .navbar-toggler {
            border-color: rgba(255, 255, 255, 0.4);
        }
        .navbar-svs .dropdown-menu {
            background-color: #fff;
            border: none;
            box-shadow: 0 4px 16px rgba(114, 34, 129, 0.18);
            border-radius: 8px;
        }
        .navbar-svs .dropdown-item {
            color: #3d1245;
        }
        .navbar-svs .dropdown-item:hover,
        .navbar-svs .dropdown-item:focus {
            background-color: #f3e5f7;
            color: #722281;
        }
        .navbar-svs .dropdown-item.active,
        .navbar-svs .dropdown-item:active {
            background-color: #722281;
            color: #fff;
        }
        .navbar-svs .dropdown-divider {
            border-color: #e8d0ee;
        }
        .navbar-svs .btn-user {
            background-color: rgba(255, 255, 255, 0.15);
            border-color: rgba(255, 255, 255, 0.4);
            color: #fff;
        }
        .navbar-svs .btn-user:hover {
            background-color: rgba(255, 255, 255, 0.25);
            border-color: rgba(255, 255, 255, 0.7);
            color: #fff;
        }
    </style>
</head>
<body>
    @auth
    <nav class="navbar navbar-expand-lg navbar-svs mb-4">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center gap-2" href="{{ route('dashboard') }}">
                <img src="{{ $logoAsset }}" alt="{{ $nomAsso }}" height="45">
                <span class="d-inline-block lh-sm">
                    <span class="d-block">{{ $nomAsso }}</span>
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

                    {{-- Dropdown Transactions --}}
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->routeIs('transactions.*') || request()->routeIs('virements.*') || request()->routeIs('dons.*') || request()->routeIs('cotisations.*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-arrow-down-up"></i> Transactions
                        </a>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('transactions.*') && !request()->query('type') ? 'active' : '' }}"
                                   href="{{ route('transactions.index') }}">
                                    <i class="bi bi-list-ul"></i> Toutes
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->query('type') === 'depense' ? 'active' : '' }}"
                                   href="{{ route('transactions.index') }}?type=depense">
                                    <i class="bi bi-arrow-down-circle"></i> Dépenses
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->query('type') === 'recette' ? 'active' : '' }}"
                                   href="{{ route('transactions.index') }}?type=recette">
                                    <i class="bi bi-arrow-up-circle"></i> Recettes
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            @if (Route::has('virements.index'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('virements.*') ? 'active' : '' }}"
                                   href="{{ route('virements.index') }}">
                                    <i class="bi bi-arrow-left-right"></i> Virements
                                </a>
                            </li>
                            @endif
                            <li><hr class="dropdown-divider"></li>
                            @if (Route::has('dons.index'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('dons.*') ? 'active' : '' }}"
                                   href="{{ route('dons.index') }}">
                                    <i class="bi bi-heart"></i> Dons
                                </a>
                            </li>
                            @endif
                            @if (Route::has('cotisations.index'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('cotisations.*') ? 'active' : '' }}"
                                   href="{{ route('cotisations.index') }}">
                                    <i class="bi bi-person-check"></i> Cotisations
                                </a>
                            </li>
                            @endif
                        </ul>
                    </li>

                    {{-- Dropdown Banques --}}
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->routeIs('comptes-bancaires.*') || request()->routeIs('rapprochement.*') || request()->routeIs('parametres.comptes-bancaires.*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-bank2"></i> Banques
                        </a>
                        <ul class="dropdown-menu">
                            @if (Route::has('comptes-bancaires.transactions'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('comptes-bancaires.*') ? 'active' : '' }}"
                                   href="{{ route('comptes-bancaires.transactions') }}">
                                    <i class="bi bi-list-ul"></i> Transactions
                                </a>
                            </li>
                            @endif
                            @if (Route::has('rapprochement.index'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('rapprochement.*') ? 'active' : '' }}"
                                   href="{{ route('rapprochement.index') }}">
                                    <i class="bi bi-bank"></i> Rapprochement
                                </a>
                            </li>
                            @endif
                            <li><hr class="dropdown-divider"></li>
                            @if (Route::has('parametres.comptes-bancaires.index'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('parametres.comptes-bancaires.*') ? 'active' : '' }}"
                                   href="{{ route('parametres.comptes-bancaires.index') }}">
                                    <i class="bi bi-credit-card"></i> Comptes bancaires
                                </a>
                            </li>
                            @endif
                        </ul>
                    </li>

                    {{-- Tiers --}}
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('tiers.*') ? 'active fw-semibold' : '' }}"
                           href="{{ route('tiers.index') }}">
                            <i class="bi bi-building-add"></i> Tiers
                        </a>
                    </li>

                    {{-- Liens directs --}}
                    @php
                        $navItems = [
                            ['route' => 'budget.index',   'icon' => 'piggy-bank',             'label' => 'Budget'],
                            ['route' => 'membres.index',  'icon' => 'people',                 'label' => 'Membres'],
                            ['route' => 'rapports.index', 'icon' => 'file-earmark-bar-graph', 'label' => 'Rapports'],
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
                        <a class="nav-link dropdown-toggle {{ (request()->routeIs('parametres.*') && !request()->routeIs('parametres.comptes-bancaires.*')) || request()->routeIs('operations.*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear"></i> Paramètres
                        </a>
                        <ul class="dropdown-menu">
                            @if (Route::has('parametres.association'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('parametres.association') ? 'active' : '' }}"
                                   href="{{ route('parametres.association') }}">
                                    <i class="bi bi-building"></i> Association
                                </a>
                            </li>
                            @endif
                            <li><hr class="dropdown-divider"></li>
                            @if (Route::has('parametres.categories.index'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('parametres.categories.*') ? 'active' : '' }}"
                                   href="{{ route('parametres.categories.index') }}">
                                    <i class="bi bi-tags"></i> Catégories
                                </a>
                            </li>
                            @endif
                            @if (Route::has('parametres.sous-categories.index'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('parametres.sous-categories.*') ? 'active' : '' }}"
                                   href="{{ route('parametres.sous-categories.index') }}">
                                    <i class="bi bi-tag"></i> Sous-catégories
                                </a>
                            </li>
                            @endif
                            @if (Route::has('operations.index'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('operations.*') ? 'active' : '' }}"
                                   href="{{ route('operations.index') }}">
                                    <i class="bi bi-calendar-event"></i> Opérations
                                </a>
                            </li>
                            @endif
                            <li><hr class="dropdown-divider"></li>
                            @if (Route::has('parametres.utilisateurs.index'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('parametres.utilisateurs.*') ? 'active' : '' }}"
                                   href="{{ route('parametres.utilisateurs.index') }}">
                                    <i class="bi bi-people"></i> Utilisateurs
                                </a>
                            </li>
                            @endif
                        </ul>
                    </li>
                </ul>

                <ul class="navbar-nav align-items-center gap-2">
                    <li class="nav-item dropdown">
                        <button class="badge rounded-pill dropdown-toggle border-0"
                                style="background-color: rgba(255,255,255,0.18); color:#fff; font-size:.8rem; font-weight:500; padding:.4em .85em; border: 1px solid rgba(255,255,255,0.35) !important; cursor:pointer;"
                                data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-calendar3"></i> Exercice {{ $exerciceLabel }}
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><h6 class="dropdown-header">Changer d'exercice</h6></li>
                            @foreach ($exercicesDispos as $annee)
                                <li>
                                    <form method="POST" action="{{ route('exercice.changer') }}">
                                        @csrf
                                        <input type="hidden" name="annee" value="{{ $annee }}">
                                        <button type="submit"
                                                class="dropdown-item {{ $annee === $exerciceActif ? 'active' : '' }}">
                                            <i class="bi bi-calendar3"></i>
                                            {{ $exerciceService->label($annee) }}
                                            @if ($annee === $exerciceActif)
                                                <i class="bi bi-check2 float-end mt-1"></i>
                                            @endif
                                        </button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    </li>
                    <li class="nav-item">
                        <div class="dropdown">
                            <button class="btn btn-user btn-sm dropdown-toggle" type="button"
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

    <div class="container-fluid px-4 pb-5 mb-3">
        <x-flash-message />
        {{ $slot }}
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    @livewireScripts
    <script>
        function initTooltips() {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
                bootstrap.Tooltip.getOrCreateInstance(el, { delay: { show: 0, hide: 100 } });
            });
        }
        document.addEventListener('DOMContentLoaded', initTooltips);
        document.addEventListener('livewire:updated', initTooltips);
    </script>

    <footer class="text-center small py-2" style="position:fixed;bottom:0;left:0;right:0;background-color:#722281;color:rgba(255,255,255,0.85);z-index:1030;">
        &copy; {{ config('version.year', date('Y')) }} Jürgen Kurz &middot; SVS Accounting &middot; {{ config('version.tag', 'dev') }} &middot; {{ config('version.date', '') }}
    </footer>
</body>
</html>
