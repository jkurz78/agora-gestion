{{-- resources/views/livewire/tiers-list.blade.php --}}
<div>
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    {{-- Filtres + actions --}}
    <div class="d-flex gap-2 mb-3 align-items-center flex-wrap">
        <input type="text" wire:model.live.debounce.300ms="search"
               class="form-control form-control-sm" style="max-width:220px;"
               placeholder="Rechercher un tiers…">
        <select wire:model.live="filtre" class="form-select form-select-sm" style="max-width:200px;">
            <option value="">Tous les tiers</option>
            <option value="depenses">Utilisables en dépenses</option>
            <option value="recettes">Utilisables en recettes</option>
        </select>
        <div class="form-check mb-0">
            <input class="form-check-input" type="checkbox"
                   wire:model.live="filtreHelloasso" id="filtreHelloasso">
            <label class="form-check-label small" for="filtreHelloasso">HelloAsso</label>
        </div>
        @if($showActions)
            <div class="d-flex gap-2 ms-auto align-items-center">
                @if(auth()->user()->role->canWrite(\App\Enums\Espace::Compta) || auth()->user()->role->canWrite(\App\Enums\Espace::Gestion))
                    @livewire('import-csv-tiers')
                @endif
                <div class="dropdown">
                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-download"></i> Exporter
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="{{ route('compta.tiers.export', ['format' => 'xlsx']) }}"><i class="bi bi-file-earmark-excel me-1"></i> Excel (.xlsx)</a></li>
                        <li><a class="dropdown-item" href="{{ route('compta.tiers.export', ['format' => 'csv']) }}"><i class="bi bi-file-earmark-text me-1"></i> CSV</a></li>
                    </ul>
                </div>
                <button class="btn btn-sm btn-primary"
                        onclick="Livewire.dispatch('open-tiers-form', {prefill: {}})">
                    <i class="bi bi-plus-lg"></i> Nouveau tiers
                </button>
            </div>
        @endif
    </div>

    {{-- Tableau --}}
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle" style="font-size:.82rem;">
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    <th>
                        <a href="#" wire:click.prevent="sort('nom')" class="text-white text-decoration-none">
                            Nom
                            @if($sortBy === 'nom')
                                <i class="bi bi-arrow-{{ $sortDir === 'asc' ? 'up' : 'down' }}"></i>
                            @endif
                        </a>
                    </th>
                    <th>
                        <a href="#" wire:click.prevent="sort('email')" class="text-white text-decoration-none">
                            Email
                            @if($sortBy === 'email')
                                <i class="bi bi-arrow-{{ $sortDir === 'asc' ? 'up' : 'down' }}"></i>
                            @endif
                        </a>
                    </th>
                    <th>Téléphone</th>
                    <th>
                        <a href="#" wire:click.prevent="sort('ville')" class="text-white text-decoration-none">
                            Ville
                            @if($sortBy === 'ville')
                                <i class="bi bi-arrow-{{ $sortDir === 'asc' ? 'up' : 'down' }}"></i>
                            @endif
                        </a>
                    </th>
                    <th class="text-center">Dép.</th>
                    <th class="text-center">Rec.</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tiersList as $tiers)
                    <tr>
                        <td class="fw-semibold">
                            <span style="font-size:.65rem;">{{ $tiers->type === 'entreprise' ? '🏢' : '👤' }}</span>
                            {{ $tiers->displayName() }}
                            <x-tiers-info-icon :tiersId="$tiers->id" />
                            @if ($tiers->est_helloasso)
                                <span class="badge text-bg-info ms-1" style="font-size:.6rem" title="Synchronisé depuis HelloAsso">HA</span>
                            @endif
                            @if ($tiers->type === 'entreprise' && ($tiers->nom || $tiers->prenom))
                                <div class="text-muted small">{{ trim(($tiers->prenom ? $tiers->prenom . ' ' : '') . ($tiers->nom ?? '')) }}</div>
                            @endif
                        </td>
                        <td>{{ $tiers->email ?? '-' }}</td>
                        <td>{{ $tiers->telephone ?? '-' }}</td>
                        <td>{{ trim(($tiers->code_postal ? $tiers->code_postal . ' ' : '') . ($tiers->ville ?? '')) ?: '—' }}</td>
                        <td class="text-center">
                            @if ($tiers->pour_depenses)
                                <i class="bi bi-check-lg text-success"></i>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-center">
                            @if ($tiers->pour_recettes)
                                <i class="bi bi-check-lg text-success"></i>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <a href="{{ route('compta.tiers.transactions', $tiers->id) }}"
                               class="btn btn-sm btn-outline-secondary me-1"
                               title="Transactions">
                                <i class="bi bi-clock-history"></i>
                            </a>
                            <button
                                class="btn btn-sm btn-outline-primary me-1"
                                wire:click="requestEdit({{ $tiers->id }})"
                                title="Modifier"
                            ><i class="bi bi-pencil"></i></button>
                            <button
                                class="btn btn-sm btn-outline-danger"
                                wire:click="delete({{ $tiers->id }})"
                                wire:confirm="Supprimer ce tiers ?"
                                title="Supprimer"
                            ><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">Aucun tiers.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-per-page-selector :paginator="$tiersList" storageKey="tiers" wire:model.live="perPage" />
    {{ $tiersList->links() }}
</div>
