<div>
    <h3>Reçus fiscaux</h3>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="alert alert-info">
        <strong>Conditions légales</strong> : votre association doit être éligible (intérêt général, RUP, ou disposer d'un rescrit fiscal favorable)
        pour émettre des reçus fiscaux. Référence :
        <a href="https://bofip.impots.gouv.fr/bofip/5872-PGP" target="_blank" rel="noopener">BOI-IR-RICI-250-30</a>.
    </div>

    <form wire:submit.prevent="enregistrer">
        <div class="form-check form-switch mb-3">
            <input type="checkbox" id="eligible" class="form-check-input" wire:model.live="eligibleRecuFiscal">
            <label for="eligible" class="form-check-label fw-bold">Émettre des reçus fiscaux</label>
        </div>

        @if(! $eligibleRecuFiscal)
            <div class="alert alert-warning">
                Activez l'émission ci-dessus pour configurer les détails fiscaux ci-dessous.
            </div>
        @endif

        <fieldset @disabled(! $eligibleRecuFiscal)>
            <div class="mb-3">
                <label for="regime" class="form-label">Régime fiscal / agrément</label>
                <select id="regime" class="form-select" wire:model="regimeFiscalDon">
                    <option value="">— Sélectionner —</option>
                    @foreach($regimeOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('regimeFiscalDon') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            </div>

            <div class="mb-3">
                <label for="objet" class="form-label">Objet d'intérêt général</label>
                <textarea id="objet" class="form-control" wire:model="objetRecuFiscal" rows="3"
                          placeholder="Ex: Œuvre d'intérêt général à caractère social et humanitaire"></textarea>
                <div class="form-text">
                    Formulation synthétique justifiant la déductibilité (article 200 du CGI).
                    Catégories éligibles : philanthropique, éducatif, scientifique, social, humanitaire, sportif, familial, culturel,
                    ou défense de l'environnement / patrimoine artistique ou naturel.
                    Il ne s'agit pas de l'objet social complet des statuts, mais de la mention figurant sur le reçu.
                    Référence : <a href="https://bofip.impots.gouv.fr/bofip/5873-PGP" target="_blank" rel="noopener">BOI-IR-RICI-250-10-10</a>.
                </div>
                @error('objetRecuFiscal') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            </div>

            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="rescritNum" class="form-label">N° de rescrit fiscal <span class="text-muted">(optionnel)</span></label>
                    <input type="text" id="rescritNum" class="form-control" wire:model="rescritFiscalNumero">
                    @error('rescritFiscalNumero') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-6">
                    <label for="rescritDate" class="form-label">Date du rescrit</label>
                    <input type="date" id="rescritDate" class="form-control" wire:model="rescritFiscalDate">
                    @error('rescritFiscalDate') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="sigNom" class="form-label">Signataire — Nom</label>
                    <input type="text" id="sigNom" class="form-control" wire:model="signataireNom">
                    @error('signataireNom') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-6">
                    <label for="sigQual" class="form-label">Signataire — Qualité</label>
                    <input type="text" id="sigQual" class="form-control" wire:model="signataireQualite"
                           placeholder="Ex: Président·e, Trésorier·e">
                    @error('signataireQualite') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>
            </div>

            <hr>
            <h6 class="mb-3">Spécificités fiscales</h6>

            <div class="form-check mb-2">
                <input type="checkbox" id="loi-coluche" class="form-check-input" wire:model="loiColucheEligible">
                <label for="loi-coluche" class="form-check-label">
                    Mon association bénéficie de la loi Coluche
                </label>
                <div class="form-text">
                    Aide alimentaire, soins médicaux gratuits ou hébergement aux personnes en difficulté, ou aide aux victimes de violence domestique.
                    Le donateur particulier bénéficie alors d'une réduction de <strong>75 % au lieu de 66 %</strong>
                    (plafond annuel fixé par la loi, environ 1 000 €). Article 200-1 ter du CGI.
                </div>
            </div>

            <div class="form-check mb-3">
                <input type="checkbox" id="ifi" class="form-check-input" wire:model="ifiEligible">
                <label for="ifi" class="form-check-label">
                    Mon association permet la réduction d'impôt dans le cadre de l'IFI
                </label>
                <div class="form-text">
                    Article 978 du CGI. Le donateur redevable de l'Impôt sur la Fortune Immobilière peut déduire
                    <strong>75 %</strong> du don de son IFI (plafond annuel de 50 000 €).
                    Réservé aux fondations reconnues d'utilité publique, certaines associations RUP et établissements de recherche.
                </div>
            </div>
        </fieldset>

        <button type="submit" class="btn btn-primary mt-2">Enregistrer</button>
    </form>
</div>
