<x-guest-layout>
    <p class="text-muted small mb-3">
        Veuillez vérifier votre adresse email en cliquant sur le lien que nous venons de vous envoyer.
    </p>

    @if (session('status') == 'verification-link-sent')
        <div class="alert alert-success mb-3">
            Un nouveau lien de vérification a été envoyé à votre adresse email.
        </div>
    @endif

    <div class="d-flex justify-content-between align-items-center">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="btn btn-primary">Renvoyer le lien</button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn-link text-decoration-none">Déconnexion</button>
        </form>
    </div>
</x-guest-layout>
