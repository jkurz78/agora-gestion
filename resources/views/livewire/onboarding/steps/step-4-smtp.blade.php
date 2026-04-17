<h3>4. Serveur d'envoi d'emails (SMTP)</h3>
<p class="text-muted">Configurez votre serveur SMTP pour envoyer des emails depuis l'application (factures, invitations, communications).</p>

<form wire:submit="saveStep4">
    <div class="row">
        <div class="col-md-8 mb-3">
            <label class="form-label">Host SMTP</label>
            <input type="text" wire:model="smtpHost" class="form-control @error('smtpHost') is-invalid @enderror" placeholder="smtp.votredomaine.fr">
            @error('smtpHost') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-4 mb-3">
            <label class="form-label">Port</label>
            <input type="number" wire:model="smtpPort" class="form-control @error('smtpPort') is-invalid @enderror">
            @error('smtpPort') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label">Chiffrement</label>
        <select wire:model="smtpEncryption" class="form-select @error('smtpEncryption') is-invalid @enderror">
            <option value="tls">TLS</option>
            <option value="ssl">SSL</option>
            <option value="starttls">STARTTLS</option>
            <option value="none">Aucun</option>
        </select>
        @error('smtpEncryption') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">Utilisateur</label>
            <input type="text" wire:model="smtpUsername" class="form-control @error('smtpUsername') is-invalid @enderror">
            @error('smtpUsername') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">
                Mot de passe
                @if($passwordDejaEnregistre)<small class="text-muted">(laisser vide pour conserver)</small>@endif
            </label>
            <input type="password" wire:model="smtpPassword" class="form-control @error('smtpPassword') is-invalid @enderror"
                placeholder="{{ $passwordDejaEnregistre ? '••••••••' : 'Mot de passe SMTP' }}">
            @error('smtpPassword') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
    </div>

    <div class="d-flex gap-2 mb-3">
        <button type="button" wire:click="testSmtp" class="btn btn-outline-secondary" wire:loading.attr="disabled">
            Tester la connexion
        </button>
        <span wire:loading wire:target="testSmtp" class="align-self-center text-muted">Test en cours…</span>
    </div>

    @if ($smtpTestMessage)
        <div class="alert alert-success">{{ $smtpTestMessage }}</div>
    @endif
    @if ($smtpTestError)
        <div class="alert alert-danger">{{ $smtpTestError }}</div>
    @endif

    <div class="d-flex gap-2 justify-content-between mt-4">
        <button type="button" wire:click="goToStep(3)" class="btn btn-link">← Retour</button>
        <button type="button" wire:click="skipStep4" class="btn btn-outline-warning" wire:confirm="Vous pourrez configurer SMTP plus tard depuis les paramètres. Les envois d'emails seront désactivés.">
            Passer sans configurer
        </button>
        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Valider et continuer</button>
    </div>
</form>
