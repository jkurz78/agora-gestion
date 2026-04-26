<style>
.sidebar {
    width: 220px;
    background: #fff;
    border-right: 1px solid #e0e0e0;
    height: 100vh;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
}
.sidebar-brand {
    padding: 1rem;
    border-bottom: 1px solid #eee;
}
.sidebar-nav {
    flex: 1;
    overflow-y: auto;
    padding: .5rem 0;
}
.sidebar-footer {
    padding: .75rem 1rem;
    border-top: 1px solid #eee;
    font-size: .8rem;
}

/* Groupe headers */
.sidebar .accordion-button {
    padding: .6rem 1rem;
    font-size: .85rem;
    font-weight: 600;
    background: transparent;
    color: #333;
    box-shadow: none;
}
.sidebar .accordion-button:not(.collapsed) {
    color: #722281;
    background: rgba(114,34,129,.05);
}
/* Remplace le chevron Bootstrap par +/- */
.sidebar .accordion-button::after {
    content: '+';
    background-image: none !important;
    width: auto;
    height: auto;
    font-size: .9rem;
    font-weight: 700;
    color: #999;
    transform: none !important;
    transition: none;
}
.sidebar .accordion-button:not(.collapsed)::after {
    content: '\2212'; /* signe moins */
    color: #722281;
}

/* Items */
.sidebar .nav-item .nav-link {
    padding: .35rem 1rem .35rem 2.5rem;
    font-size: .82rem;
    color: #555;
    border-radius: 0;
}
.sidebar .nav-item .nav-link:hover {
    background: rgba(114,34,129,.06);
    color: #722281;
}
.sidebar .nav-item .nav-link.active {
    background: rgba(114,34,129,.1);
    color: #722281;
    font-weight: 600;
}

/* Sous-groupe collapsible "Boîte de réception" */
.sidebar .sidebar-inbox-toggle {
    padding: .3rem 1rem .3rem 2.5rem;
    font-size: .85rem;
    font-weight: 700;
    color: #555;
    text-decoration: none;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-radius: 0;
}
.sidebar .sidebar-inbox-toggle:hover {
    color: #722281;
    background: rgba(114,34,129,.04);
}
.sidebar .sidebar-inbox-toggle .inbox-chevron {
    font-size: .65rem;
    transition: transform .2s;
    color: #bbb;
}
.sidebar .sidebar-inbox-toggle[aria-expanded="false"] .inbox-chevron {
    transform: rotate(-90deg);
}
/* Items nested inside the inbox sub-group get extra indent */
.sidebar .inbox-nav .nav-link {
    padding-left: 3.25rem;
}
</style>

@props(['logoAsset', 'nomAsso', 'exerciceCloture', 'exerciceLabel', 'canSeeNdf' => false, 'ndfPendingCount' => 0, 'canSeeFacturesPartenaires' => false, 'facturesPartenairesPendingCount' => 0])

@php
$activeGroup = match(true) {
    request()->routeIs('comptabilite.transactions*', 'comptabilite.budget*', 'comptabilite.ndf.*') => 'comptabilite',
    request()->routeIs('banques.rapprochement.*', 'banques.virements.*', 'banques.helloasso-sync',
        'banques.comptes.*', 'banques.remises*') => 'banques',
    request()->routeIs('tiers.*') => 'tiers',
    request()->routeIs('operations.*') => 'operations',
    request()->routeIs('facturation.factures*', 'facturation.documents-en-attente*') => 'facturation',
    request()->routeIs('rapports.*') => 'rapports',
    request()->routeIs('exercices.*') => 'exercices',
    request()->routeIs('parametres.*') => 'parametres',
    default => 'comptabilite',
};
@endphp

