<x-guest-layout>
    <h5 class="mb-3 text-center">Vérification en deux étapes</h5>

    @if ($method === \App\Enums\TwoFactorMethod::Email)
        <p class="text-muted small mb-3">Un code a été envoyé à votre adresse email.</p>
    @else
        <p class="text-muted small mb-3" id="totp-message">Entrez le code de votre application d'authentification.</p>
        <p class="text-muted small mb-3 d-none" id="recovery-message">Entrez un de vos codes de récupération.</p>
    @endif

    @if (session('status'))
        <div class="alert alert-success small">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('two-factor.challenge.verify') }}" id="challenge-form">
        @csrf
        <input type="hidden" name="use_recovery" id="use_recovery" value="0">

        <div class="mb-3">
            <label for="code" class="form-label" id="code-label">Code</label>
            <input id="code" type="text" name="code"
                   class="form-control @error('code') is-invalid @enderror"
                   required autofocus autocomplete="one-time-code"
                   inputmode="numeric" maxlength="20">
            @error('code')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3 form-check">
            <input type="checkbox" class="form-check-input" name="trust_browser" value="1" id="trust_browser">
            <label for="trust_browser" class="form-check-label small">Se fier à ce navigateur pendant 30 jours</label>
        </div>

        <button type="submit" class="btn btn-primary w-100 mb-3">
            <i class="bi bi-shield-check"></i> Vérifier
        </button>
    </form>

    <div class="d-flex justify-content-between">
        @if ($method === \App\Enums\TwoFactorMethod::Email)
            <form method="POST" action="{{ route('two-factor.challenge.resend') }}">
                @csrf
                <button type="submit" class="btn btn-link btn-sm p-0">Renvoyer le code</button>
            </form>
        @else
            <button type="button" class="btn btn-link btn-sm p-0" id="toggle-recovery"
                    onclick="
                        var isRecovery = document.getElementById('use_recovery').value === '1';
                        document.getElementById('use_recovery').value = isRecovery ? '0' : '1';
                        document.getElementById('totp-message').classList.toggle('d-none');
                        document.getElementById('recovery-message').classList.toggle('d-none');
                        document.getElementById('code').setAttribute('inputmode', isRecovery ? 'numeric' : 'text');
                        document.getElementById('code').setAttribute('maxlength', isRecovery ? '6' : '20');
                        this.textContent = isRecovery ? 'Utiliser un code de récupération' : 'Utiliser le code TOTP';
                        document.getElementById('code').value = '';
                        document.getElementById('code').focus();
                    ">
                Utiliser un code de récupération
            </button>
        @endif

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn-link btn-sm p-0 text-muted">Se déconnecter</button>
        </form>
    </div>
</x-guest-layout>
