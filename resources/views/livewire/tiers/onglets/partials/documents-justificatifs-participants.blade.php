<x-tiers.section-card id="documents-justificatifs-participants" titre="Justificatifs participants" :compteur="count($lignes)">
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    <th>Label</th>
                    <th>Participant</th>
                    <th>Source</th>
                    <th>Date dépôt</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($lignes as $ligne)
                    <tr>
                        <td>{{ $ligne->label }}</td>
                        <td>{{ $ligne->participantNom }}</td>
                        <td><span class="badge text-bg-light text-dark">{{ $ligne->source }}</span></td>
                        <td data-sort="{{ $ligne->dateDepot->toIso8601String() }}">{{ $ligne->dateDepot->format('d/m/Y') }}</td>
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
