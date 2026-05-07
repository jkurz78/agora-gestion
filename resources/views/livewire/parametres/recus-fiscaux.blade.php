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
        <div class="form-check mb-3">
            <input type="checkbox" id="eligible" class="form-check-input" wire:model="eligibleRecuFiscal">
            <label for="eligible" class="form-check-label">Émettre des reçus fiscaux</label>
        </div>

        <div class="mb-3">
            <label for="regime" class="form-label">Régime fiscal</label>
            <input type="text" id="regime" class="form-control" wire:model="regimeFiscalDon"
                   placeholder="Ex: Intérêt général, RUP, cultuelle, ...">
            @error('regimeFiscalDon') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
        </div>

        <div class="mb-3">
            <label for="objet" class="form-label">Objet</label>
            <textarea id="objet" class="form-control" wire:model="objetRecuFiscal" rows="3"
                      placeholder="Ex: Œuvre d'intérêt général à caractère social"></textarea>
            @error('objetRecuFiscal') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <label for="rescritNum" class="form-label">N° de rescrit fiscal (optionnel)</label>
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

        <button type="submit" class="btn btn-primary">Enregistrer</button>
    </form>
</div>
