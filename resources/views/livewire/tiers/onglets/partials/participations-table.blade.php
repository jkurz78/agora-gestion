<table class="table table-sm mb-0">
    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
        <tr>
            <th>Opération</th>
            <th>Type</th>
            <th>Période</th>
            <th>Tarif</th>
            <th class="text-center">Séances</th>
            <th class="text-end">Règlement</th>
            <th>Statut</th>
            <th>Référé par</th>
            <th class="text-end">Actions</th>
        </tr>
    </thead>
    <tbody>
    @foreach($lignes as $ligne)
        <tr wire:key="participation-{{ $ligne->participant->id }}"
            onclick="if (!event.target.closest('button,a,.no-row-click')) window.location='{{ route('operations.show', $ligne->operationId()) }}'"
            style="cursor:pointer">
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
            <td class="small" data-sort="{{ $ligne->tarifMontant() }}">
                @if($ligne->tarifLibelle())
                    {{ $ligne->tarifLibelle() }}
                    <span class="text-muted">({{ number_format($ligne->tarifMontant(), 2, ',', ' ') }} €)</span>
                @else
                    —
                @endif
            </td>
            <td class="text-center" data-sort="{{ $ligne->seancesSuivies() }}">
                @if($ligne->seancesTotal() !== null)
                    {{ $ligne->seancesSuivies() }} / {{ $ligne->seancesTotal() }}
                @else
                    —
                @endif
            </td>
            <td class="text-end small" data-sort="{{ $ligne->montantPaye() }}">
                @if($ligne->statut() === 'gratuit')
                    <span class="badge text-bg-info">Gratuit</span>
                @else
                    {{ number_format($ligne->montantPaye(), 2, ',', ' ') }} €
                    <span class="text-muted">/ {{ number_format($ligne->montantPrevu(), 2, ',', ' ') }} €</span>
                @endif
            </td>
            <td>
                @switch($ligne->statut())
                    @case('solde')
                        <span class="badge text-bg-success">Soldé</span>
                    @break
                    @case('partiel')
                        <span class="badge text-bg-warning">Partiel</span>
                    @break
                    @case('non_paye')
                        <span class="badge text-bg-danger">Non payé</span>
                    @break
                    @case('gratuit')
                        <span class="badge text-bg-info">Gratuit</span>
                    @break
                @endswitch
            </td>
            <td class="small">
                @if($ligne->refereParTiers())
                    <a href="{{ route('tiers.show', $ligne->refereParTiers()->id) }}" class="no-row-click text-decoration-none">{{ $ligne->refereParNomComplet() }}</a>
                @endif
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
