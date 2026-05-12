<x-tiers.section-card id="documents-factures-deposees" titre="Factures partenaires déposées" :compteur="count($lignes)">
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    <th>N° fournisseur</th>
                    <th>Date facture</th>
                    <th>Statut</th>
                    <th class="text-end">Taille</th>
                    <th>Date dépôt</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($lignes as $ligne)
                    <tr>
                        <td>{{ $ligne->numeroFournisseur }}</td>
                        <td data-sort="{{ $ligne->dateFacture->toDateString() }}">{{ $ligne->dateFacture->format('d/m/Y') }}</td>
                        <td><span class="badge text-bg-secondary">{{ ucfirst($ligne->statut) }}</span></td>
                        <td class="text-end" data-sort="{{ $ligne->pdfTaille }}">
                            {{ number_format($ligne->pdfTaille / 1024, 0, ',', ' ') }} Ko
                        </td>
                        <td data-sort="{{ $ligne->dateDepot->toIso8601String() }}">{{ $ligne->dateDepot->format('d/m/Y H:i') }}</td>
                        <td class="text-end">
                            <a href="{{ $ligne->downloadUrl }}" class="btn btn-sm btn-outline-secondary" title="Télécharger">
                                <i class="bi bi-download"></i>
                            </a>
                            @if($ligne->ficheUrl)
                                <a href="{{ $ligne->ficheUrl }}" class="btn btn-sm btn-outline-secondary" title="Voir fiche">
                                    <i class="bi bi-eye"></i>
                                </a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-tiers.section-card>
