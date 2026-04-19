<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h4 mb-3">Code de connexion</h1>
                <p class="text-muted">Saisissez le code à 8 chiffres reçu par email.</p>

                @if ($errorMessage)
                    <div class="alert alert-danger">{{ $errorMessage }}</div>
                @endif
                @if ($infoMessage)
                    <div class="alert alert-info">{{ $infoMessage }}</div>
                @endif
                @if (session('portail.info'))
                    <div class="alert alert-info">{{ session('portail.info') }}</div>
                @endif

                <form wire:submit="submit" novalidate>
                    <div class="mb-3">
                        <label for="code" class="form-label">Code à 8 chiffres</label>
                        <input
                            type="text"
                            wire:model="code"
                            id="code"
                            autocomplete="one-time-code"
                            inputmode="numeric"
                            pattern="[\d\s]*"
                            class="form-control font-monospace @error('code') is-invalid @enderror"
                            required
                            autofocus
                        >
                        @error('code')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <button type="submit" class="btn btn-primary w-100 mb-2">Valider</button>
                </form>
                <button type="button" wire:click="resend" class="btn btn-link btn-sm w-100">Renvoyer le code</button>
            </div>
        </div>
    </div>
</div>
