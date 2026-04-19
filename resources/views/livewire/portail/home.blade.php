<div>
    <h1 class="h4 mb-2">Bienvenue {{ trim(($tiers->prenom ?? '').' '.($tiers->nom ?? '')) }}</h1>
    <hr>
    <div class="alert alert-info small">
        Vos services arriveront bientôt : notes de frais, attestations de présence, reçus fiscaux…
    </div>
    <form method="POST" action="{{ route('portail.logout', ['association' => $association->slug]) }}" class="mt-3 text-end">
        @csrf
        <button type="submit" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-box-arrow-right"></i> Déconnexion
        </button>
    </form>
</div>
