<div>
    <div class="d-flex gap-3 align-items-center mb-3">
        <label class="fw-semibold text-muted text-nowrap">Opération :</label>
        <select class="form-select" wire:model.live="selectedOperationId">
            <option value="">— Sélectionner une opération —</option>
            @foreach($operations as $op)
                <option value="{{ $op->id }}">{{ $op->nom }} ({{ $op->date_debut?->format('d/m/Y') }} → {{ $op->date_fin?->format('d/m/Y') ?? '...' }})</option>
            @endforeach
        </select>
        @if($selectedOperation)
            <a class="btn btn-sm btn-outline-secondary text-nowrap" title="Modifier l'opération"
               href="{{ route('compta.operations.edit', $selectedOperation) }}?_redirect_back={{ urlencode(route('gestion.operations', ['id' => $selectedOperation->id])) }}">
                <i class="bi bi-pencil"></i>
            </a>
        @endif
        <a class="btn btn-sm btn-primary text-nowrap" title="Nouvelle opération"
           href="{{ route('compta.operations.create') }}">
            <i class="bi bi-plus-lg"></i>
        </a>
    </div>

    @if(!$selectedOperation)
    <div class="text-center text-muted py-5">
        <i class="bi bi-calendar-event" style="font-size:3rem;opacity:0.3"></i>
        <p class="mt-3">Sélectionnez une opération ci-dessus ou créez-en une nouvelle.</p>
    </div>
    @else
    <style>
        .nav-gestion .nav-link { color: #666; }
        .nav-gestion .nav-link:hover:not(.disabled) { color: #A9014F; }
        .nav-gestion .nav-link.active { color: #A9014F; font-weight: 600; border-color: #dee2e6 #dee2e6 #fff; }
        .nav-gestion .nav-link.disabled { color: #bbb; font-style: italic; }
    </style>
    <ul class="nav nav-tabs nav-gestion mb-3">
        <li class="nav-item">
            <button class="nav-link {{ $activeTab === 'details' ? 'active' : '' }}" wire:click="setTab('details')">Détails</button>
        </li>
        <li class="nav-item">
            <button class="nav-link {{ $activeTab === 'participants' ? 'active' : '' }}" wire:click="setTab('participants')">
                Participants ({{ $selectedOperation->participants()->count() }})
            </button>
        </li>
        @if(auth()->user()?->peut_voir_donnees_sensibles)
        <li class="nav-item">
            <button class="nav-link {{ $activeTab === 'seances' ? 'active' : '' }}" wire:click="setTab('seances')">Séances</button>
        </li>
        @endif
        <li class="nav-item">
            <button class="nav-link {{ $activeTab === 'compte_resultat' ? 'active' : '' }}" wire:click="setTab('compte_resultat')">Compte résultat</button>
        </li>
        <li class="nav-item">
            <button class="nav-link {{ $activeTab === 'compte_resultat_seances' ? 'active' : '' }}" wire:click="setTab('compte_resultat_seances')">Résultat par séances</button>
        </li>
        <li class="nav-item">
            <button class="nav-link disabled" disabled>Finances</button>
        </li>
    </ul>

    @if($activeTab === 'details')
    <div class="card">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">Nom</dt>
                <dd class="col-sm-9">{{ $selectedOperation->nom }}</dd>
                <dt class="col-sm-3">Description</dt>
                <dd class="col-sm-9">{{ $selectedOperation->description ?? '—' }}</dd>
                <dt class="col-sm-3">Date début</dt>
                <dd class="col-sm-9">{{ $selectedOperation->date_debut?->format('d/m/Y') ?? '—' }}</dd>
                <dt class="col-sm-3">Date fin</dt>
                <dd class="col-sm-9">{{ $selectedOperation->date_fin?->format('d/m/Y') ?? '—' }}</dd>
                <dt class="col-sm-3">Nombre de séances</dt>
                <dd class="col-sm-9">{{ $selectedOperation->nombre_seances ?? '—' }}</dd>
                <dt class="col-sm-3">Statut</dt>
                <dd class="col-sm-9">
                    <span class="badge {{ $selectedOperation->statut === \App\Enums\StatutOperation::EnCours ? 'bg-success' : 'bg-secondary' }}">
                        {{ $selectedOperation->statut->label() }}
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

    @if($activeTab === 'participants')
        <livewire:participant-table :operation="$selectedOperation" :key="'pt-'.$selectedOperation->id" />
    @endif

    @if($activeTab === 'seances')
        <livewire:seance-table :operation="$selectedOperation" :key="'st-'.$selectedOperation->id" />
    @endif

    @if($activeTab === 'compte_resultat')
        <livewire:rapport-compte-resultat-operations :selectedOperationIds="[$selectedOperation->id]" :key="'cr-'.$selectedOperation->id" />
    @endif

    @if($activeTab === 'compte_resultat_seances')
        <livewire:rapport-seances :selectedOperationIds="[$selectedOperation->id]" :key="'rs-'.$selectedOperation->id" />
    @endif
    @endif
</div>
