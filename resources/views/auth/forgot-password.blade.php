<x-guest-layout>
    <p class="text-muted small mb-3">
        Mot de passe oublié ? Saisissez votre adresse email et nous vous enverrons un lien de réinitialisation.
    </p>

    @if (session('status'))
        <div class="alert alert-success mb-3">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <div class="mb-3">
            <label for="email" class="form-label">Adresse email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}"
                   class="form-control @error('email') is-invalid @enderror"
                   required autofocus>
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="d-flex justify-content-between align-items-center">
            <a href="{{ route('login') }}" class="text-decoration-none small">Retour à la connexion</a>
            <button type="submit" class="btn btn-primary">Envoyer le lien</button>
        </div>
    </form>
</x-guest-layout>
