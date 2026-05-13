<x-tiers.section-card id="documents-recus-fiscaux" titre="Reçus fiscaux émis" :compteur="count($lignes)">
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    <th>N°</th>
                    <th>Type</th>
                    <th>Date émission</th>
                    <th class="text-end">Montant</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($lignes as $ligne)
                    <tr>
                        <td>{{ $ligne->numero }}</td>
                        <td>
                            <span class="badge text-bg-{{ $ligne->type === 'cotisation' ? 'info' : 'success' }}">
                                {{ ucfirst($ligne->type) }}
                            </span>
                        </td>
                        <td data-sort="{{ $ligne->dateEmission->toDateString() }}">
                            {{ $ligne->dateEmission->format('d/m/Y') }}
                        </td>
                        <td class="text-end" data-sort="{{ $ligne->montant }}">
                            {{ number_format($ligne->montant, 2, ',', ' ') }} €
                        </td>
                        <td class="text-end">
                            <a href="{{ $ligne->downloadUrl }}" class="btn btn-sm btn-outline-secondary" title="Télécharger">
                                <i class="bi bi-download"></i>
                            </a>
                            @if($ligne->sourceUrl)
                                <a href="{{ $ligne->sourceUrl }}" class="btn btn-sm btn-outline-secondary" title="Voir source">
                                    <i class="bi bi-arrow-up-right-square"></i>
                                </a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-tiers.section-card>
