<x-app-layout>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>Opérations</h1>
        <div class="d-flex gap-2 align-items-center">
            @if ($showAll)
                <a href="{{ route('compta.operations.index') }}" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-funnel"></i> Exercice {{ $exercice }}-{{ $exercice + 1 }} seulement
                </a>
            @else
                <a href="{{ route('compta.operations.index', ['all' => 1]) }}" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-list-ul"></i> Toutes les opérations
                </a>
            @endif
            <a href="{{ route('compta.operations.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> Ajouter une opération
            </a>
        </div>
    </div>

    <form method="GET" action="{{ route('compta.operations.index') }}" class="mb-3 d-flex gap-2 align-items-center">
        @if ($showAll)
            <input type="hidden" name="all" value="1">
        @endif
        <label for="type_operation_id" class="form-label mb-0 text-nowrap">Type :</label>
        <select name="type_operation_id" id="type_operation_id" class="form-select form-select-sm" style="width:auto;"
                onchange="this.form.submit()">
            <option value="">— Tous les types —</option>
            @foreach ($typeOperations as $type)
                <option value="{{ $type->id }}" @selected($typeOperationId === $type->id)>
                    {{ $type->code }} — {{ $type->nom }}
                </option>
            @endforeach
        </select>
        @if ($typeOperationId)
            <a href="{{ route('compta.operations.index', $showAll ? ['all' => 1] : []) }}"
               class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-x-lg"></i> Effacer
            </a>
        @endif
    </form>

    <div class="card">
        <div class="card-body">
            <table class="table table-striped table-hover mb-0" id="opsTable">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th data-col="0" style="cursor:pointer;user-select:none;">Code <i class="bi bi-arrow-down-up text-secondary" id="opsSortIcon0"></i></th>
                        <th data-col="1" style="cursor:pointer;user-select:none;">Nom <i class="bi bi-arrow-down-up text-secondary" id="opsSortIcon1"></i></th>
                        <th data-col="2" style="cursor:pointer;user-select:none;">Type <i class="bi bi-arrow-down-up text-secondary" id="opsSortIcon2"></i></th>
                        <th data-col="3" style="cursor:pointer;user-select:none;">Date début <i class="bi bi-arrow-down-up text-secondary" id="opsSortIcon3"></i></th>
                        <th data-col="4" style="cursor:pointer;user-select:none;">Date fin <i class="bi bi-arrow-down-up text-secondary" id="opsSortIcon4"></i></th>
                        <th data-col="5" style="cursor:pointer;user-select:none;">Séances <i class="bi bi-arrow-down-up text-secondary" id="opsSortIcon5"></i></th>
                        <th data-col="6" style="cursor:pointer;user-select:none;">Statut <i class="bi bi-arrow-down-up text-secondary" id="opsSortIcon6"></i></th>
                        <th style="width: 150px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($operations as $operation)
                        <tr>
                            <td>{{ $operation->code }}</td>
                            <td>{{ $operation->nom }}</td>
                            <td data-sort="{{ $operation->typeOperation?->nom ?? '' }}">
                                @if ($operation->typeOperation)
                                    <span class="badge bg-info text-dark">{{ $operation->typeOperation->nom }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td data-sort="{{ $operation->date_debut?->format('Y-m-d') ?? '' }}">{{ $operation->date_debut?->format('d/m/Y') ?? '—' }}</td>
                            <td data-sort="{{ $operation->date_fin?->format('Y-m-d') ?? '' }}">{{ $operation->date_fin?->format('d/m/Y') ?? '—' }}</td>
                            <td data-sort="{{ $operation->nombre_seances ?? '' }}">{{ $operation->nombre_seances ?? '—' }}</td>
                            <td>
                                <span class="badge {{ $operation->statut === \App\Enums\StatutOperation::EnCours ? 'bg-primary' : 'bg-secondary' }}">
                                    {{ $operation->statut->label() }}
                                </span>
                            </td>
                            <td>
                                <a href="{{ route('compta.operations.show', $operation) }}" class="btn btn-sm btn-outline-info">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="{{ route('compta.operations.edit', $operation) }}" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-pencil"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-muted">Aucune opération enregistrée.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <script>
    (function () {
        var sortState = { col: null, dir: 'asc' };
        var numCols = 7;

        document.querySelectorAll('#opsTable thead th[data-col]').forEach(function (th) {
            th.addEventListener('click', function () {
                var col = parseInt(this.dataset.col);
                if (sortState.col === col) {
                    sortState.dir = sortState.dir === 'asc' ? 'desc' : 'asc';
                } else {
                    sortState.col = col;
                    sortState.dir = 'asc';
                }
                for (var i = 0; i < numCols; i++) {
                    var icon = document.getElementById('opsSortIcon' + i);
                    if (i === sortState.col) {
                        icon.className = 'bi ' + (sortState.dir === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down');
                    } else {
                        icon.className = 'bi bi-arrow-down-up text-secondary';
                    }
                }
                var tbody = document.querySelector('#opsTable tbody');
                var rows = Array.from(tbody.querySelectorAll('tr'));
                rows.sort(function (a, b) {
                    var cellA = a.cells[sortState.col];
                    var cellB = b.cells[sortState.col];
                    var aVal = (cellA.dataset.sort !== undefined ? cellA.dataset.sort : cellA.textContent.trim()).toLowerCase();
                    var bVal = (cellB.dataset.sort !== undefined ? cellB.dataset.sort : cellB.textContent.trim()).toLowerCase();
                    if (aVal < bVal) return sortState.dir === 'asc' ? -1 : 1;
                    if (aVal > bVal) return sortState.dir === 'asc' ? 1 : -1;
                    return 0;
                });
                rows.forEach(function (row) { tbody.appendChild(row); });
            });
        });
    })();
    </script>
</x-app-layout>
