<div>
    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if (session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <form wire:submit.prevent="sauvegarder">
        <div class="card mb-3">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span>Configuration SMTP sortant</span>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" id="smtpEnabled"
                           wire:click="toggleEnabled"
                           @checked($enabled)>
                    <label class="form-check-label" for="smtpEnabled">
                        {{ $enabled ? 'Activé' : 'Désactivé' }}
                    </label>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label">Hôte SMTP</label>
                        <input type="text" class="form-control @error('smtpHost') is-invalid @enderror"
                               wire:model="smtpHost" placeholder="smtp.example.fr">
                        @error('smtpHost') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Port</label>
                        <input type="number" class="form-control @error('smtpPort') is-invalid @enderror"
                               wire:model="smtpPort">
                        @error('smtpPort') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Chiffrement</label>
                        <select class="form-select @error('smtpEncryption') is-invalid @enderror"
                                wire:model="smtpEncryption">
                            <option value="tls">TLS</option>
                            <option value="ssl">SSL</option>
                            <option value="starttls">STARTTLS</option>
                            <option value="none">Aucun</option>
                        </select>
                        @error('smtpEncryption') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Utilisateur</label>
                        <input type="text" class="form-control @error('smtpUsername') is-invalid @enderror"
                               wire:model="smtpUsername" autocomplete="off">
                        @error('smtpUsername') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">
                            Mot de passe
                            @if ($passwordDejaEnregistre)
                                <small class="text-muted">(laisser vide pour conserver l'actuel)</small>
                            @endif
                        </label>
                        <input type="password" class="form-control @error('smtpPassword') is-invalid @enderror"
                               wire:model="smtpPassword" autocomplete="new-password">
                        @error('smtpPassword') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Timeout (s)</label>
                        <input type="number" class="form-control @error('timeout') is-invalid @enderror"
                               wire:model="timeout" min="5" max="120">
                        @error('timeout') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    Enregistrer
                </button>
                <button type="button" class="btn btn-outline-secondary"
                        wire:click="testerConnexion">
                    Tester la connexion
                </button>
            </div>
        </div>
    </form>

    @if ($testResult !== null)
        <div class="alert {{ $testResult['success'] ? 'alert-success' : 'alert-danger' }}">
            @if ($testResult['success'])
                <strong>Connexion réussie.</strong>
                @if ($testResult['banner'])
                    <br><small>{{ $testResult['banner'] }}</small>
                @endif
            @else
                <strong>Échec de connexion :</strong> {{ $testResult['error'] }}
            @endif
        </div>
    @endif
</div>
