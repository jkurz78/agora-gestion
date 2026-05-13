<x-tiers.section-card titre="Attestations de présence" :compteur="count($lignes)" id="documents-attestations-presence">
    <div class="alert alert-info small py-2 mb-2 d-flex align-items-center gap-2">
        <i class="bi bi-stars"></i>
        <span>Documents régénérés à la demande à partir des présences enregistrées.</span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    <th>Opération</th>
                    <th>Participant</th>
                    <th class="text-end">Séances présentes</th>
                    <th>Dernière présence</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($lignes as $ligne)
                    <tr>
                        <td>
                            {{ $ligne->operationNom }}
                            @if($ligne->operationArchivee)
                                <span class="badge text-bg-secondary ms-1" title="Opération archivée">Archivée</span>
                            @endif
                        </td>
                        <td>{{ $ligne->participantNom }}</td>
                        <td class="text-end" data-sort="{{ $ligne->nbPresences }}">
                            {{ $ligne->nbPresences }} / {{ $ligne->nbSeancesTotal }}
                        </td>
                        <td data-sort="{{ $ligne->dateDernierePresence?->format('Y-m-d') ?? '' }}">
                            {{ $ligne->dateDernierePresence?->format('d/m/Y') ?? '—' }}
                        </td>
                        <td class="text-end">
                            <a href="{{ $ligne->downloadUrl }}" target="_blank" class="btn btn-sm btn-outline-secondary" title="Télécharger l'attestation PDF">
                                <i class="bi bi-download"></i>
                            </a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-tiers.section-card>
