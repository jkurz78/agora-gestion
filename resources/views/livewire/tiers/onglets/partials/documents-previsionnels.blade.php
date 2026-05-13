<x-tiers.section-card titre="Devis et Pro forma" :compteur="count($lignes)" id="documents-previsionnels">
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    <th>N°</th>
                    <th>Type</th>
                    <th>Date</th>
                    <th>Opération</th>
                    <th>Participant</th>
                    <th class="text-end">Montant TTC</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($lignes as $ligne)
                    <tr>
                        <td>{{ $ligne->numero }}</td>
                        <td>
                            <span class="badge text-bg-{{ $ligne->type->value === 'devis' ? 'info' : 'warning' }}">
                                {{ $ligne->type->label() }}
                            </span>
                            @if($ligne->version > 1)
                                <span class="badge text-bg-light text-dark" title="Version">v{{ $ligne->version }}</span>
                            @endif
                        </td>
                        <td data-sort="{{ $ligne->date->format('Y-m-d') }}">{{ $ligne->date->format('d/m/Y') }}</td>
                        <td>{{ $ligne->operationNom }}</td>
                        <td>{{ $ligne->participantNom }}</td>
                        <td class="text-end" data-sort="{{ $ligne->montantTotal }}">
                            {{ number_format($ligne->montantTotal, 2, ',', ' ') }} €
                        </td>
                        <td class="text-end">
                            <a href="{{ $ligne->downloadUrl }}" class="btn btn-sm btn-outline-secondary" title="Télécharger PDF">
                                <i class="bi bi-download"></i>
                            </a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-tiers.section-card>