<nav class="sidebar">

    {{-- Brand --}}
    <div class="sidebar-brand text-center">
        <a href="{{ route('dashboard') }}">
            <img src="{{ $logoAsset }}" alt="{{ $nomAsso }}" height="100" class="mb-1">
        </a>
        <div class="text-muted" style="font-size:.7rem;">{{ $nomAsso }}</div>
    </div>

    {{-- Navigation accordéon --}}
    <div class="sidebar-nav">
        <div class="accordion accordion-flush" id="sidebarAccordion">

            {{-- ─── COMPTABILITÉ ─── --}}
            <div class="accordion-item border-0">
                <h2 class="accordion-header">
                    <button class="accordion-button {{ $activeGroup === 'comptabilite' ? '' : 'collapsed' }}"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#grpComptabilite"
                            aria-expanded="{{ $activeGroup === 'comptabilite' ? 'true' : 'false' }}"
                            aria-controls="grpComptabilite">
                        <i class="bi bi-calculator me-2"></i> Comptabilité
                    </button>
                </h2>
                <div id="grpComptabilite"
                     class="accordion-collapse collapse {{ $activeGroup === 'comptabilite' ? 'show' : '' }}"
                     data-bs-parent="#sidebarAccordion">
                    <div class="accordion-body p-0">
                        <ul class="nav flex-column">

                            <li class="nav-item">
                                <a href="{{ route('comptabilite.transactions') }}"
                                   class="nav-link {{ request()->routeIs('comptabilite.transactions') ? 'active' : '' }}">
                                    <i class="bi bi-list-ul me-1"></i> Recettes &amp; dépenses
                                </a>
                            </li>

                            <li class="nav-item">
                                <a href="{{ route('comptabilite.transactions.all') }}"
                                   class="nav-link {{ request()->routeIs('comptabilite.transactions.all') ? 'active' : '' }}">
                                    <i class="bi bi-collection me-1"></i> Toutes les transactions
                                </a>
                            </li>

                            @if(($canSeeNdf && Route::has('comptabilite.ndf.index')) || ($canSeeFacturesPartenaires && Route::has('back-office.factures-partenaires.index')))
                            @php $inboxPendingTotal = ($ndfPendingCount ?? 0) + ($facturesPartenairesPendingCount ?? 0); @endphp
                            <li class="nav-item mt-2">
                                <a class="sidebar-inbox-toggle"
                                   data-bs-toggle="collapse"
                                   href="#sidebar-inbox-comptabilite"
                                   role="button"
                                   aria-expanded="true"
                                   aria-controls="sidebar-inbox-comptabilite">
                                    <span><i class="bi bi-inbox me-1"></i> Boîte de réception
                                        @if($inboxPendingTotal > 0)
                                            <span class="badge bg-warning text-dark ms-1">{{ $inboxPendingTotal }}</span>
                                        @endif
                                    </span>
                                    <i class="bi bi-chevron-down inbox-chevron"></i>
                                </a>
                                <div class="collapse show" id="sidebar-inbox-comptabilite">
                                    <ul class="nav flex-column inbox-nav">

                                        @if($canSeeNdf && Route::has('comptabilite.ndf.index'))
                                        <li class="nav-item">
                                            <a href="{{ route('comptabilite.ndf.index') }}"
                                               class="nav-link d-flex align-items-center justify-content-between
                                                      {{ request()->routeIs('comptabilite.ndf.*') ? 'active' : '' }}">
                                                <span><i class="bi bi-receipt-cutoff me-1"></i> Notes de frais</span>
                                                @if($ndfPendingCount > 0)
                                                    <span class="badge bg-warning text-dark ms-1">{{ $ndfPendingCount }}</span>
                                                @endif
                                            </a>
                                        </li>
                                        @endif

                                        @if($canSeeFacturesPartenaires && Route::has('back-office.factures-partenaires.index'))
                                        <li class="nav-item">
                                            <a href="{{ route('back-office.factures-partenaires.index') }}"
                                               class="nav-link d-flex align-items-center justify-content-between
                                                      {{ request()->routeIs('back-office.factures-partenaires.*') ? 'active' : '' }}">
                                                <span><i class="bi bi-file-earmark-text me-1"></i> Factures</span>
                                                @if($facturesPartenairesPendingCount > 0)
                                                    <span class="badge bg-warning text-dark ms-1">{{ $facturesPartenairesPendingCount }}</span>
                                                @endif
                                            </a>
                                        </li>
                                        @endif

                                    </ul>
                                </div>
                            </li>
                            @endif

                            @if (Route::has('comptabilite.budget'))
                            <li class="nav-item">
                                <a href="{{ route('comptabilite.budget') }}"
                                   class="nav-link {{ request()->routeIs('comptabilite.budget*') ? 'active' : '' }}">
                                    <i class="bi bi-piggy-bank me-1"></i> Budget
                                </a>
                            </li>
                            @endif

                        </ul>
                    </div>
                </div>
            </div>

            {{-- ─── BANQUES ─── --}}
            <div class="accordion-item border-0">
                <h2 class="accordion-header">
                    <button class="accordion-button {{ $activeGroup === 'banques' ? '' : 'collapsed' }}"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#grpBanques"
                            aria-expanded="{{ $activeGroup === 'banques' ? 'true' : 'false' }}"
                            aria-controls="grpBanques">
                        <i class="bi bi-bank2 me-2"></i> Banques
                    </button>
                </h2>
                <div id="grpBanques"
                     class="accordion-collapse collapse {{ $activeGroup === 'banques' ? 'show' : '' }}"
                     data-bs-parent="#sidebarAccordion">
                    <div class="accordion-body p-0">
                        <ul class="nav flex-column">

                            @if (Route::has('banques.comptes.index'))
                            <li class="nav-item">
                                <a href="{{ route('banques.comptes.index') }}"
                                   class="nav-link {{ request()->routeIs('banques.comptes.*') ? 'active' : '' }}">
                                    <i class="bi bi-credit-card me-1"></i> Comptes bancaires
                                </a>
                            </li>
                            @endif

                            @if (Route::has('banques.rapprochement.index'))
                            <li class="nav-item">
                                <a href="{{ route('banques.rapprochement.index') }}"
                                   class="nav-link {{ request()->routeIs('banques.rapprochement.*') ? 'active' : '' }}">
                                    <i class="bi bi-bank me-1"></i> Rapprochement
                                </a>
                            </li>
                            @endif

                            @if (Route::has('banques.virements.index'))
                            <li class="nav-item">
                                <a href="{{ route('banques.virements.index') }}"
                                   class="nav-link {{ request()->routeIs('banques.virements.*') ? 'active' : '' }}">
                                    <i class="bi bi-arrow-left-right me-1"></i> Virements
                                </a>
                            </li>
                            @endif

                            @if (Route::has('banques.remises.index'))
                            <li class="nav-item">
                                <a href="{{ route('banques.remises.index') }}"
                                   class="nav-link {{ request()->routeIs('banques.remises*') ? 'active' : '' }}">
                                    <i class="bi bi-cash-coin me-1"></i> Remises en banque
                                </a>
                            </li>
                            @endif

                            @if (Route::has('banques.helloasso-sync'))
                            <li class="nav-item">
                                <a href="{{ route('banques.helloasso-sync') }}"
                                   class="nav-link {{ request()->routeIs('banques.helloasso-sync') ? 'active' : '' }}">
                                    <i class="bi bi-arrow-repeat me-1"></i> Sync HelloAsso
                                </a>
                            </li>
                            @endif

                        </ul>
                    </div>
                </div>
            </div>

            {{-- ─── TIERS ─── --}}
            <div class="accordion-item border-0">
                <h2 class="accordion-header">
                    <button class="accordion-button {{ $activeGroup === 'tiers' ? '' : 'collapsed' }}"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#grpTiers"
                            aria-expanded="{{ $activeGroup === 'tiers' ? 'true' : 'false' }}"
                            aria-controls="grpTiers">
                        <i class="bi bi-people me-2"></i> Tiers
                    </button>
                </h2>
                <div id="grpTiers"
                     class="accordion-collapse collapse {{ $activeGroup === 'tiers' ? 'show' : '' }}"
                     data-bs-parent="#sidebarAccordion">
                    <div class="accordion-body p-0">
                        <ul class="nav flex-column">

                            <li class="nav-item">
                                <a href="{{ route('tiers.index') }}"
                                   class="nav-link {{ request()->routeIs('tiers.index', 'tiers.transactions', 'tiers.export', 'tiers.template.*') ? 'active' : '' }}">
                                    <i class="bi bi-building-add me-1"></i> Liste
                                </a>
                            </li>

                            <li class="nav-item">
                                <a href="{{ route('tiers.adherents') }}"
                                   class="nav-link {{ request()->routeIs('tiers.adherents') ? 'active' : '' }}">
                                    <i class="bi bi-person-badge me-1"></i> Adhérents
                                </a>
                            </li>

                            @if (Route::has('tiers.dons'))
                            <li class="nav-item">
                                <a href="{{ route('tiers.dons') }}"
                                   class="nav-link {{ request()->routeIs('tiers.dons') ? 'active' : '' }}">
                                    <i class="bi bi-heart me-1"></i> Dons
                                </a>
                            </li>
                            @endif

                            @if (Route::has('tiers.cotisations'))
                            <li class="nav-item">
                                <a href="{{ route('tiers.cotisations') }}"
                                   class="nav-link {{ request()->routeIs('tiers.cotisations') ? 'active' : '' }}">
                                    <i class="bi bi-person-check me-1"></i> Cotisations
                                </a>
                            </li>
                            @endif

                            @if (Route::has('tiers.communication'))
                            <li class="nav-item">
                                <a href="{{ route('tiers.communication') }}"
                                   class="nav-link {{ request()->routeIs('tiers.communication') ? 'active' : '' }}">
                                    <i class="bi bi-envelope me-1"></i> Communication
                                </a>
                            </li>
                            @endif

                        </ul>
                    </div>
                </div>
            </div>

            {{-- ─── OPÉRATIONS ─── --}}
            <div class="accordion-item border-0">
                <h2 class="accordion-header">
                    <button class="accordion-button {{ $activeGroup === 'operations' ? '' : 'collapsed' }}"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#grpOperations"
                            aria-expanded="{{ $activeGroup === 'operations' ? 'true' : 'false' }}"
                            aria-controls="grpOperations">
                        <i class="bi bi-calendar-event me-2"></i> Opérations
                    </button>
                </h2>
                <div id="grpOperations"
                     class="accordion-collapse collapse {{ $activeGroup === 'operations' ? 'show' : '' }}"
                     data-bs-parent="#sidebarAccordion">
                    <div class="accordion-body p-0">
                        <ul class="nav flex-column">

                            <li class="nav-item">
                                <a href="{{ route('operations.index') }}"
                                   class="nav-link {{ request()->routeIs('operations.index', 'operations.show', 'operations.participants.*', 'operations.seances.*') ? 'active' : '' }}">
                                    <i class="bi bi-calendar-event me-1"></i> Liste
                                </a>
                            </li>

                            <li class="nav-item">
                                <a href="{{ route('operations.types-operation.index') }}"
                                   class="nav-link {{ request()->routeIs('operations.types-operation.*') ? 'active' : '' }}">
                                    <i class="bi bi-collection me-1"></i> Types d'opération
                                </a>
                            </li>

                            <li class="nav-item">
                                <a href="{{ route('operations.analyse') }}"
                                   class="nav-link {{ request()->routeIs('operations.analyse') ? 'active' : '' }}">
                                    <i class="bi bi-graph-up me-1"></i> Analyse pivot
                                </a>
                            </li>

                        </ul>
                    </div>
                </div>
            </div>

            {{-- ─── FACTURATION ─── --}}
            <div class="accordion-item border-0">
                <h2 class="accordion-header">
                    <button class="accordion-button {{ $activeGroup === 'facturation' ? '' : 'collapsed' }}"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#grpFacturation"
                            aria-expanded="{{ $activeGroup === 'facturation' ? 'true' : 'false' }}"
                            aria-controls="grpFacturation">
                        <i class="bi bi-receipt me-2"></i> Facturation
                    </button>
                </h2>
                <div id="grpFacturation"
                     class="accordion-collapse collapse {{ $activeGroup === 'facturation' ? 'show' : '' }}"
                     data-bs-parent="#sidebarAccordion">
                    <div class="accordion-body p-0">
                        <ul class="nav flex-column">

                            <li class="nav-item">
                                <a href="{{ route('facturation.factures') }}"
                                   class="nav-link {{ request()->routeIs('facturation.factures*') ? 'active' : '' }}">
                                    <i class="bi bi-receipt me-1"></i> Factures
                                </a>
                            </li>

                            <li class="nav-item">
                                <a href="{{ route('facturation.documents-en-attente') }}"
                                   class="nav-link d-flex align-items-center justify-content-between
                                          {{ request()->routeIs('facturation.documents-en-attente*') ? 'active' : '' }}">
                                    <span><i class="bi bi-inbox me-1"></i> Documents en attente</span>
                                    @if(($incomingDocumentsCount ?? 0) > 0)
                                        <span class="badge bg-warning text-dark ms-1">{{ $incomingDocumentsCount }}</span>
                                    @endif
                                </a>
                            </li>

                        </ul>
                    </div>
                </div>
            </div>

            {{-- ─── RAPPORTS ─── --}}
            <div class="accordion-item border-0">
                <h2 class="accordion-header">
                    <button class="accordion-button {{ $activeGroup === 'rapports' ? '' : 'collapsed' }}"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#grpRapports"
                            aria-expanded="{{ $activeGroup === 'rapports' ? 'true' : 'false' }}"
                            aria-controls="grpRapports">
                        <i class="bi bi-file-earmark-bar-graph me-2"></i> Rapports
                    </button>
                </h2>
                <div id="grpRapports"
                     class="accordion-collapse collapse {{ $activeGroup === 'rapports' ? 'show' : '' }}"
                     data-bs-parent="#sidebarAccordion">
                    <div class="accordion-body p-0">
                        <ul class="nav flex-column">

                            <li class="nav-item">
                                <a href="{{ route('rapports.compte-resultat') }}"
                                   class="nav-link {{ request()->routeIs('rapports.compte-resultat') ? 'active' : '' }}">
                                    <i class="bi bi-journal-text me-1"></i> Compte de résultat
                                </a>
                            </li>

                            <li class="nav-item">
                                <a href="{{ route('rapports.operations') }}"
                                   class="nav-link {{ request()->routeIs('rapports.operations') ? 'active' : '' }}">
                                    <i class="bi bi-diagram-3 me-1"></i> CR par opérations
                                </a>
                            </li>

                            <li class="nav-item">
                                <a href="{{ route('rapports.flux-tresorerie') }}"
                                   class="nav-link {{ request()->routeIs('rapports.flux-tresorerie') ? 'active' : '' }}">
                                    <i class="bi bi-cash-stack me-1"></i> Flux de trésorerie
                                </a>
                            </li>

                            <li class="nav-item">
                                <a href="{{ route('rapports.analyse') }}"
                                   class="nav-link {{ request()->routeIs('rapports.analyse') ? 'active' : '' }}">
                                    <i class="bi bi-graph-up me-1"></i> Analyse financière
                                </a>
                            </li>

                        </ul>
                    </div>
                </div>
            </div>

            {{-- ─── EXERCICES ─── --}}
            <div class="accordion-item border-0">
                <h2 class="accordion-header">
                    <button class="accordion-button {{ $activeGroup === 'exercices' ? '' : 'collapsed' }}"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#grpExercices"
                            aria-expanded="{{ $activeGroup === 'exercices' ? 'true' : 'false' }}"
                            aria-controls="grpExercices">
                        <i class="bi bi-journal-check me-2"></i> Exercices
                    </button>
                </h2>
                <div id="grpExercices"
                     class="accordion-collapse collapse {{ $activeGroup === 'exercices' ? 'show' : '' }}"
                     data-bs-parent="#sidebarAccordion">
                    <div class="accordion-body p-0">
                        <ul class="nav flex-column">

                            @if ($exerciceCloture)
                            <li class="nav-item">
                                <a href="{{ route('exercices.reouvrir') }}"
                                   class="nav-link text-danger {{ request()->routeIs('exercices.reouvrir') ? 'active' : '' }}">
                                    <i class="bi bi-unlock me-1"></i> Réouvrir l'exercice
                                </a>
                            </li>
                            @else
                            <li class="nav-item">
                                <a href="{{ route('exercices.cloture') }}"
                                   class="nav-link {{ request()->routeIs('exercices.cloture') ? 'active' : '' }}">
                                    <i class="bi bi-lock me-1"></i> Clôturer l'exercice
                                </a>
                            </li>
                            @endif

                            <li class="nav-item">
                                <a href="{{ route('exercices.provisions') }}"
                                   class="nav-link {{ request()->routeIs('exercices.provisions') ? 'active' : '' }}">
                                    <i class="bi bi-journal-arrow-down me-1"></i> Écritures de provisions
                                </a>
                            </li>

                            <li class="nav-item">
                                <a href="{{ route('exercices.changer') }}"
                                   class="nav-link {{ request()->routeIs('exercices.changer') ? 'active' : '' }}">
                                    <i class="bi bi-arrow-left-right me-1"></i> Changer d'exercice
                                </a>
                            </li>

                            <li class="nav-item">
                                <a href="{{ route('exercices.audit') }}"
                                   class="nav-link {{ request()->routeIs('exercices.audit') ? 'active' : '' }}">
                                    <i class="bi bi-clock-history me-1"></i> Piste d'audit
                                </a>
                            </li>

                        </ul>
                    </div>
                </div>
            </div>

            {{-- ─── SÉPARATEUR ─── --}}
            <hr class="my-1 mx-3">

            {{-- ─── PARAMÈTRES ─── --}}
            @auth
            @if(auth()->user()->currentRoleEnum()?->canAccessParametres())
            <div class="accordion-item border-0">
                <h2 class="accordion-header">
                    <button class="accordion-button {{ $activeGroup === 'parametres' ? '' : 'collapsed' }}"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#grpParametres"
                            aria-expanded="{{ $activeGroup === 'parametres' ? 'true' : 'false' }}"
                            aria-controls="grpParametres">
                        <i class="bi bi-gear me-2"></i> Paramètres
                    </button>
                </h2>
                <div id="grpParametres"
                     class="accordion-collapse collapse {{ $activeGroup === 'parametres' ? 'show' : '' }}"
                     data-bs-parent="#sidebarAccordion">
                    <div class="accordion-body p-0">
                        <ul class="nav flex-column">

                            @if (Route::has('parametres.association'))
                            <li class="nav-item">
                                <a href="{{ route('parametres.association') }}"
                                   class="nav-link {{ request()->routeIs('parametres.association') ? 'active' : '' }}">
                                    <i class="bi bi-building me-1"></i> Association
                                </a>
                            </li>
                            @endif

                            @if (Route::has('parametres.reception-documents'))
                            <li class="nav-item">
                                <a href="{{ route('parametres.reception-documents') }}"
                                   class="nav-link {{ request()->routeIs('parametres.reception-documents') ? 'active' : '' }}">
                                    <i class="bi bi-envelope-arrow-down me-1"></i> Réception documents
                                </a>
                            </li>
                            @endif

                            @if (Route::has('parametres.smtp'))
                            <li class="nav-item">
                                <a href="{{ route('parametres.smtp') }}"
                                   class="nav-link {{ request()->routeIs('parametres.smtp') ? 'active' : '' }}">
                                    <i class="bi bi-send me-1"></i> Serveur d'envoi
                                </a>
                            </li>
                            @endif

                            @if (Route::has('parametres.helloasso'))
                            <li class="nav-item">
                                <a href="{{ route('parametres.helloasso') }}"
                                   class="nav-link {{ request()->routeIs('parametres.helloasso') ? 'active' : '' }}">
                                    <i class="bi bi-plug me-1"></i> Connexion HelloAsso
                                </a>
                            </li>
                            @endif

                            @if (Route::has('parametres.categories.index'))
                            <li class="nav-item">
                                <a href="{{ route('parametres.categories.index') }}"
                                   class="nav-link {{ request()->routeIs('parametres.categories.*') ? 'active' : '' }}">
                                    <i class="bi bi-tags me-1"></i> Catégories
                                </a>
                            </li>
                            @endif

                            @if (Route::has('parametres.sous-categories.index'))
                            <li class="nav-item">
                                <a href="{{ route('parametres.sous-categories.index') }}"
                                   class="nav-link {{ request()->routeIs('parametres.sous-categories.*') ? 'active' : '' }}">
                                    <i class="bi bi-tag me-1"></i> Sous-catégories
                                </a>
                            </li>
                            @endif

                            @if (Route::has('parametres.comptabilite.usages'))
                            <li class="nav-item">
                                <a href="{{ route('parametres.comptabilite.usages') }}"
                                   class="nav-link {{ request()->routeIs('parametres.comptabilite.usages') ? 'active' : '' }}">
                                    <i class="bi bi-sliders me-2"></i> Comptabilité
                                </a>
                            </li>
                            @endif

                            @if (Route::has('parametres.utilisateurs.index'))
                            <li class="nav-item">
                                <a href="{{ route('parametres.utilisateurs.index') }}"
                                   class="nav-link {{ request()->routeIs('parametres.utilisateurs.*') ? 'active' : '' }}">
                                    <i class="bi bi-people me-1"></i> Utilisateurs
                                </a>
                            </li>
                            @endif

                        </ul>
                    </div>
                </div>
            </div>
            @endif
            @endauth

        </div>{{-- /accordion --}}
    </div>{{-- /sidebar-nav --}}

    {{-- Footer --}}
    <div class="sidebar-footer text-center">
        <div class="text-muted" style="font-size:.65rem;">
            &copy; {{ config('version.year', date('Y')) }} AgoraGestion &middot; {{ config('version.tag', '') }}
        </div>
        <img src="{{ asset('images/agora-gestion.svg') }}" alt="AgoraGestion" height="45" class="mt-1">
    </div>

</nav>
