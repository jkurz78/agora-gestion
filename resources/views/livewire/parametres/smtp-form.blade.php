{{-- resources/views/livewire/parametres/smtp-form.blade.php --}}
<div>
    @if (session('success'))
        <div class="alert alert-success alert-dismissible mb-4">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible mb-4">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="form-check form-switch mb-4">
        <input class="form-check-input" type="checkbox"
               id="smtpEnabled" wire:click="toggleEnabled"
               @checked($enabled)>
        <label class="form-check-label" for="smtpEnabled">
            Utiliser cette configuration SMTP
            @if ($enabled)
                <span class="badge bg-success ms-1">ACTIVE</span>
            @else
                <span class="badge bg-secondary ms-1">DÉSACTIVÉE — envois via .env</span>
            @endif
        </label>
    </div>

    <div class="row g-3" style="max-width: 680px;">
        <div class="col-md-7">
            <label class="form-label">Hôte SMTP</label>
            <input type="text" class="form-control @error('smtpHost') is-invalid @enderror"
                   wire:model="smtpHost" placeholder="mail.monasso.fr" autocomplete="off">
            @error('smtpHost') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-2">
            <label class="form-label">Port</label>
            <input type="number" class="form-control @error('smtpPort') is-invalid @enderror"
                   wire:model="smtpPort">
            @error('smtpPort') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-3">
            <label class="form-label">Chiffrement</label>
            <select class="form-select @error('smtpEncryption') is-invalid @enderror"
                    wire:model="smtpEncryption">
                <option value="tls">TLS (587)</option>
                <option value="ssl">SSL (465)</option>
                <option value="starttls">STARTTLS</option>
                <option value="none">Aucun</option>
            </select>
            @error('smtpEncryption') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-7">
            <label class="form-label">Utilisateur</label>
            <input type="text" class="form-control @error('smtpUsername') is-invalid @enderror"
                   wire:model="smtpUsername" placeholder="envoi@monasso.fr" autocomplete="off">
            @error('smtpUsername') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-5">
            <label class="form-label">
                Mot de passe
                @if ($passwordDejaEnregistre)
                    <span class="badge bg-info ms-1">déjà enregistré</span>
                @endif
            </label>
            <input type="password" class="form-control @error('smtpPassword') is-invalid @enderror"
                   wire:model="smtpPassword" autocomplete="new-password"
                   placeholder="{{ $passwordDejaEnregistre ? '●●●●●●●● (laisser vide pour conserver)' : '' }}">
            <div class="form-text text-muted">
                Chiffré en base de données.
                @if ($passwordDejaEnregistre) Laisser vide pour conserver la valeur actuelle. @endif
            </div>
            @error('smtpPassword') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-3">
            <label class="form-label">Timeout (s)</label>
            <input type="number" class="form-control @error('timeout') is-invalid @enderror"
                   wire:model="timeout" min="5" max="120">
            @error('timeout') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
    </div>

    <div class="d-flex gap-2 mt-4">
        <button type="button" class="btn btn-primary"
                wire:click="sauvegarder"
                wire:loading.attr="disabled" wire:target="sauvegarder">
            <span wire:loading.remove wire:target="sauvegarder">
                <i class="bi bi-floppy"></i> Enregistrer
            </span>
            <span wire:loading wire:target="sauvegarder">
                <span class="spinner-border spinner-border-sm" role="status"></span> Enregistrement…
            </span>
        </button>
        <button type="button" class="btn btn-outline-secondary"
                wire:click="testerConnexion"
                wire:loading.attr="disabled" wire:target="testerConnexion">
            <span wire:loading.remove wire:target="testerConnexion">
                <i class="bi bi-plug"></i> Tester la connexion
            </span>
            <span wire:loading wire:target="testerConnexion">
                <span class="spinner-border spinner-border-sm" role="status"></span> Test en cours…
            </span>
        </button>
    </div>

    @if ($testResult !== null)
        <div class="mt-3 alert {{ $testResult['success'] ? 'alert-success' : 'alert-danger' }} mb-0"
             style="max-width: 680px;">
            @if ($testResult['success'])
                <i class="bi bi-check-circle-fill"></i>
                Connexion SMTP établie.
                @if ($testResult['banner'])
                    <br><small class="text-muted">{{ $testResult['banner'] }}</small>
                @endif
            @else
                <i class="bi bi-x-circle-fill"></i>
                Échec : {{ $testResult['error'] }}
            @endif
        </div>
    @endif
</div>
