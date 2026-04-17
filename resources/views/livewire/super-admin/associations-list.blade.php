<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">Associations</h2>
        <a href="{{ route('super-admin.associations.create') }}" class="btn btn-primary">+ Nouvelle asso</a>
    </div>

    <div class="mb-3">
        <input type="text" wire:model.live.debounce.300ms="search" class="form-control" placeholder="Recherche nom ou slug…">
    </div>

    <table class="table table-hover">
        <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
            <tr>
                <th>Nom</th>
                <th>Slug</th>
                <th>Statut</th>
                <th>Utilisateurs</th>
                <th>Créée</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($associations as $asso)
                <tr>
                    <td>{{ $asso->nom }}</td>
                    <td><code>{{ $asso->slug }}</code></td>
                    <td>
                        @php
                            $badge = match ($asso->statut) {
                                'actif' => 'success',
                                'suspendu' => 'warning',
                                'archive' => 'secondary',
                                default => 'light',
                            };
                        @endphp
                        <span class="badge bg-{{ $badge }}">{{ $asso->statut }}</span>
                    </td>
                    <td>{{ $asso->active_users_count }} utilisateur{{ $asso->active_users_count > 1 ? 's' : '' }}</td>
                    <td>{{ $asso->created_at?->format('d/m/Y') }}</td>
                    <td class="text-end">
                        <a href="{{ route('super-admin.associations.show', $asso->slug) }}" class="btn btn-sm btn-outline-primary">Détail</a>
                        <form method="POST" action="{{ route('super-admin.associations.support.enter', $asso->slug) }}" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-danger">Support</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-muted text-center py-4">Aucune association.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{ $associations->links() }}
</div>
