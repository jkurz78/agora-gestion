@php
    $euros = fn (int $centimes): string => number_format($centimes / 100, 2, ',', ' ');
@endphp

<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Livre des immobilisations</h4>
        {{-- Bouton rétabli en tâche 12 --}}
    </div>

    @if ($immobilisations->isEmpty())
        <div class="alert alert-info">
            Aucune immobilisation enregistrée. Les biens durables (matériel, mobilier,
            informatique) s'inscrivent ici plutôt qu'en charge de l'exercice.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle" id="table-immobilisations">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th>Numéro</th>
                        <th>Libellé</th>
                        <th class="text-end">Qté</th>
                        <th>Compte</th>
                        <th>Mise en service</th>
                        <th>Durée</th>
                        <th class="text-end">Valeur brute</th>
                        <th class="text-end">Amortissements</th>
                        <th class="text-end">Valeur nette</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($immobilisations as $immo)
                        <tr>
                            <td data-sort="{{ $immo->numero }}">{{ $immo->numero }}</td>
                            <td>
                                {{ $immo->libelle }}
                                @unless ($immo->estEnService())
                                    <span class="badge bg-warning text-dark ms-1">Pas encore en service</span>
                                @endunless
                            </td>
                            <td class="text-end" data-sort="{{ $immo->quantite }}">{{ $immo->quantite }}</td>
                            <td>{{ $immo->compte->numero_pcg }} — {{ $immo->compte->intitule }}</td>
                            <td data-sort="{{ $immo->date_mise_en_service->format('Y-m-d') }}">
                                {{ $immo->date_mise_en_service->format('d/m/Y') }}
                            </td>
                            <td data-sort="{{ $immo->duree_mois }}">{{ $immo->duree_label }}</td>
                            <td class="text-end" data-sort="{{ $immo->montantAcquisitionCentimes() }}">
                                {{ $euros($immo->montantAcquisitionCentimes()) }} €
                            </td>
                            <td class="text-end" data-sort="{{ $immo->cumulAmortiCentimes() }}">
                                {{ $euros($immo->cumulAmortiCentimes()) }} €
                            </td>
                            <td class="text-end fw-semibold" data-sort="{{ $immo->valeurNetteCentimes() }}">
                                {{ $euros($immo->valeurNetteCentimes()) }} €
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="table-light fw-semibold">
                    <tr>
                        <td colspan="6" class="text-end">Totaux</td>
                        <td class="text-end">{{ $euros($totalBrutCentimes) }} €</td>
                        <td class="text-end">{{ $euros($totalCumulCentimes) }} €</td>
                        <td class="text-end">{{ $euros($totalNetCentimes) }} €</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
