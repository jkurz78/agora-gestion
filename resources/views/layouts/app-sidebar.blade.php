@php
    // $association injected by LayoutAssociationComposerProvider (CurrentAssociation::tryGet())
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
    <title>{{ $title ?? $nomAsso }}</title>
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
            // Pivot : si > annee courante en 2 chiffres -> 1900+, sinon 2000+
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
        /* Sidebar visible en permanence >= lg */
        @media (min-width: 992px) {
            #sidebarContainer {
                display: block !important;
                position: fixed !important;
                top: 0;
                left: 0;
                width: 220px !important;
                height: 100vh;
                z-index: 1040;
            }
        }

        /* Sur mobile, le main-content prend toute la largeur */
        @media (max-width: 991.98px) {
            .main-content {
                margin-left: 0 !important;
            }
        }

        /* Header leger */
        .main-content > header {
            z-index: 1020;
        }
    </style>
</head>
<body>
    @auth
    <div class="d-flex">
        {{-- Sidebar : composant separe, offcanvas-lg pour responsive --}}
        <div id="sidebarContainer" class="offcanvas-lg offcanvas-start" tabindex="-1" style="width: 220px;">
            {{-- Bouton fermer visible seulement sur mobile --}}
            <div class="offcanvas-header d-lg-none py-2 px-3 border-bottom">
                <span class="fw-semibold small">Menu</span>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#sidebarContainer"></button>
            </div>
            <div class="offcanvas-body p-0">
                <x-sidebar
                    :logo-asset="$logoAsset"
                    :nom-asso="$nomAsso"
                    :exercice-cloture="$exerciceCloture"
                    :exercice-label="$exerciceLabel"
                />
            </div>
        </div>

        {{-- Contenu principal --}}
        <div class="main-content flex-grow-1" style="margin-left: 220px; min-height: 100vh;">
            {{-- Bandeau haut --}}
            <header class="sticky-top d-flex align-items-center px-3 gap-3"
                    style="height: 40px; background: linear-gradient(135deg, #722281 0%, #5a1b66 100%); color: rgba(255,255,255,.9); font-size: .8rem; z-index: 1020;">

                {{-- Burger mobile --}}
                <button class="btn btn-sm d-lg-none" type="button"
                        data-bs-toggle="offcanvas" data-bs-target="#sidebarContainer"
                        style="color: rgba(255,255,255,.85); border-color: rgba(255,255,255,.3);">
                    <i class="bi bi-list"></i>
                </button>

                {{-- Breadcrumb --}}
                @php
                    $breadcrumbGroup = match(true) {
                        request()->routeIs('comptabilite.transactions*', 'comptabilite.budget*') => 'Comptabilité',
                        request()->routeIs('banques.rapprochement.*', 'banques.virements.*', 'banques.helloasso-sync',
                            'banques.comptes.*', 'banques.remises*') => 'Banques',
                        request()->routeIs('tiers.*') => 'Tiers',
                        request()->routeIs('operations.*') => 'Opérations',
                        request()->routeIs('facturation.factures*', 'facturation.documents-en-attente*') => 'Facturation',
                        request()->routeIs('rapports.*') => 'Rapports',
                        request()->routeIs('exercices.*') => 'Exercices',
                        request()->routeIs('parametres.*') => 'Paramètres',
                        default => null,
                    };
                    $breadcrumbPage = trim(str_replace(['—', '|'], '', $title ?? ''));
                @endphp
                <nav aria-label="breadcrumb" class="mb-0 d-none d-md-block">
                    <ol class="breadcrumb mb-0" style="font-size:.8rem; --bs-breadcrumb-divider-color: rgba(255,255,255,.5); --bs-breadcrumb-item-active-color: rgba(255,255,255,.9);">
                        @if($breadcrumbGroup)
                            <li class="breadcrumb-item">
                                <span style="color: rgba(255,255,255,.6);">{{ $breadcrumbGroup }}</span>
                            </li>
                        @endif
                        @if(isset($breadcrumbGrandParent))
                            <li class="breadcrumb-item">
                                <a href="{{ $breadcrumbGrandParent->attributes['url'] }}" style="color: rgba(255,255,255,.6); text-decoration:none;">{{ $breadcrumbGrandParent }}</a>
                            </li>
                        @endif
                        @if(isset($breadcrumbParent))
                            <li class="breadcrumb-item">
                                <a href="{{ $breadcrumbParent->attributes['url'] }}" style="color: rgba(255,255,255,.6); text-decoration:none;">{{ $breadcrumbParent }}</a>
                            </li>
                        @endif
                        @if($breadcrumbPage)
                            <li class="breadcrumb-item active" aria-current="page" style="font-size:.88rem; font-weight:600;">{{ $breadcrumbPage }}</li>
                        @endif
                    </ol>
                </nav>

                {{-- Partie droite --}}
                <div class="d-flex align-items-center gap-3 ms-auto">

                    {{-- Documents en attente --}}
                    @if(($incomingDocumentsCount ?? 0) > 0)
                        <a href="{{ route('facturation.documents-en-attente') }}"
                           class="text-decoration-none d-flex align-items-center gap-1"
                           style="color: rgba(255,255,255,.9);"
                           title="{{ $incomingDocumentsCount }} document(s) en attente">
                            <i class="bi bi-inbox"></i>
                            <span class="badge bg-warning text-dark" style="font-size: .65rem;">{{ $incomingDocumentsCount }}</span>
                        </a>
                    @endif

                    {{-- Exercice --}}
                    <span class="d-none d-sm-flex align-items-center gap-1">
                        <i class="bi bi-{{ $exerciceCloture ? 'lock-fill' : 'calendar3' }}"></i>
                        Ex. {{ $exerciceLabel }}
                        @if ($exerciceCloture)
                            <span class="badge bg-warning text-dark" style="font-size: .65rem;">Cloture</span>
                        @endif
                    </span>

                    {{-- Dropdown Changer d'association --}}
                    @php
                        $currentAsso = \App\Tenant\TenantContext::current();
                        $userAssos = auth()->user()?->associations()->whereNull('association_user.revoked_at')->get() ?? collect();
                    @endphp
                    @if ($currentAsso && $userAssos->count() > 1)
                    <div class="dropdown">
                        <a href="#" class="text-decoration-none dropdown-toggle d-flex align-items-center gap-1"
                           role="button" data-bs-toggle="dropdown" aria-expanded="false"
                           style="color: rgba(255,255,255,.9);">
                            @if ($currentAsso->logo_path)
                                <img src="{{ Storage::url($currentAsso->logo_path) }}" style="height:20px;width:20px;object-fit:contain" alt="">
                            @else
                                <i class="bi bi-building"></i>
                            @endif
                            <span class="d-none d-md-inline small">{{ $currentAsso->nom }}</span>
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
                    </div>
                    @endif

                    {{-- Separateur --}}
                    <span style="border-left: 1px solid rgba(255,255,255,.25); height: 20px;"></span>

                    {{-- Utilisateur --}}
                    <div class="dropdown">
                        <a href="#" class="text-decoration-none dropdown-toggle d-flex align-items-center gap-1"
                           role="button" data-bs-toggle="dropdown" aria-expanded="false"
                           style="color: rgba(255,255,255,.9);">
                            <i class="bi bi-person-circle"></i>
                            <span class="d-none d-md-inline">{{ auth()->user()->nom }}</span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <a class="dropdown-item" href="{{ route('profil.index') }}">
                                    <i class="bi bi-person me-1"></i> Mon profil
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="dropdown-item text-danger">
                                        <i class="bi bi-box-arrow-right me-1"></i> Deconnexion
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </div>
                </div>
            </header>

            {{-- Bandeau HelloAsso (sous le header, pleine largeur) --}}
            <livewire:helloasso-notification-banner />

            {{-- Contenu --}}
            <div class="container-fluid px-4 py-3 pb-5">
                <x-flash-message />
                {{ $slot }}
            </div>
        </div>
    </div>
    @endauth

    @guest
    <div class="container-fluid px-4 pb-5 mb-3">
        {{ $slot }}
    </div>
    @endguest

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
</body>
</html>
