<x-guest-layout>
    <form method="POST" action="{{ route('password.store') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div class="mb-3">
            <label for="email" class="form-label">Adresse email</label>
            <input id="email" type="email" name="email" value="{{ old('email', $request->email) }}"
                   class="form-control @error('email') is-invalid @enderror"
                   required autofocus autocomplete="username">
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">Nouveau mot de passe</label>
            <input id="password" type="password" name="password"
                   class="form-control @error('password') is-invalid @enderror"
                   required autocomplete="new-password">
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="password_confirmation" class="form-label">Confirmer le mot de passe</label>
            <input id="password_confirmation" type="password" name="password_confirmation"
                   class="form-control" required autocomplete="new-password">
        </div>

        <div class="d-grid">
            <button type="submit" class="btn btn-primary">Réinitialiser le mot de passe</button>
        </div>
    </form>
</x-guest-layout>
