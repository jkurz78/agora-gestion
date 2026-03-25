<div>
    <div class="d-flex gap-3 align-items-center mb-3">
        <label class="fw-semibold text-muted text-nowrap">Opération :</label>
        <select class="form-select" wire:model.live="selectedOperationId">
            <option value="">— Sélectionner une opération —</option>
            @foreach($operations as $op)
                <option value="{{ $op->id }}">{{ $op->nom }} ({{ $op->date_debut?->format('d/m/Y') }} → {{ $op->date_fin?->format('d/m/Y') ?? '...' }})</option>
            @endforeach
        </select>
        <button class="btn btn-sm btn-primary text-nowrap" title="Nouvelle opération"
                onclick="window.location='{{ route('compta.operations.create') }}'">
            <i class="bi bi-plus-lg"></i>
        </button>
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
        <li class="nav-item">
            <button class="nav-link disabled" disabled>Séances</button>
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
    @endif

    @if($activeTab === 'participants')
        <livewire:participant-table :operation="$selectedOperation" :key="'pt-'.$selectedOperation->id" />
    @endif
    @endif
</div>
