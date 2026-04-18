<div>
    @if($flashMessage)
        <div class="alert alert-{{ $flashType }} alert-dismissible fade show">
            {{ $flashMessage }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" wire:click="$set('flashMessage', '')"></button>
        </div>
    @endif

    {{-- Toolbar --}}
    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
        <div class="d-flex gap-2 align-items-center">
            <label class="form-label mb-0 small text-muted">Filtre :</label>
            <select wire:model.live="filter" class="form-select form-select-sm" style="width:auto">
                <option value="tous">Tous</option>
                <option value="actif">Actifs</option>
                <option value="inactif">Inactifs</option>
            </select>
        </div>
        <a href="{{ route('operations.types-operation.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg"></i> Nouveau type
        </a>
    </div>

    {{-- Table --}}
    <div class="table-responsive">
        <table class="table table-sm table-striped table-hover" id="type-operation-table">
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    <th>Logo</th>
                    <th class="sortable" data-col="nom" style="cursor:pointer">Nom <i class="bi bi-arrow-down-up" style="font-size:.7rem"></i></th>
                    <th>Sous-catégorie</th>
                    <th class="text-center">Séances</th>
                    <th class="text-center">Formulaire</th>
                    <th class="text-center">Adhérents</th>
                    <th class="text-center">Actif</th>
                    <th class="text-center">Tarifs</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody style="color:#555">
                @forelse($types as $type)
                    <tr class="{{ !$type->actif ? 'opacity-50' : '' }}">
                        <td>
                            @if($type->logo_path)
                                <img src="{{ \App\Support\TenantAsset::url($type->typeOpLogoFullPath()) }}"
                                     alt="{{ $type->nom }}" style="width:32px;height:32px;object-fit:cover;border-radius:4px">
                            @else
                                <span class="text-muted"><i class="bi bi-image" style="font-size:1.2rem"></i></span>
                            @endif
                        </td>
                        <td class="small" data-sort="{{ $type->nom }}">
                            <a href="{{ route('operations.types-operation.show', $type) }}" class="text-decoration-none">{{ $type->nom }}</a>
                        </td>
                        <td class="small">{{ $type->sousCategorie?->nom ?? '—' }}</td>
                        <td class="text-center small">{{ $type->nombre_seances ?? '—' }}</td>
                        <td class="text-center">
                            @if($type->formulaire_actif)
                                <i class="bi bi-circle-fill text-success" style="font-size:.6rem"></i>
                            @else
                                <i class="bi bi-circle-fill" style="font-size:.6rem;color:#ccc"></i>
                            @endif
                        </td>
                        <td class="text-center">
                            @if($type->reserve_adherents)
                                <i class="bi bi-circle-fill text-success" style="font-size:.6rem"></i>
                            @else
                                <i class="bi bi-circle-fill" style="font-size:.6rem;color:#ccc"></i>
                            @endif
                        </td>
                        <td class="text-center">
                            @if($type->actif)
                                <span class="badge bg-success">Actif</span>
                            @else
                                <span class="badge bg-secondary">Inactif</span>
                            @endif
                        </td>
                        <td class="text-center">
                            <span class="badge bg-info text-dark">{{ $type->tarifs->count() }}</span>
                        </td>
                        <td class="text-end">
                            @if($type->operations_count > 0)
                                <span data-bs-toggle="tooltip" title="Utilisé par {{ $type->operations_count }} opération(s)">
                                    <button class="btn btn-sm btn-outline-secondary" disabled style="pointer-events:none">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </span>
                            @else
                                <button class="btn btn-sm btn-outline-danger"
                                        wire:click="delete({{ $type->id }})"
                                        wire:confirm="Supprimer ce type d'opération ?"
                                        title="Supprimer">
                                    <i class="bi bi-trash"></i>
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">
                            Aucun type d'opération enregistré.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- JS sorting --}}
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const table = document.getElementById('type-operation-table');
            if (!table) return;

            const headers = table.querySelectorAll('th.sortable');
            let currentCol = null;
            let ascending = true;

            headers.forEach(function (th) {
                th.addEventListener('click', function () {
                    const col = th.dataset.col;
                    if (currentCol === col) {
                        ascending = !ascending;
                    } else {
                        currentCol = col;
                        ascending = true;
                    }

                    const tbody = table.querySelector('tbody');
                    const rows = Array.from(tbody.querySelectorAll('tr'));
                    const colIndex = Array.from(th.parentElement.children).indexOf(th);

                    rows.sort(function (a, b) {
                        const aCell = a.children[colIndex];
                        const bCell = b.children[colIndex];
                        if (!aCell || !bCell) return 0;

                        const aVal = (aCell.dataset.sort || aCell.textContent || '').trim().toLowerCase();
                        const bVal = (bCell.dataset.sort || bCell.textContent || '').trim().toLowerCase();

                        const result = aVal.localeCompare(bVal, 'fr');
                        return ascending ? result : -result;
                    });

                    rows.forEach(function (row) { tbody.appendChild(row); });

                    headers.forEach(function (h) {
                        const icon = h.querySelector('i');
                        if (icon) icon.className = 'bi bi-arrow-down-up';
                    });
                    const icon = th.querySelector('i');
                    if (icon) icon.className = ascending ? 'bi bi-arrow-down' : 'bi bi-arrow-up';
                });
            });
        });
    </script>
</div>
