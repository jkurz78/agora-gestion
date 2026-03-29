<div x-show="step === 5" x-cloak data-step="5">
    <h5 class="mb-3"><i class="bi bi-currency-euro"></i> Engagement financier</h5>

    @if($tarif && $seancesCount)
        @php
            $montantTotal = $seancesCount * $tarif->montant;
        @endphp
        <div class="card mb-3">
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <td>Tarif</td>
                        <td class="text-end fw-bold">{{ number_format((float) $tarif->montant, 2, ',', ' ') }} € / séance</td>
                    </tr>
                    <tr>
                        <td>Nombre de séances</td>
                        <td class="text-end fw-bold">{{ $seancesCount }}</td>
                    </tr>
                    <tr class="table-primary">
                        <td><strong>Montant total</strong></td>
                        <td class="text-end fw-bold">{{ number_format((float) $montantTotal, 2, ',', ' ') }} €</td>
                    </tr>
                </table>
            </div>
        </div>
    @else
        <div class="alert alert-secondary mb-3">
            <i class="bi bi-info-circle me-1"></i> Tarif à confirmer avec l'association.
        </div>
    @endif

    <div class="mb-4">
        <label class="form-label fw-bold">Mode de paiement</label>
        <div class="form-check">
            <input type="radio" name="mode_paiement_choisi" value="comptant" class="form-check-input"
                   id="paiement_comptant" @checked(old('mode_paiement_choisi') === 'comptant')>
            <label class="form-check-label" for="paiement_comptant">Comptant (en une fois)</label>
        </div>
        <div class="form-check">
            <input type="radio" name="mode_paiement_choisi" value="par_seance" class="form-check-input"
                   id="paiement_seance" @checked(old('mode_paiement_choisi') === 'par_seance')>
            <label class="form-check-label" for="paiement_seance">Par séance</label>
        </div>
    </div>

    <div class="mb-3">
        <label class="form-label fw-bold">Moyen de règlement</label>
        <div class="form-check">
            <input type="radio" name="moyen_paiement_choisi" value="especes" class="form-check-input"
                   id="moyen_especes" @checked(old('moyen_paiement_choisi') === 'especes')>
            <label class="form-check-label" for="moyen_especes">Espèces</label>
        </div>
        <div class="form-check">
            <input type="radio" name="moyen_paiement_choisi" value="cheque" class="form-check-input"
                   id="moyen_cheque" @checked(old('moyen_paiement_choisi') === 'cheque')>
            <label class="form-check-label" for="moyen_cheque">Chèque</label>
        </div>
        <div class="form-check">
            <input type="radio" name="moyen_paiement_choisi" value="virement" class="form-check-input"
                   id="moyen_virement" @checked(old('moyen_paiement_choisi') === 'virement')>
            <label class="form-check-label" for="moyen_virement">Virement</label>
        </div>
    </div>
</div>
