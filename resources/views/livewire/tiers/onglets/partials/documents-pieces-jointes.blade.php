<x-tiers.section-card id="documents-pieces-jointes" titre="Pièces jointes comptables" :compteur="count($lignes)">
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Libellé</th>
                    <th>Niveau</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($lignes as $ligne)
                    <tr>
                        <td data-sort="{{ $ligne->dateTransaction->toDateString() }}">{{ $ligne->dateTransaction->format('d/m/Y') }}</td>
                        <td>
                            <span class="badge text-bg-{{ $ligne->type === 'recette' ? 'success' : 'warning' }}">
                                {{ ucfirst($ligne->type) }}
                            </span>
                        </td>
                        <td>{{ $ligne->libelle }}</td>
                        <td>
                            <span class="badge text-bg-light text-dark">{{ ucfirst($ligne->niveau) }}</span>
                        </td>
                        <td class="text-end">
                            <a href="{{ $ligne->downloadUrl }}" class="btn btn-sm btn-outline-secondary" title="Télécharger">
                                <i class="bi bi-download"></i>
                            </a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-tiers.section-card>
