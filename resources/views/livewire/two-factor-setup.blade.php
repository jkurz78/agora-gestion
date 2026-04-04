<div>
    @if ($successMessage)
        <div class="alert alert-success alert-dismissible">
            {{ $successMessage }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if ($errorMessage)
        <div class="alert alert-danger alert-dismissible">
            {{ $errorMessage }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card mt-4">
        <div class="card-header">Vérification en deux étapes</div>
        <div class="card-body">

            @if ($method === null)
                {{-- ═══ Disabled state ═══ --}}
                <p class="text-muted">La vérification en deux étapes n'est pas activée.</p>
                <div class="d-flex gap-2">
                    <button wire:click="enableEmail" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-envelope"></i> Activer via email
                    </button>
                    <button wire:click="startTotp" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-phone"></i> Activer via application
                    </button>
                </div>

            @elseif ($method === 'email')
                {{-- ═══ Email OTP active ═══ --}}
                <p><span class="badge bg-success">OTP email activé</span></p>
                <p class="text-muted small">Un code à 6 chiffres vous sera envoyé par email à chaque connexion.</p>
                <div class="d-flex gap-2">
                    <button wire:click="startTotp" class="btn btn-outline-secondary btn-sm">
                        Passer au TOTP (application)
                    </button>
                    <button wire:click="disable" class="btn btn-outline-danger btn-sm"
                            onclick="return confirm('Désactiver la vérification en deux étapes ?')">
                        Désactiver
                    </button>
                </div>

            @elseif ($method === 'totp' && $totpSecret !== null && ! $isConfirmed)
                {{-- ═══ TOTP setup (not yet confirmed) ═══ --}}
                <p class="mb-2">Scannez ce QR code avec votre application d'authentification :</p>

                <div class="text-center my-3">
                    {!! $qrCodeSvg !!}
                </div>

                <p class="small text-muted text-center">
                    Ou entrez manuellement : <code>{{ $totpSecret }}</code>
                </p>

                <div class="row g-2 align-items-end mt-2">
                    <div class="col-auto">
                        <label class="form-label">Code de vérification</label>
                        <input type="text" wire:model="confirmCode" class="form-control"
                               inputmode="numeric" maxlength="6" placeholder="000000"
                               wire:keydown.enter="confirmTotp">
                        @error('confirmCode') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-auto">
                        <button wire:click="confirmTotp" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i> Confirmer
                        </button>
                    </div>
                    <div class="col-auto">
                        <button wire:click="disable" class="btn btn-outline-secondary">Annuler</button>
                    </div>
                </div>

            @elseif ($method === 'totp' && $isConfirmed)
                {{-- ═══ TOTP active and confirmed ═══ --}}
                <p><span class="badge bg-success">TOTP activé</span></p>
                <p class="text-muted small">Votre application d'authentification génère les codes de connexion.</p>

                @if ($recoveryCodes)
                    <div class="alert alert-warning">
                        <strong><i class="bi bi-exclamation-triangle"></i> Sauvegardez ces codes de récupération</strong>
                        <p class="small mb-2">Chaque code ne peut être utilisé qu'une seule fois. Conservez-les en lieu sûr.</p>
                        <div class="row">
                            @foreach ($recoveryCodes as $code)
                                <div class="col-6 col-md-3"><code>{{ $code }}</code></div>
                            @endforeach
                        </div>
                    </div>
                @else
                    <p class="small text-muted">
                        <i class="bi bi-key"></i> {{ $remainingCodes }} code(s) de récupération restant(s)
                    </p>
                @endif

                <div class="d-flex gap-2 flex-wrap">
                    <button wire:click="regenerateRecoveryCodes" class="btn btn-outline-secondary btn-sm"
                            onclick="return confirm('Régénérer les codes ? Les anciens seront invalidés.')">
                        <i class="bi bi-arrow-repeat"></i> Régénérer les codes
                    </button>
                    <button wire:click="revokeTrustedBrowsers" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-laptop"></i> Révoquer les appareils de confiance
                    </button>
                    <button wire:click="disable" class="btn btn-outline-danger btn-sm"
                            onclick="return confirm('Désactiver la vérification en deux étapes ?')">
                        Désactiver
                    </button>
                </div>
            @endif

        </div>
    </div>
</div>
