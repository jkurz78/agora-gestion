<h3>6. Réception de documents par email <small class="text-muted">(IMAP, optionnel)</small></h3>
<p class="text-muted">Connectez une boîte IMAP dédiée pour que l'application récupère automatiquement les pièces jointes envoyées sur cette adresse (factures fournisseur, feuilles d'émargement signées…). Vous pouvez configurer cela plus tard.</p>

<form wire:submit="saveStep6">
    <div class="row">
        <div class="col-md-8 mb-3">
            <label class="form-label">Host IMAP</label>
            <input type="text" wire:model="imapHost" class="form-control @error('imapHost') is-invalid @enderror">
            @error('imapHost') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-4 mb-3">
            <label class="form-label">Port</label>
            <input type="number" wire:model="imapPort" class="form-control @error('imapPort') is-invalid @enderror">
            @error('imapPort') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label">Chiffrement</label>
        <select wire:model="imapEncryption" class="form-select">
            <option value="ssl">SSL</option>
            <option value="tls">TLS</option>
            <option value="">Aucun</option>
        </select>
    </div>
    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">Utilisateur</label>
            <input type="text" wire:model="imapUsername" class="form-control @error('imapUsername') is-invalid @enderror">
            @error('imapUsername') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">
                Mot de passe
                @if($imapPasswordDejaEnregistre)<small class="text-muted">(laisser vide pour conserver)</small>@endif
            </label>
            <input type="password" wire:model="imapPassword" class="form-control @error('imapPassword') is-invalid @enderror" placeholder="{{ $imapPasswordDejaEnregistre ? '••••••••' : '' }}">
            @error('imapPassword') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">Dossier "traités"</label>
            <input type="text" wire:model="imapProcessedFolder" class="form-control">
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">Dossier "erreurs"</label>
            <input type="text" wire:model="imapErrorsFolder" class="form-control">
        </div>
    </div>

    <div class="d-flex gap-2 justify-content-between mt-4">
        <button type="button" wire:click="goToStep(5)" class="btn btn-link">← Retour</button>
        <div class="d-flex gap-2">
            <button type="button" wire:click="skipStep6" class="btn btn-outline-secondary">Passer cette étape</button>
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Valider et continuer</button>
        </div>
    </div>
</form>
