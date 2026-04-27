@php
    // $association injected by LayoutAssociationComposerProvider (CurrentAssociation::tryGet())
    $nomAsso       = $association?->nom ?? 'Mon Association';
    $logoFullPath  = $association?->brandingLogoFullPath();
    $logoAsset     = ($logoFullPath && \Illuminate\Support\Facades\Storage::disk('local')->exists($logoFullPath))
        ? \App\Support\TenantAsset::url($logoFullPath)
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

        /* Tooltip CSS-only instantané — bulle noire, pas de délai, pas de JS. */
        [data-tooltip] {
            position: relative;
        }
        [data-tooltip]:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: calc(100% + 6px);
            left: 50%;
            transform: translateX(-50%);
            background: #212529;
            color: #fff;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: .75rem;
            white-space: nowrap;
            z-index: 2100;
            pointer-events: none;
        }
        [data-tooltip]:hover::before {
            content: "";
            position: absolute;
            bottom: calc(100% + 2px);
            left: 50%;
            transform: translateX(-50%);
            border: 4px solid transparent;
            border-top-color: #212529;
            z-index: 2100;
            pointer-events: none;
        }
    </style>
</head>
<body>
    @include('partials.support-mode-banner')
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
                        <a class="nav-link dropdown-toggle {{ request()->routeIs('comptabilite.transactions*') || request()->routeIs('tiers.dons') || request()->routeIs('tiers.cotisations') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-arrow-down-up"></i> Transactions
                        </a>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('comptabilite.transactions') ? 'active' : '' }}"
                                   href="{{ route('comptabilite.transactions') }}">
                                    <i class="bi bi-list-ul"></i> Recettes &amp; dépenses
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            @if (Route::has('tiers.dons'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('tiers.dons') ? 'active' : '' }}"
                                   href="{{ route('tiers.dons') }}">
                                    <i class="bi bi-heart"></i> Dons
                                </a>
                            </li>
                            @endif
                            @if (Route::has('tiers.cotisations'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('tiers.cotisations') ? 'active' : '' }}"
                                   href="{{ route('tiers.cotisations') }}">
                                    <i class="bi bi-person-check"></i> Cotisations
                                </a>
                            </li>
                            @endif
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('comptabilite.transactions.all') ? 'active' : '' }}"
                                   href="{{ route('comptabilite.transactions.all') }}">
                                    <i class="bi bi-collection"></i> Toutes les transactions
                                </a>
                            </li>
                        </ul>
                    </li>

                    {{-- Tiers --}}
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('tiers.*') ? 'active fw-semibold' : '' }}"
                           href="{{ route('tiers.index') }}">
                            <i class="bi bi-building-add"></i> Tiers
                        </a>
                    </li>

                    {{-- Factures --}}
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('facturation.factures*') ? 'active' : '' }}"
                           href="{{ route('facturation.factures') }}">
                            <i class="bi bi-receipt"></i> Factures
                        </a>
                    </li>

                    {{-- Dropdown Banques --}}
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->routeIs('banques.comptes.*') || request()->routeIs('banques.rapprochement.*') || request()->routeIs('banques.virements.*') || request()->routeIs('banques.helloasso-sync') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-bank2"></i> Banques
                        </a>
                        <ul class="dropdown-menu">
                            @if (Route::has('banques.rapprochement.index'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('banques.rapprochement.*') ? 'active' : '' }}"
                                   href="{{ route('banques.rapprochement.index') }}">
                                    <i class="bi bi-bank"></i> Rapprochement
                                </a>
                            </li>
                            @endif
                            @if (Route::has('banques.virements.index'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('banques.virements.*') ? 'active' : '' }}"
                                   href="{{ route('banques.virements.index') }}">
                                    <i class="bi bi-arrow-left-right"></i> Virements
                                </a>
                            </li>
                            @endif
                            <li><hr class="dropdown-divider"></li>
                            @if (Route::has('banques.helloasso-sync'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('banques.helloasso-sync') ? 'active' : '' }}"
                                   href="{{ route('banques.helloasso-sync') }}">
                                    <i class="bi bi-arrow-repeat"></i> Synchronisation HelloAsso
                                </a>
                            </li>
                            @endif
                            <li><hr class="dropdown-divider"></li>
                            @if (Route::has('banques.comptes.index'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('banques.comptes.*') ? 'active' : '' }}"
                                   href="{{ route('banques.comptes.index') }}">
                                    <i class="bi bi-credit-card"></i> Comptes bancaires
                                </a>
                            </li>
                            @endif
                        </ul>
                    </li>

                    {{-- Notes de frais --}}
                    @if(($canSeeNdf ?? false) && Route::has('comptabilite.ndf.index'))
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('comptabilite.ndf.*') ? 'active' : '' }}"
                               href="{{ route('comptabilite.ndf.index') }}">
                                <i class="bi bi-receipt-cutoff"></i> Notes de frais
                                @if(($ndfPendingCount ?? 0) > 0)
                                    <span class="badge bg-warning text-dark">{{ $ndfPendingCount }}</span>
                                @endif
                            </a>
                        </li>
                    @endif

                    {{-- Budget --}}
                    @if (Route::has('comptabilite.budget'))
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('comptabilite.budget*') ? 'active' : '' }}"
                               href="{{ route('comptabilite.budget') }}">
                                <i class="bi bi-piggy-bank"></i> Budget
                            </a>
                        </li>
                    @endif

                    {{-- Dropdown Rapports --}}
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->routeIs('rapports.*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-file-earmark-bar-graph"></i> Rapports
                        </a>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('rapports.compte-resultat') ? 'active' : '' }}"
                                   href="{{ route('rapports.compte-resultat') }}">
                                    <i class="bi bi-journal-text me-1"></i>Compte de résultat
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('rapports.operations') ? 'active' : '' }}"
                                   href="{{ route('rapports.operations') }}">
                                    <i class="bi bi-diagram-3 me-1"></i>Compte de résultat par opérations
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('rapports.flux-tresorerie') ? 'active' : '' }}"
                                   href="{{ route('rapports.flux-tresorerie') }}">
                                    <i class="bi bi-cash-stack me-1"></i>Flux de trésorerie
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('rapports.analyse') ? 'active' : '' }}"
                                   href="{{ route('rapports.analyse') }}">
                                    <i class="bi bi-graph-up me-1"></i>Analyse financière
                                </a>
                            </li>
                        </ul>
                    </li>

                    {{-- Dropdown Exercices --}}
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->routeIs('exercices.*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-journal-check"></i> Exercices
                        </a>
                        <ul class="dropdown-menu">
                            @if ($exerciceCloture)
                                <li>
                                    <a class="dropdown-item text-danger {{ request()->routeIs('exercices.reouvrir') ? 'active' : '' }}"
                                       href="{{ route('exercices.reouvrir') }}">
                                        <i class="bi bi-unlock"></i> Réouvrir l'exercice
                                    </a>
                                </li>
                            @else
                                <li>
                                    <a class="dropdown-item {{ request()->routeIs('exercices.cloture') ? 'active' : '' }}"
                                       href="{{ route('exercices.cloture') }}">
                                        <i class="bi bi-lock"></i> Clôturer l'exercice
                                    </a>
                                </li>
                            @endif
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('exercices.changer') ? 'active' : '' }}"
                                   href="{{ route('exercices.changer') }}">
                                    <i class="bi bi-arrow-left-right"></i> Changer d'exercice
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('exercices.audit') ? 'active' : '' }}"
                                   href="{{ route('exercices.audit') }}">
                                    <i class="bi bi-clock-history"></i> Piste d'audit
                                </a>
                            </li>
                        </ul>
                    </li>
                    @endif

                    @if(($espace ?? null) === \App\Enums\Espace::Gestion)
                    {{-- Adhérents --}}
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('tiers.adherents') ? 'active' : '' }}"
                           href="{{ route('tiers.adherents') }}">
                            <i class="bi bi-people"></i> Adhérents
                        </a>
                    </li>

                    {{-- Dropdown Opérations --}}
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->routeIs('operations.*') || request()->routeIs('banques.remises*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-calendar-event"></i> Opérations
                        </a>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('operations.index', 'operations.show', 'operations.participants.*', 'operations.seances.*') ? 'active' : '' }}"
                                   href="{{ route('operations.index') }}">
                                    <i class="bi bi-calendar-event"></i> Gestion des opérations
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('banques.remises*') ? 'active' : '' }}"
                                   href="{{ route('banques.remises.index') }}">
                                    <i class="bi bi-bank"></i> Remises en banque
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('operations.analyse') ? 'active' : '' }}"
                                   href="{{ route('operations.analyse') }}">
                                    <i class="bi bi-graph-up"></i> Analyse
                                </a>
                            </li>
                        </ul>
                    </li>

                    {{-- Factures --}}
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('facturation.factures*') ? 'active' : '' }}"
                           href="{{ route('facturation.factures') }}">
                            <i class="bi bi-receipt"></i> Factures
                        </a>
                    </li>

                    {{-- Sync HelloAsso --}}
                    @if (Route::has('banques.helloasso-sync'))
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('banques.helloasso-sync') ? 'active' : '' }}"
                           href="{{ route('banques.helloasso-sync') }}">
                            <i class="bi bi-arrow-repeat"></i> Sync HelloAsso
                        </a>
                    </li>
                    @endif
                    @endif

                </ul>

                {{-- Dropdown Changer d'association --}}
                @auth
                @php
                    $currentAsso = \App\Tenant\TenantContext::current();
                    $userAssos = auth()->user()?->associations()->whereNull('association_user.revoked_at')->get() ?? collect();
                @endphp
                @if ($currentAsso && $userAssos->count() > 1)
                <ul class="navbar-nav me-2 align-items-end">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" data-bs-toggle="dropdown" role="button">
                            @if ($currentAsso->brandingLogoFullPath() && \Illuminate\Support\Facades\Storage::disk('local')->exists($currentAsso->brandingLogoFullPath()))
                                <img src="{{ \App\Support\TenantAsset::url($currentAsso->brandingLogoFullPath()) }}" style="height:24px;width:24px;object-fit:contain" alt="">
                            @endif
                            <span class="small">{{ $currentAsso->nom }}</span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><h6 class="dropdown-header">Changer d'association</h6></li>
                            @foreach ($userAssos as $asso)
                                @if ($asso->id !== $currentAsso->id)
                                    <li>
                                        <form method="POST" action="{{ route('switch-association') }}">
                                            @csrf
                                            <input type="hidden" name="association_id" value="{{ $asso->id }}">
                                            <button type="submit" class="dropdown-item">{{ $asso->nom }}</button>
                                        </form>
                                    </li>
                                @endif
                            @endforeach
                        </ul>
                    </li>
                </ul>
                @endif
                @endauth

                {{-- Dropdown Paramètres (poussé à droite) --}}
                <ul class="navbar-nav ms-auto me-3 align-items-end">
                    {{-- Boîte de réception (dropdown unifié) --}}
                    @php
                        $cumulCount = ($incomingDocumentsCount ?? 0)
                            + (($canSeeNdf ?? false) ? ($ndfPendingCount ?? 0) : 0)
                            + (($canSeeFacturesPartenaires ?? false) ? ($facturesPartenairesPendingCount ?? 0) : 0);
                        $hasVisibleSource = ($incomingDocumentsCount ?? 0) > 0
                            || (($canSeeNdf ?? false) && ($ndfPendingCount ?? 0) > 0)
                            || (($canSeeFacturesPartenaires ?? false) && ($facturesPartenairesPendingCount ?? 0) > 0);
                    @endphp
                    @if($hasVisibleSource)
                    <li class="nav-item dropdown" style="font-size:.8rem; --bs-dropdown-font-size:.8rem;">
                        <a class="nav-link dropdown-toggle d-flex align-items-center gap-1"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"
                           title="Boîte de réception : {{ $cumulCount }} pièce(s) en attente">
                            <i class="bi bi-inbox"></i>
                            <span class="badge bg-warning text-dark" style="font-size: .65rem;">{{ $cumulCount }}</span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            @if(($canSeeNdf ?? false) && ($ndfPendingCount ?? 0) > 0)
                                <li>
                                    <a class="dropdown-item d-flex align-items-center justify-content-between"
                                       href="{{ route('comptabilite.ndf.index') }}">
                                        <span><i class="bi bi-receipt-cutoff me-2"></i> Notes de frais</span>
                                        <span class="badge bg-warning text-dark ms-3">{{ $ndfPendingCount }}</span>
                                    </a>
                                </li>
                            @endif
                            @if(($canSeeFacturesPartenaires ?? false) && ($facturesPartenairesPendingCount ?? 0) > 0)
                                <li>
                                    <a class="dropdown-item d-flex align-items-center justify-content-between"
                                       href="{{ route('comptabilite.factures-fournisseurs.index') }}">
                                        <span><i class="bi bi-file-earmark-text me-2"></i> Factures fournisseurs</span>
                                        <span class="badge bg-warning text-dark ms-3">{{ $facturesPartenairesPendingCount }}</span>
                                    </a>
                                </li>
                            @endif
                            @if(($incomingDocumentsCount ?? 0) > 0)
                                <li>
                                    <a class="dropdown-item d-flex align-items-center justify-content-between"
                                       href="{{ route('facturation.documents-en-attente') }}">
                                        <span><i class="bi bi-envelope-paper me-2"></i> Documents reçus</span>
                                        <span class="badge bg-warning text-dark ms-3">{{ $incomingDocumentsCount }}</span>
                                    </a>
                                </li>
                            @endif
                        </ul>
                    </li>
                    @endif
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ request()->routeIs('parametres.*') || request()->routeIs('operations.*') ? 'active' : '' }}"
                           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear"></i> Paramètres
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            @if (Route::has('parametres.association'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('parametres.association') ? 'active' : '' }}"
                                   href="{{ route('parametres.association') }}">
                                    <i class="bi bi-building"></i> Association
                                </a>
                            </li>
                            @endif
                            @if (Route::has('parametres.helloasso'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('parametres.helloasso') ? 'active' : '' }}"
                                   href="{{ route('parametres.helloasso') }}">
                                    <i class="bi bi-plug"></i> Connexion HelloAsso
                                </a>
                            </li>
                            @endif
                            @if (Route::has('parametres.reception-documents'))
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('parametres.reception-documents') ? 'active' : '' }}"
                                   href="{{ route('parametres.reception-documents') }}">
                                    <i class="bi bi-envelope-arrow-down"></i> Réception de documents
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
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('operations.types-operation.*') ? 'active' : '' }}"
                                   href="{{ route('operations.types-operation.index') }}">
                                    <i class="bi bi-collection"></i> Types d'opération
                                </a>
                            </li>
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
    @include('partials.confirm-modal')
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
