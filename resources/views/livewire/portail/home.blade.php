<div>
    <h1 class="h4 mb-2">Bienvenue {{ trim(($tiers->prenom ?? '').' '.($tiers->nom ?? '')) }}</h1>
    <hr>

    @if (session('portail.info'))
        <div class="alert alert-info">{{ session('portail.info') }}</div>
    @endif

    @if (! $tiers->pour_depenses && ! $tiers->pour_recettes)
        <div class="alert alert-warning">
            <strong>Aucun espace activé.</strong> Votre compte n'est pas encore rattaché à un espace portail. Contactez votre association.
        </div>
    @endif

    @if ($tiers->pour_recettes)
        <section class="mb-4">
            <h2 class="h5 text-uppercase text-muted mb-3">Membres, participants, donateurs</h2>
            <div class="card">
                <div class="card-body">
                    <h3 class="h6 mb-2">Espace membre</h3>
                    <p class="text-muted small mb-0">
                        Votre espace membre sera bientôt enrichi : cotisations, dons, attestations de présence, reçus fiscaux.
                    </p>
                </div>
            </div>
        </section>
    @endif

    @if ($tiers->pour_depenses)
        <section class="mb-4">
            <h2 class="h5 text-uppercase text-muted mb-3">Notes de frais</h2>
            <div class="row g-3 mb-0">
                <div class="col-12">
                    <a href="{{ \App\Support\PortailRoute::to('ndf.index', $association) }}"
                       class="card text-decoration-none text-dark h-100">
                        <div class="card-body d-flex align-items-center gap-3">
                            <i class="bi bi-receipt fs-2 text-primary"></i>
                            <div>
                                <h5 class="card-title mb-0">Vos notes de frais</h5>
                                <p class="card-text text-muted small mb-0">Saisir et suivre vos remboursements de frais</p>
                            </div>
                            <i class="bi bi-chevron-right ms-auto text-muted"></i>
                        </div>
                    </a>
                </div>
            </div>
        </section>

        <section class="mb-4">
            <h2 class="h5 text-uppercase text-muted mb-3">Vos factures</h2>
            <div class="row g-3 mb-0">
                <div class="col-12">
                    <a href="{{ \App\Support\PortailRoute::to('factures.index', $association) }}"
                       class="card text-decoration-none text-dark h-100">
                        <div class="card-body d-flex align-items-center gap-3">
                            <i class="bi bi-inbox fs-2 text-primary"></i>
                            <div>
                                <h5 class="card-title mb-0">Boîte de dépôt</h5>
                                <p class="card-text text-muted small mb-0">Nouvelle facture et en attente de traitement</p>
                            </div>
                            <i class="bi bi-chevron-right ms-auto text-muted"></i>
                        </div>
                    </a>
                </div>

                <div class="col-12">
                    <a href="{{ \App\Support\PortailRoute::to('historique.index', $association) }}"
                       class="card text-decoration-none text-dark h-100">
                        <div class="card-body d-flex align-items-center gap-3">
                            <i class="bi bi-clock-history fs-2 text-primary"></i>
                            <div>
                                <h5 class="card-title mb-0">Historique et règlement</h5>
                                <p class="card-text text-muted small mb-0">Suivi comptable et historique</p>
                            </div>
                            <i class="bi bi-chevron-right ms-auto text-muted"></i>
                        </div>
                    </a>
                </div>
            </div>
        </section>
    @endif

    <div class="text-end">
        <form method="POST" action="{{ \App\Support\PortailRoute::to('logout', $association) }}">
            @csrf
            <button type="submit" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-box-arrow-right"></i> Déconnexion
            </button>
        </form>
    </div>
</div>
