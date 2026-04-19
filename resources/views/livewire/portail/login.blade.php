<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h4 mb-3">Portail {{ $association->nom }}</h1>
                <p class="text-muted">Entrez votre adresse email pour recevoir votre code de connexion.</p>

                @if (session('portail.info'))
                    <div class="alert alert-info">{{ session('portail.info') }}</div>
                @endif

                <form wire:submit="submit" novalidate>
                    <div class="mb-3">
                        <label for="email" class="form-label">Adresse email</label>
                        <input type="email" wire:model="email" id="email" class="form-control @error('email') is-invalid @enderror" required autofocus>
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <span wire:loading.remove>Recevoir mon code</span>
                        <span wire:loading>Envoi en cours…</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
