<div x-show="step === 7" x-cloak data-step="7">
    <h5 class="mb-3"><i class="bi bi-check2-square"></i> Engagements</h5>

    {{-- Mandatory checkboxes --}}
    <div class="card mb-4">
        <div class="card-header fw-bold">Engagements obligatoires</div>
        <div class="card-body">
            <div class="form-check mb-3">
                <input type="checkbox" name="engagement_presence" value="1" class="form-check-input"
                       id="engagement_presence" data-required :class="hasError('engagement_presence') && 'is-invalid'">
                <label class="form-check-label" for="engagement_presence">
                    Je m'engage à être présent(e) à toutes les séances prévues.
                </label>
                <div class="invalid-feedback" x-text="errors.engagement_presence"></div>
            </div>

            <div class="form-check mb-3">
                <input type="checkbox" name="engagement_certificat" value="1" class="form-check-input"
                       id="engagement_certificat" data-required :class="hasError('engagement_certificat') && 'is-invalid'">
                <label class="form-check-label" for="engagement_certificat">
                    Je fournirai un certificat médical de non contre-indication à la pratique sportive.
                </label>
                <div class="invalid-feedback" x-text="errors.engagement_certificat"></div>
            </div>

            @if($tarif && (float) $tarif->montant > 0)
            <div class="form-check mb-3">
                <input type="checkbox" name="engagement_reglement" value="1" class="form-check-input"
                       id="engagement_reglement" data-required :class="hasError('engagement_reglement') && 'is-invalid'">
                <label class="form-check-label" for="engagement_reglement">
                    J'ai compris que mon inscription m'engage à régler
                    <strong>{{ number_format((float) ($seancesCount * $tarif->montant), 2, ',', ' ') }} €</strong>
                    ({{ $seancesCount }} séance(s) × {{ number_format((float) $tarif->montant, 2, ',', ' ') }} €).
                    Les séances sont dues dans tous les cas, même en cas d'absence, sauf cas de force majeure dûment justifié.
                </label>
                <div class="invalid-feedback" x-text="errors.engagement_reglement"></div>
            </div>
            @endif

            <div class="form-check mb-3">
                <input type="checkbox" name="engagement_rgpd" value="1" class="form-check-input"
                       id="engagement_rgpd" data-required :class="hasError('engagement_rgpd') && 'is-invalid'">
                <label class="form-check-label" for="engagement_rgpd">
                    J'accepte le traitement électronique de mes données personnelles. Je dispose d'un droit d'accès, de modification et de suppression de mes données (droit à l'oubli) à l'issue de l'opération.
                </label>
                <div class="invalid-feedback" x-text="errors.engagement_rgpd"></div>
            </div>
        </div>
    </div>

    {{-- Optional checkbox --}}
    <div class="card mb-4">
        <div class="card-header fw-bold">Autorisation optionnelle</div>
        <div class="card-body">
            <div class="form-check">
                <input type="checkbox" name="autorisation_contact_medecin" value="1" class="form-check-input"
                       id="autorisation_contact">
                <label class="form-check-label" for="autorisation_contact">
                    J'autorise l'association à prendre contact avec mon médecin traitant et/ou mon thérapeute référent si nécessaire.
                </label>
            </div>
        </div>
    </div>

    {{-- Token re-entry --}}
    <div class="card border-primary">
        <div class="card-header fw-bold text-primary">
            <i class="bi bi-pen"></i> Confirmation
        </div>
        <div class="card-body">
            <p>Pour confirmer votre engagement, veuillez re-saisir le code qui vous a été communiqué :</p>
            <div class="row justify-content-center">
                <div class="col-md-6">
                    <input type="text" name="token_confirmation" class="form-control form-control-lg text-center font-monospace"
                           placeholder="XXXX-XXXX" maxlength="9" autocomplete="off" autocapitalize="characters"
                           data-required :class="hasError('token_confirmation') && 'is-invalid'">
                    <div class="invalid-feedback" x-text="errors.token_confirmation"></div>
                </div>
            </div>
        </div>
    </div>
</div>
