@php
    $association   = \App\Models\Association::find(1);
    $nomAsso       = $association?->nom ?? 'Mon Association';
    $logoAsset     = ($association?->logo_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($association->logo_path))
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($association->logo_path)
        : asset('images/agora-gestion.svg');
    $exerciceService = app(\App\Services\ExerciceService::class);
    $exerciceActif   = $exerciceService->current();
    $exerciceLabel   = $exerciceService->label($exerciceActif);
    $exerciceModel   = $exerciceService->exerciceAffiche();
    $exerciceCloture = $exerciceModel?->isCloture() ?? false;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? $nomAsso.' '.($espaceLabel ?? 'Comptabilité') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    @include('partials.colors')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/fr.js"></script>
    <script>
    window.parseFlatpickrDate = function(str) {
        str = (str || '').trim();
        const y = new Date().getFullYear();
        function expandYear(yy) {
            yy = parseInt(yy, 10);
            // Pivot : si > année courante en 2 chiffres → 1900+, sinon 2000+
            return yy > (y % 100) ? 1900 + yy : 2000 + yy;
        }
        function make(d, m, yr) {
            d = parseInt(d, 10); m = parseInt(m, 10) - 1; yr = parseInt(yr, 10);
            const dt = new Date(yr, m, d);
            return (!isNaN(dt) && dt.getDate()===d && dt.getMonth()===m && dt.getFullYear()===yr) ? dt : null;
        }
        if (/^\d{8}$/.test(str)) return make(str.slice(0,2), str.slice(2,4), str.slice(4,8));
        if (/^\d{6}$/.test(str)) return make(str.slice(0,2), str.slice(2,4), expandYear(str.slice(4,6)));
        if (/^\d{4}$/.test(str))  return make(str.slice(0,2), str.slice(2,4), y);
        const p = str.split(/[\/\-\.]/);
        if (p.length === 2) return make(p[0], p[1], y);
        if (p.length === 3 && p[0].length === 4) return make(p[2], p[1], p[0]); // ISO : aaaa-mm-jj
        if (p.length === 3) return make(p[0], p[1], p[2].length <= 2 ? expandYear(p[2]) : p[2]);
        return null;
    };
    </script>
    @livewireStyles
    <style>
        .navbar-app {
            background: linear-gradient(160deg, color-mix(in srgb, {{ $espaceColor ?? '#722281' }}, white 15%) 0%, {{ $espaceColor ?? '#722281' }} 50%, color-mix(in srgb, {{ $espaceColor ?? '#722281' }}, black 20%) 100%);
            box-shadow: 0 2px 8px rgba(0,0,0,.18), inset 0 1px 0 rgba(255,255,255,.12);
        }
        .navbar-app .navbar-brand,
        .navbar-app .nav-link,
        .navbar-app .navbar-toggler {
            color: rgba(255, 255, 255, 0.9);
        }
        .navbar-app .nav-link:hover,
        .navbar-app .nav-link:focus {
            color: #fff;
            background-color: rgba(255, 255, 255, 0.12);
            border-radius: 6px;
        }
        .navbar-app .nav-link.active {
            color: #fff;
            background-color: rgba(255, 255, 255, 0.2);
            border-radius: 6px;
            font-weight: 600;
        }
        .navbar-app .navbar-toggler {
            border-color: rgba(255, 255, 255, 0.4);
        }
        .navbar-app .dropdown-menu {
            background-color: #fff;
            border: none;
            box-shadow: 0 4px 16px {{ ($espaceColor ?? '#722281') }}2e;
            border-radius: 8px;
        }
        .navbar-app .dropdown-item {
            color: #333;
        }
        .navbar-app .dropdown-item:hover,
        .navbar-app .dropdown-item:focus {
            background-color: {{ ($espaceColor ?? '#722281') }}18;
            color: {{ $espaceColor ?? '#722281' }};
        }
        .navbar-app .dropdown-item.active,
        .navbar-app .dropdown-item:active {
            background-color: {{ $espaceColor ?? '#722281' }};
            color: #fff;
        }
        .navbar-app .dropdown-divider {
            border-color: {{ ($espaceColor ?? '#722281') }}30;
        }
        .navbar-app .btn-user {
            background-color: rgba(255, 255, 255, 0.15);
            border-color: rgba(255, 255, 255, 0.4);
            color: #fff;
            border-radius: .75rem;
        }
        .navbar-app .btn-user:hover {
            background-color: rgba(255, 255, 255, 0.25);
            border-color: rgba(255, 255, 255, 0.7);
            color: #fff;
        }
    </style>
