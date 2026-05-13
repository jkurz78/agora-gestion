<table class="table table-sm mb-0">
    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
        <tr>
            <th>Opération</th>
            <th>Type</th>
            <th>Période</th>
            <th>Nb séances</th>
            <th class="text-end">Montant</th>
            <th class="text-end">Actions</th>
        </tr>
    </thead>
    <tbody>
    @foreach($lignes as $ligne)
        <tr wire:key="encadrement-{{ $ligne->operationId() }}"
            onclick="if (!event.target.closest('button,a,.no-row-click')) window.location='{{ route('operations.show', $ligne->operationId()) }}'"
            style="cursor:pointer">
            <td>
                <a href="{{ route('operations.show', $ligne->operationId()) }}" class="no-row-click text-decoration-none">{{ $ligne->operationNom() }}</a>
                @if($ligne->operationArchivee())
                    <span class="badge text-bg-secondary ms-1" title="Opération supprimée">Archivée</span>
                @endif
            </td>
            <td class="text-muted small">{{ $ligne->typeOperationNom() }}</td>
            <td class="small text-nowrap" data-sort="{{ $ligne->dateDebut()?->format('Y-m-d') ?? '' }}">
                @if($ligne->dateDebut() && $ligne->dateFin())
                    {{ $ligne->dateDebut()->format('d/m/Y') }} → {{ $ligne->dateFin()->format('d/m/Y') }}
                @else
                    —
                @endif
            </td>
            <td class="text-center" data-sort="{{ $ligne->nbSeances() }}">
                {{ $ligne->nbSeances() }}
            </td>
            <td class="text-end text-nowrap" data-sort="{{ $ligne->montantTotal() }}">
                {{ number_format($ligne->montantTotal(), 2, ',', ' ') }} €
            </td>
            <td class="text-end">
                <a href="{{ route('operations.show', $ligne->operationId()) }}"
                   class="btn btn-sm btn-outline-secondary no-row-click"
                   title="Voir l'opération">
                    <i class="bi bi-eye"></i>
                </a>
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
