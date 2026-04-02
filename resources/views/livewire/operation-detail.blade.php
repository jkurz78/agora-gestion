<div>
    {{-- Zone haute : breadcrumb + onglets (fond gris) --}}
    <style>
        .nav-gestion .nav-link { color: #666; background: transparent; border: 1px solid transparent; font-size: 13px; padding: 6px 12px; }
        .nav-gestion .nav-link:hover:not(.disabled) { color: #A9014F; }
        .nav-gestion .nav-link.active { color: #A9014F; font-weight: 600; background: #fff; border-color: #dee2e6 #dee2e6 #fff; }
        .nav-gestion .nav-link.disabled { color: #bbb; font-style: italic; }
    </style>
    <div style="background: #eef0f3; margin: -1rem -1rem 0; padding: 1rem 1rem 0;">
        <x-operation-breadcrumb :operation="$operation" :operationMeta="$operationMeta">
            <a class="btn btn-sm btn-outline-secondary" title="Modifier l'opération"
               href="{{ route('compta.operations.edit', $operation) }}?_redirect_back={{ urlencode(route('gestion.operations.show', $operation)) }}">
                <i class="bi bi-gear"></i>
            </a>
        </x-operation-breadcrumb>

        <ul class="nav nav-tabs nav-gestion mb-0" style="border-bottom: none;">
        <li class="nav-item">
            <button class="nav-link {{ $activeTab === 'details' ? 'active' : '' }}" wire:click="setTab('details')">
                <i class="bi bi-info-circle me-1"></i>Infos
            </button>
        </li>
        <li class="nav-item d-flex align-items-end" style="padding:0 4px">
            <span style="border-left:1px solid #ccc;height:20px;display:inline-block;margin-bottom:8px"></span>
        </li>
        <li class="nav-item">
            <button class="nav-link {{ $activeTab === 'participants' ? 'active' : '' }}" wire:click="setTab('participants')">
                <i class="bi bi-people me-1"></i>Participants ({{ $participantsCount }})
            </button>
        </li>
        @if(auth()->user()?->peut_voir_donnees_sensibles)
        <li class="nav-item">
            <button class="nav-link {{ $activeTab === 'seances' ? 'active' : '' }}" wire:click="setTab('seances')">
                <i class="bi bi-calendar-week me-1"></i>Séances
            </button>
        </li>
        @endif
        <li class="nav-item">
            <button class="nav-link {{ $activeTab === 'reglements' ? 'active' : '' }}" wire:click="setTab('reglements')">
                <i class="bi bi-wallet2 me-1"></i>Règlements
            </button>
        </li>
        <li class="nav-item d-flex align-items-end" style="padding:0 4px">
            <span style="border-left:1px solid #ccc;height:20px;display:inline-block;margin-bottom:8px"></span>
        </li>
        <li class="nav-item">
            <button class="nav-link {{ $activeTab === 'compte_resultat' ? 'active' : '' }}" wire:click="setTab('compte_resultat')">
                <i class="bi bi-bar-chart-line me-1"></i>Compte résultat
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link {{ $activeTab === 'compte_resultat_seances' ? 'active' : '' }}" wire:click="setTab('compte_resultat_seances')">
                <i class="bi bi-grid-3x3-gap me-1"></i>Résultat par séances
            </button>
        </li>
    </ul>
    </div>

    {{-- Tab content --}}
    @if($activeTab === 'participants')
        <livewire:participant-table :operation="$operation" :key="'pt-'.$operation->id" />
    @endif

    @if($activeTab === 'seances')
        <livewire:seance-table :operation="$operation" :key="'st-'.$operation->id" />
    @endif

    @if($activeTab === 'reglements')
        <livewire:reglement-table :operation="$operation" :key="'rt-'.$operation->id" />
    @endif

    @if($activeTab === 'details')
    <div class="card">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">Nom</dt>
                <dd class="col-sm-9">{{ $operation->nom }}</dd>
                <dt class="col-sm-3">Description</dt>
                <dd class="col-sm-9">{{ $operation->description ?? '—' }}</dd>
                <dt class="col-sm-3">Date début</dt>
                <dd class="col-sm-9">{{ $operation->date_debut?->format('d/m/Y') ?? '—' }}</dd>
                <dt class="col-sm-3">Date fin</dt>
                <dd class="col-sm-9">{{ $operation->date_fin?->format('d/m/Y') ?? '—' }}</dd>
                <dt class="col-sm-3">Nombre de séances</dt>
                <dd class="col-sm-9">{{ $operation->nombre_seances ?? '—' }}</dd>
                <dt class="col-sm-3">Statut</dt>
                <dd class="col-sm-9">
                    <span class="badge {{ $operation->statut === \App\Enums\StatutOperation::EnCours ? 'bg-success' : 'bg-secondary' }}">
                        {{ $operation->statut->label() }}
                    </span>
                </dd>
            </dl>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-header">
            <h6 class="mb-0">Bilan financier</h6>
        </div>
        <div class="card-body">
            <table class="table table-sm mb-0">
                <tbody>
                    <tr>
                        <td>Total dépenses</td>
                        <td class="text-end text-danger fw-bold">{{ number_format($totalDepenses, 2, ',', ' ') }} &euro;</td>
                    </tr>
                    <tr>
                        <td>Total recettes</td>
                        <td class="text-end text-success fw-bold">{{ number_format($totalRecettes, 2, ',', ' ') }} &euro;</td>
                    </tr>
                    <tr>
                        <td>Total dons</td>
                        <td class="text-end text-success fw-bold">{{ number_format($totalDons, 2, ',', ' ') }} &euro;</td>
                    </tr>
                    <tr class="table-active">
                        <td class="fw-bold">Solde</td>
                        <td class="text-end fw-bold {{ $solde >= 0 ? 'text-success' : 'text-danger' }}">{{ number_format($solde, 2, ',', ' ') }} &euro;</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    @endif

    @if($activeTab === 'compte_resultat')
        <livewire:rapport-compte-resultat-operations :selectedOperationIds="[$operation->id]" :key="'cr-'.$operation->id" />
    @endif

    @if($activeTab === 'compte_resultat_seances')
        <livewire:rapport-seances :selectedOperationIds="[$operation->id]" :key="'rs-'.$operation->id" />
    @endif
</div>
