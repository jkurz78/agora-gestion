<div>
    @if(\App\Support\Demo::isActive())
        <x-demo-portail-banner :association="$association" class="mb-4" />
    @endif

    <p class="text-muted mb-3">Entrez votre adresse email pour recevoir votre code de connexion.</p>

    @if (session('portail.info'))
        <div class="alert alert-info small">{{ session('portail.info') }}</div>
    @endif

    <form wire:submit="submit" novalidate>
        <div class="mb-3">
            <label for="email" class="form-label">Adresse email</label>
            <input type="email" wire:model="email" id="email" class="form-control @error('email') is-invalid @enderror" required autofocus autocomplete="email">
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-envelope-arrow-up"></i>
            <span wire:loading.remove wire:target="submit">Recevoir mon code</span>
            <span wire:loading wire:target="submit">Envoi en cours…</span>
        </button>
    </form>
</div>
