<div>
    <h1 class="h4 mb-2">Bienvenue {{ trim(($tiers->prenom ?? '').' '.($tiers->nom ?? '')) }}</h1>
    <hr>

    <div class="row g-3 mb-4">
        <div class="col-12">
            <a href="{{ route('portail.ndf.index', ['association' => $association->slug]) }}"
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

    <div class="text-end">
        <form method="POST" action="{{ route('portail.logout', ['association' => $association->slug]) }}">
            @csrf
            <button type="submit" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-box-arrow-right"></i> Déconnexion
            </button>
        </form>
    </div>
</div>
