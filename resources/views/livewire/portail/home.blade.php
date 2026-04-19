<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h4 mb-2">Bienvenue {{ trim(($tiers->prenom ?? '').' '.($tiers->nom ?? '')) }}</h1>
                <p class="text-muted">{{ $association->nom }}</p>
                <hr>
                <div class="alert alert-info small">
                    Vos services arriveront bientôt : notes de frais, attestations de présence, reçus fiscaux…
                </div>
                <form method="POST" action="{{ route('portail.logout', ['association' => $association->slug]) }}" class="mt-3">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary btn-sm">Déconnexion</button>
                </form>
            </div>
        </div>
    </div>
</div>