</head>
<body>
    @auth
    <nav class="navbar navbar-expand-lg navbar-app mb-4">
        <div class="container-fluid">
            <div class="navbar-brand d-flex align-items-center gap-2 mb-0">
                <a href="{{ route(($espace ?? \App\Enums\Espace::Compta)->value . '.dashboard') }}">
                    <img src="{{ $logoAsset }}" alt="{{ $nomAsso }}" height="45">
                </a>
                <span class="d-inline-block lh-sm">
                    <a class="d-block text-decoration-none" href="{{ route(($espace ?? \App\Enums\Espace::Compta)->value . '.dashboard') }}"
                       style="color: rgba(255,255,255,0.9);">{{ $nomAsso }}</a>
                    <x-espace-switcher />
                </span>
            </div>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                    data-bs-target="#navbarNav" aria-controls="navbarNav"
                    aria-expanded="false" aria-label="Ouvrir la navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse align-items-end" id="navbarNav">
                <ul class="navbar-nav me-auto align-items-end">

                    @if(($espace ?? null) === \App\Enums\Espace::Compta)
                    {{-- Dropdown Transactions --}}
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->routeIs('compta.transactions.*') || request()->routeIs('compta.dons.*') || request()->routeIs('compta.cotisations.*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-arrow-down-up"></i> Transactions
                        </a>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('compta.transactions.index') ? 'active' : '' }}"
                                   href="{{ route('compta.transactions.index') }}">
                                    <i class="bi bi-list-ul"></i> Recettes &amp; dépenses
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            @if (Route::has('compta.dons.index'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('compta.dons.*') ? 'active' : '' }}"
                                   href="{{ route('compta.dons.index') }}">
                                    <i class="bi bi-heart"></i> Dons
                                </a>
                            </li>
                            @endif
                            @if (Route::has('compta.cotisations.index'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('compta.cotisations.*') ? 'active' : '' }}"
                                   href="{{ route('compta.cotisations.index') }}">
                                    <i class="bi bi-person-check"></i> Cotisations
                                </a>
                            </li>
                            @endif
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('compta.transactions.all') ? 'active' : '' }}"
                                   href="{{ route('compta.transactions.all') }}">
                                    <i class="bi bi-collection"></i> Toutes les transactions
                                </a>
                            </li>
                        </ul>
                    </li>

                    {{-- Tiers --}}
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('compta.tiers.*') ? 'active fw-semibold' : '' }}"
                           href="{{ route('compta.tiers.index') }}">
                            <i class="bi bi-building-add"></i> Tiers
                        </a>
                    </li>

                    {{-- Factures --}}
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('compta.factures*') ? 'active' : '' }}"
                           href="{{ route('compta.factures') }}">
                            <i class="bi bi-receipt"></i> Factures
                        </a>
                    </li>

                    {{-- Dropdown Banques --}}
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->routeIs('compta.comptes-bancaires.*') || request()->routeIs('compta.rapprochement.*') || request()->routeIs('compta.virements.*') || request()->routeIs('compta.parametres.comptes-bancaires.*') || request()->routeIs('compta.helloasso-sync') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-bank2"></i> Banques
                        </a>
                        <ul class="dropdown-menu">
                            @if (Route::has('compta.rapprochement.index'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('compta.rapprochement.*') ? 'active' : '' }}"
                                   href="{{ route('compta.rapprochement.index') }}">
                                    <i class="bi bi-bank"></i> Rapprochement
                                </a>
                            </li>
                            @endif
                            @if (Route::has('compta.virements.index'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('compta.virements.*') ? 'active' : '' }}"
                                   href="{{ route('compta.virements.index') }}">
                                    <i class="bi bi-arrow-left-right"></i> Virements
                                </a>
                            </li>
                            @endif
                            <li><hr class="dropdown-divider"></li>
                            @if (Route::has('compta.helloasso-sync'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('compta.helloasso-sync') ? 'active' : '' }}"
                                   href="{{ route('compta.helloasso-sync') }}">
                                    <i class="bi bi-arrow-repeat"></i> Synchronisation HelloAsso
                                </a>
                            </li>
                            @endif
                            <li><hr class="dropdown-divider"></li>
                            @if (Route::has('compta.parametres.comptes-bancaires.index'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('compta.parametres.comptes-bancaires.*') ? 'active' : '' }}"
                                   href="{{ route('compta.parametres.comptes-bancaires.index') }}">
                                    <i class="bi bi-credit-card"></i> Comptes bancaires
                                </a>
                            </li>
                            @endif
                        </ul>
                    </li>

                    {{-- Budget --}}
                    @if (Route::has('compta.budget.index'))
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('compta.budget.*') ? 'active' : '' }}"
                               href="{{ route('compta.budget.index') }}">
                                <i class="bi bi-piggy-bank"></i> Budget
                            </a>
                        </li>
                    @endif

                    {{-- Dropdown Rapports --}}
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->routeIs('compta.rapports.*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-file-earmark-bar-graph"></i> Rapports
                        </a>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('compta.rapports.compte-resultat') ? 'active' : '' }}"
                                   href="{{ route('compta.rapports.compte-resultat') }}">
                                    <i class="bi bi-journal-text me-1"></i>Compte de résultat
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('compta.rapports.operations') ? 'active' : '' }}"
                                   href="{{ route('compta.rapports.operations') }}">
                                    <i class="bi bi-diagram-3 me-1"></i>Compte de résultat par opérations
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('compta.rapports.flux-tresorerie') ? 'active' : '' }}"
                                   href="{{ route('compta.rapports.flux-tresorerie') }}">
                                    <i class="bi bi-cash-stack me-1"></i>Flux de trésorerie
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('compta.rapports.analyse') ? 'active' : '' }}"
                                   href="{{ route('compta.rapports.analyse') }}">
                                    <i class="bi bi-graph-up me-1"></i>Analyse financière
                                </a>
                            </li>
                        </ul>
                    </li>

                    {{-- Dropdown Exercices --}}
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->routeIs('compta.exercices.*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-journal-check"></i> Exercices
                        </a>
                        <ul class="dropdown-menu">
                            @if ($exerciceCloture)
                                <li>
                                    <a class="dropdown-item text-danger {{ request()->routeIs('compta.exercices.reouvrir') ? 'active' : '' }}"
                                       href="{{ route('compta.exercices.reouvrir') }}">
                                        <i class="bi bi-unlock"></i> Réouvrir l'exercice
                                    </a>
                                </li>
                            @else
                                <li>
                                    <a class="dropdown-item {{ request()->routeIs('compta.exercices.cloture') ? 'active' : '' }}"
                                       href="{{ route('compta.exercices.cloture') }}">
                                        <i class="bi bi-lock"></i> Clôturer l'exercice
                                    </a>
                                </li>
                            @endif
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('compta.exercices.changer') ? 'active' : '' }}"
                                   href="{{ route('compta.exercices.changer') }}">
                                    <i class="bi bi-arrow-left-right"></i> Changer d'exercice
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('compta.exercices.audit') ? 'active' : '' }}"
                                   href="{{ route('compta.exercices.audit') }}">
                                    <i class="bi bi-clock-history"></i> Piste d'audit
                                </a>
                            </li>
                        </ul>
                    </li>
                    @endif

                    @if(($espace ?? null) === \App\Enums\Espace::Gestion)
                    {{-- Adhérents --}}
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('gestion.adherents') ? 'active' : '' }}"
                           href="{{ route('gestion.adherents') }}">
                            <i class="bi bi-people"></i> Adhérents
                        </a>
                    </li>

                    {{-- Dropdown Opérations --}}
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->routeIs('gestion.operations*') || request()->routeIs('gestion.remises-bancaires*') || request()->routeIs('gestion.analyse*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-calendar-event"></i> Opérations
                        </a>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('gestion.operations*') ? 'active' : '' }}"
                                   href="{{ route('gestion.operations') }}">
                                    <i class="bi bi-calendar-event"></i> Gestion des opérations
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('gestion.remises-bancaires*') ? 'active' : '' }}"
                                   href="{{ route('gestion.remises-bancaires') }}">
                                    <i class="bi bi-bank"></i> Remises en banque
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('gestion.analyse*') ? 'active' : '' }}"
                                   href="{{ route('gestion.analyse') }}">
                                    <i class="bi bi-graph-up"></i> Analyse
                                </a>
                            </li>
                        </ul>
                    </li>

                    {{-- Factures --}}
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('gestion.factures*') ? 'active' : '' }}"
                           href="{{ route('gestion.factures') }}">
                            <i class="bi bi-receipt"></i> Factures
                        </a>
                    </li>

                    {{-- Sync HelloAsso --}}
                    @if (Route::has('gestion.helloasso-sync'))
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('gestion.helloasso-sync') ? 'active' : '' }}"
                           href="{{ route('gestion.helloasso-sync') }}">
                            <i class="bi bi-arrow-repeat"></i> Sync HelloAsso
                        </a>
                    </li>
                    @endif
                    @endif

                </ul>

                {{-- Dropdown Paramètres (poussé à droite) --}}
                @php $espacePrefix = ($espace ?? \App\Enums\Espace::Compta)->value; @endphp
                <ul class="navbar-nav ms-auto me-3 align-items-end">
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs($espacePrefix.'.documents-en-attente') ? 'active' : '' }}"
                           href="{{ route($espacePrefix.'.documents-en-attente') }}">
                            <i class="bi bi-inbox"></i> Documents
                            @if(($incomingDocumentsCount ?? 0) > 0)
                                <span class="badge bg-warning text-dark">{{ $incomingDocumentsCount }}</span>
                            @endif
                        </a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ (request()->routeIs($espacePrefix . '.parametres.*') && !request()->routeIs($espacePrefix . '.parametres.comptes-bancaires.*')) || request()->routeIs($espacePrefix . '.operations.*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear"></i> Paramètres
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            @if (Route::has($espacePrefix . '.parametres.association'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs($espacePrefix . '.parametres.association') ? 'active' : '' }}"
                                   href="{{ route($espacePrefix . '.parametres.association') }}">
                                    <i class="bi bi-building"></i> Association
                                </a>
                            </li>
                            @endif
                            @if (Route::has($espacePrefix . '.parametres.helloasso'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs($espacePrefix . '.parametres.helloasso') ? 'active' : '' }}"
                                   href="{{ route($espacePrefix . '.parametres.helloasso') }}">
                                    <i class="bi bi-plug"></i> Connexion HelloAsso
                                </a>
                            </li>
                            @endif
                            <li><hr class="dropdown-divider"></li>
                            @if (Route::has($espacePrefix . '.parametres.categories.index'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs($espacePrefix . '.parametres.categories.*') ? 'active' : '' }}"
                                   href="{{ route($espacePrefix . '.parametres.categories.index') }}">
                                    <i class="bi bi-tags"></i> Catégories
                                </a>
                            </li>
                            @endif
                            @if (Route::has($espacePrefix . '.parametres.sous-categories.index'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs($espacePrefix . '.parametres.sous-categories.*') ? 'active' : '' }}"
                                   href="{{ route($espacePrefix . '.parametres.sous-categories.index') }}">
                                    <i class="bi bi-tag"></i> Sous-catégories
                                </a>
                            </li>
                            @endif
                            @if (Route::has($espacePrefix . '.parametres.type-operations.index'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs($espacePrefix . '.parametres.type-operations.*') ? 'active' : '' }}"
                                   href="{{ route($espacePrefix . '.parametres.type-operations.index') }}">
                                    <i class="bi bi-collection"></i> Types d'opération
                                </a>
                            </li>
                            @endif
                            @if(($espace ?? null) === \App\Enums\Espace::Compta)
                            @if (Route::has('compta.operations.index'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('compta.operations.*') ? 'active' : '' }}"
                                   href="{{ route('compta.operations.index') }}">
                                    <i class="bi bi-calendar-event"></i> Opérations
                                </a>
                            </li>
                            @endif
                            @endif
                            <li><hr class="dropdown-divider"></li>
                            @if (Route::has($espacePrefix . '.parametres.utilisateurs.index'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs($espacePrefix . '.parametres.utilisateurs.*') ? 'active' : '' }}"
                                   href="{{ route($espacePrefix . '.parametres.utilisateurs.index') }}">
                                    <i class="bi bi-people"></i> Utilisateurs
                                </a>
                            </li>
                            @endif
                        </ul>
                    </li>
                </ul>

                <ul class="navbar-nav flex-column align-items-stretch" style="min-width:0">
                    <li class="nav-item">
                        <div class="dropdown">
                            <button class="btn btn-user btn-sm dropdown-toggle w-100" type="button"
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
                    <li class="nav-item">
                        <span class="badge text-center w-100"
                              style="background-color: rgba(255,255,255,0.18); color:#fff; font-size:.75rem; font-weight:500; padding:.4em .75em; border: 1px solid rgba(255,255,255,0.35) !important; border-radius:.75rem;">
                            <i class="bi bi-{{ $exerciceCloture ? 'lock' : 'calendar3' }}"></i>
                            Exercice {{ $exerciceLabel }}
                        </span>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <livewire:helloasso-notification-banner />
    @endauth

    <div class="container-fluid px-4 pb-5 mb-3">
        <x-flash-message />
        {{ $slot }}
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    {{-- Formulaires modaux globaux --}}
    <livewire:tiers-form />
    <livewire:transaction-form />
    <livewire:virement-interne-form />
    <livewire:tiers-quick-view />
    @livewireScripts
    <script>
        function initTooltips() {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
                const existing = bootstrap.Tooltip.getInstance(el);
                if (existing) existing.dispose();
                new bootstrap.Tooltip(el, { delay: { show: 0, hide: 100 } });
            });
        }
        document.addEventListener('DOMContentLoaded', initTooltips);
        document.addEventListener('livewire:updated', initTooltips);
    </script>

    <script src="{{ asset('vendor/tinymce/tinymce.min.js') }}"></script>
    @stack('scripts')
    @php $footerBg = app()->environment('production') ? ($espaceColor ?? '#722281') : '#b45309'; @endphp
    <footer class="text-center small py-2" style="position:fixed;bottom:0;left:0;right:0;background-color:{{ $footerBg }};color:rgba(255,255,255,0.85);z-index:1030;">
        &copy; {{ config('version.year', date('Y')) }} Jürgen Kurz &middot; AgoraGestion &middot; {{ config('version.tag', app()->environment()) }} &middot; {{ config('version.date', '') }}
    </footer>
</body>
</html>
