<h3>3. Compte bancaire principal</h3>
<p class="text-muted">Ajoutez votre compte bancaire principal. Vous pourrez en ajouter d'autres ensuite depuis les paramètres.</p>

<form wire:submit="saveStep3">
    <div class="mb-3">
        <label class="form-label">Nom du compte</label>
        <input type="text" wire:model="banqueNom" class="form-control @error('banqueNom') is-invalid @enderror" placeholder="Compte courant principal">
        @error('banqueNom') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="mb-3">
        <label class="form-label">IBAN</label>
        <input type="text" wire:model="banqueIban" class="form-control @error('banqueIban') is-invalid @enderror" placeholder="FR76...">
        @error('banqueIban') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">BIC (optionnel)</label>
            <input type="text" wire:model="banqueBic" class="form-control @error('banqueBic') is-invalid @enderror">
            @error('banqueBic') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">Domiciliation (optionnel)</label>
            <input type="text" wire:model="banqueDomiciliation" class="form-control @error('banqueDomiciliation') is-invalid @enderror" placeholder="Crédit Agricole Paris 8">
            @error('banqueDomiciliation') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">Solde initial</label>
            <input type="number" step="0.01" wire:model="banqueSoldeInitial" class="form-control @error('banqueSoldeInitial') is-invalid @enderror">
            @error('banqueSoldeInitial') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">Date du solde initial</label>
            <input type="date" wire:model="banqueDateSoldeInitial" class="form-control @error('banqueDateSoldeInitial') is-invalid @enderror">
            @error('banqueDateSoldeInitial') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
    </div>
    <button type="button" wire:click="goToStep(2)" class="btn btn-link">← Retour</button>
    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Valider et continuer</button>
</form>
