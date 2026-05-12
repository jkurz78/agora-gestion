<x-tiers.section-card id="documents-factures-emises" titre="Factures émises" :compteur="count($lignes)">
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    <th>N°</th>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Statut</th>
                    <th class="text-end">Montant TTC</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($lignes as $ligne)
                    <tr role="button" onclick="window.location='{{ $ligne->ficheUrl }}'">
                        <td>{{ $ligne->numero }}</td>
                        <td data-sort="{{ $ligne->date->toDateString() }}">{{ $ligne->date->format('d/m/Y') }}</td>
                        <td><span class="badge text-bg-secondary">{{ ucfirst($ligne->type) }}</span></td>
                        <td>{{ ucfirst($ligne->statut) }}</td>
                        <td class="text-end" data-sort="{{ $ligne->montantTtc }}">
                            {{ number_format($ligne->montantTtc, 2, ',', ' ') }} €
                        </td>
                        <td class="text-end">
                            <a href="{{ $ligne->ficheUrl }}" class="btn btn-sm btn-outline-secondary"
                               onclick="event.stopPropagation()" title="Voir">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-tiers.section-card>
