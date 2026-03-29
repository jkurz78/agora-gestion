<div x-show="step === 4" x-cloak data-step="4">
    <h5 class="mb-3"><i class="bi bi-info-circle"></i> Informations pratiques</h5>

    <p class="text-muted mb-4">Merci de préciser les points suivants. Ces informations nous permettent de vous orienter vers les dispositifs d'aide dont vous pourriez bénéficier.</p>

    {{-- Coupons sport CE --}}
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">
                <span>Je peux disposer de <strong>coupons sport</strong> dans le cadre d'un CE</span>
                <div class="d-flex gap-3">
                    <div class="form-check form-check-inline mb-0">
                        <input type="radio" name="info_coupons_sport_ce" value="oui" class="form-check-input" id="coupons_ce_oui">
                        <label class="form-check-label" for="coupons_ce_oui">Oui</label>
                    </div>
                    <div class="form-check form-check-inline mb-0">
                        <input type="radio" name="info_coupons_sport_ce" value="non" class="form-check-input" id="coupons_ce_non">
                        <label class="form-check-label" for="coupons_ce_non">Non</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Coupons sport-santé mutuelle --}}
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">
                <span>Je peux disposer de <strong>coupons sport-santé</strong> dans le cadre de ma mutuelle</span>
                <div class="d-flex gap-3">
                    <div class="form-check form-check-inline mb-0">
                        <input type="radio" name="info_coupons_sport_sante" value="oui" class="form-check-input" id="coupons_sante_oui">
                        <label class="form-check-label" for="coupons_sante_oui">Oui</label>
                    </div>
                    <div class="form-check form-check-inline mb-0">
                        <input type="radio" name="info_coupons_sport_sante" value="non" class="form-check-input" id="coupons_sante_non">
                        <label class="form-check-label" for="coupons_sante_non">Non</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Prise en charge kiné/ostéo --}}
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>Je peux disposer d'une prise en charge de séances de <strong>kiné / ostéopathie</strong> dans le cadre de ma mutuelle</span>
                <div class="d-flex gap-3 align-items-center">
                    <div class="form-check form-check-inline mb-0">
                        <input type="radio" name="info_prise_charge_kine" value="oui" class="form-check-input" id="kine_oui"
                               x-on:change="$refs.kine_nb.closest('.input-group').classList.remove('d-none')">
                        <label class="form-check-label" for="kine_oui">Oui</label>
                    </div>
                    <div class="form-check form-check-inline mb-0">
                        <input type="radio" name="info_prise_charge_kine" value="non" class="form-check-input" id="kine_non"
                               x-on:change="$refs.kine_nb.closest('.input-group').classList.add('d-none')">
                        <label class="form-check-label" for="kine_non">Non</label>
                    </div>
                </div>
            </div>
            <div class="input-group input-group-sm mt-2 d-none" style="max-width: 250px;">
                <span class="input-group-text">Nombre de séances</span>
                <input type="number" name="info_nb_seances_kine" class="form-control" min="0" x-ref="kine_nb">
            </div>
        </div>
    </div>

    {{-- Prise en charge sophrologie --}}
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>Je peux disposer d'une prise en charge de séances de <strong>sophrologie</strong> dans le cadre de ma mutuelle</span>
                <div class="d-flex gap-3 align-items-center">
                    <div class="form-check form-check-inline mb-0">
                        <input type="radio" name="info_prise_charge_sophro" value="oui" class="form-check-input" id="sophro_oui"
                               x-on:change="$refs.sophro_nb.closest('.input-group').classList.remove('d-none')">
                        <label class="form-check-label" for="sophro_oui">Oui</label>
                    </div>
                    <div class="form-check form-check-inline mb-0">
                        <input type="radio" name="info_prise_charge_sophro" value="non" class="form-check-input" id="sophro_non"
                               x-on:change="$refs.sophro_nb.closest('.input-group').classList.add('d-none')">
                        <label class="form-check-label" for="sophro_non">Non</label>
                    </div>
                </div>
            </div>
            <div class="input-group input-group-sm mt-2 d-none" style="max-width: 250px;">
                <span class="input-group-text">Nombre de séances</span>
                <input type="number" name="info_nb_seances_sophro" class="form-control" min="0" x-ref="sophro_nb">
            </div>
        </div>
    </div>

    {{-- Prise en charge thérapie --}}
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>Je peux disposer d'une prise en charge de séances de <strong>thérapie</strong> dans le cadre de ma mutuelle</span>
                <div class="d-flex gap-3 align-items-center">
                    <div class="form-check form-check-inline mb-0">
                        <input type="radio" name="info_prise_charge_therapie" value="oui" class="form-check-input" id="therapie_oui"
                               x-on:change="$refs.therapie_nb.closest('.input-group').classList.remove('d-none')">
                        <label class="form-check-label" for="therapie_oui">Oui</label>
                    </div>
                    <div class="form-check form-check-inline mb-0">
                        <input type="radio" name="info_prise_charge_therapie" value="non" class="form-check-input" id="therapie_non"
                               x-on:change="$refs.therapie_nb.closest('.input-group').classList.add('d-none')">
                        <label class="form-check-label" for="therapie_non">Non</label>
                    </div>
                </div>
            </div>
            <div class="input-group input-group-sm mt-2 d-none" style="max-width: 250px;">
                <span class="input-group-text">Nombre de séances</span>
                <input type="number" name="info_nb_seances_therapie" class="form-control" min="0" x-ref="therapie_nb">
            </div>
        </div>
    </div>
</div>
