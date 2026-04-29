<div class="alert alert-info" role="alert">
    <h5 class="alert-heading mb-3">Démonstration du portail tiers</h5>
    <p class="mb-3">Cliquez sur un profil pour explorer le portail sans saisie de code OTP. Les données sont réinitialisées chaque nuit à 4h.</p>
    <div class="row g-2">
        <div class="col-md-6">
            <a href="{{ \App\Support\PortailRoute::to('demo.login-as', $association ?? null, ['tierId' => 31]) }}"
               class="btn btn-outline-primary w-100 text-start">
                <strong>&#128100; Membre particulier</strong><br>
                <small>Marie GAUTHIER — NDF, attestations</small>
            </a>
        </div>
        <div class="col-md-6">
            <a href="{{ \App\Support\PortailRoute::to('demo.login-as', $association ?? null, ['tierId' => 34]) }}"
               class="btn btn-outline-primary w-100 text-start">
                <strong>&#127970; Fournisseur entreprise</strong><br>
                <small>Salle des Brotteaux — Factures partenaires</small>
            </a>
        </div>
    </div>
</div>
