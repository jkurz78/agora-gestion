{{-- resources/views/livewire/tiers-list.blade.php --}}
<div>
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    {{-- Filtres --}}
    <div class="row g-2 mb-3">
        <div class="col-md-6">
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                class="form-control"
                placeholder="Rechercher un tiers..."
            >
        </div>
        <div class="col-md-4">
            <select wire:model.live="filtre" class="form-select">
                <option value="">Tous les tiers</option>
                <option value="depenses">Utilisables en depenses</option>
                <option value="recettes">Utilisables en recettes</option>
            </select>
        </div>
    </div>

    {{-- Tableau --}}
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
            <thead>
                <tr class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <th>Nom</th>
                    <th>Type</th>
                    <th>Email</th>
                    <th>Telephone</th>
                    <th class="text-center">Dép.</th>
                    <th class="text-center">Rec.</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tiersList as $tiers)
                    <tr>
                        <td class="fw-semibold">{{ $tiers->displayName() }}</td>
                        <td>
                            <span class="badge bg-secondary">
                                {{ $tiers->type === 'entreprise' ? 'Entreprise' : 'Particulier' }}
                            </span>
                        </td>
                        <td>{{ $tiers->email ?? '-' }}</td>
                        <td>{{ $tiers->telephone ?? '-' }}</td>
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
                            <a href="{{ route('tiers.transactions', $tiers->id) }}"
                               class="btn btn-sm btn-outline-secondary me-1"
                               title="Transactions">
                                <i class="bi bi-clock-history"></i>
                            </a>
                            <button
                                class="btn btn-sm btn-outline-primary me-1"
                                wire:click="$dispatch('edit-tiers', { id: {{ $tiers->id }} })"
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
