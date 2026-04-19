<div>
    <p class="text-muted mb-3">Saisissez le code à 8 chiffres reçu par email.</p>

    @if ($errorMessage)
        <div class="alert alert-danger small">{{ $errorMessage }}</div>
    @endif
    @if ($infoMessage)
        <div class="alert alert-info small">{{ $infoMessage }}</div>
    @endif
    @if (session('portail.info'))
        <div class="alert alert-info small">{{ session('portail.info') }}</div>
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
                class="form-control form-control-lg text-center font-monospace @error('code') is-invalid @enderror"
                style="letter-spacing: .5rem;"
                required
                autofocus
            >
            @error('code')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
        <button type="submit" class="btn btn-primary w-100 mb-2">
            <i class="bi bi-box-arrow-in-right"></i>
            <span wire:loading.remove wire:target="submit">Valider</span>
            <span wire:loading wire:target="submit">Vérification…</span>
        </button>
    </form>
    <button type="button" wire:click="resend" class="btn btn-link btn-sm w-100">
        <i class="bi bi-arrow-clockwise"></i> Renvoyer le code
    </button>
</div>
