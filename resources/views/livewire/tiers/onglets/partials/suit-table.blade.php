<table class="table table-sm mb-0">
    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
        <tr>
            <th>Tiers suivi</th>
            <th>Qualité</th>
            <th>Opération</th>
            <th>Type</th>
            <th>Période</th>
            <th>Date d'inscription</th>
            <th class="text-end">Actions</th>
        </tr>
    </thead>
    <tbody>
    @foreach($lignes as $ligne)
        <tr wire:key="suit-{{ $ligne->participant->id }}-{{ $ligne->qualite() }}"
            onclick="if (!event.target.closest('button,a,.no-row-click')) window.location='{{ route('operations.show', $ligne->operationId()) }}'"
            style="cursor:pointer">
            <td>
                <a href="{{ route('tiers.show', $ligne->tiersSuiviId()) }}" class="no-row-click text-decoration-none">{{ $ligne->tiersSuiviNomComplet() }}</a>
            </td>
            <td>
                <span class="badge text-bg-secondary">{{ $ligne->qualiteLabel() }}</span>
            </td>
            <td>
                <a href="{{ route('operations.show', $ligne->operationId()) }}" class="no-row-click text-decoration-none">{{ $ligne->operationNom() }}</a>
                @if($ligne->estHelloasso())
                    <span class="badge text-bg-info ms-1" title="Issu de HelloAsso">HelloAsso</span>
                @endif
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
            <td class="small text-nowrap" data-sort="{{ $ligne->dateInscription()->format('Y-m-d') }}">
                {{ $ligne->dateInscription()->format('d/m/Y') }}
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
