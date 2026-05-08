<div class="row g-3">
    {{-- Identité --}}
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header py-2 small fw-semibold">Identité</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-sm-4 text-muted">Nom</dt>
                    <dd class="col-sm-8 mb-2 fw-semibold">{{ strtoupper($tiers->nom ?? '—') }}</dd>
                    @if($tiers->prenom)
                        <dt class="col-sm-4 text-muted">Prénom</dt>
                        <dd class="col-sm-8 mb-2">{{ $tiers->prenom }}</dd>
                    @endif
                    @if($tiers->entreprise)
                        <dt class="col-sm-4 text-muted">Entreprise</dt>
                        <dd class="col-sm-8 mb-2">{{ $tiers->entreprise }}</dd>
                    @endif
                    <dt class="col-sm-4 text-muted">Type</dt>
                    <dd class="col-sm-8 mb-2">{{ $tiers->type === 'entreprise' ? 'Entreprise' : 'Particulier' }}</dd>
                    @if($tiers->date_naissance)
                        <dt class="col-sm-4 text-muted">Date de naissance</dt>
                        <dd class="col-sm-8 mb-0">{{ \Carbon\Carbon::parse($tiers->date_naissance)->format('d/m/Y') }}</dd>
                    @endif
                </dl>
            </div>
        </div>
    </div>

    {{-- Contact --}}
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header py-2 small fw-semibold">Contact</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-sm-4 text-muted">Email</dt>
                    <dd class="col-sm-8 mb-2">
                        {{ $tiers->email ?? '—' }}
                        @if($tiers->email_optout)
                            <span class="badge text-bg-warning ms-1">Désinscrit</span>
                        @endif
                    </dd>
                    <dt class="col-sm-4 text-muted">Téléphone</dt>
                    <dd class="col-sm-8 mb-0">{{ $tiers->telephone ?? '—' }}</dd>
                </dl>
            </div>
        </div>
    </div>

    {{-- Adresse --}}
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header py-2 small fw-semibold">Adresse</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-sm-4 text-muted">Ligne 1</dt>
                    <dd class="col-sm-8 mb-2">{{ $tiers->adresse_ligne1 ?? '—' }}</dd>
                    <dt class="col-sm-4 text-muted">Code postal</dt>
                    <dd class="col-sm-8 mb-2">{{ $tiers->code_postal ?? '—' }}</dd>
                    <dt class="col-sm-4 text-muted">Ville</dt>
                    <dd class="col-sm-8 mb-2">{{ $tiers->ville ?? '—' }}</dd>
                    @if($tiers->pays && $tiers->pays !== 'France')
                        <dt class="col-sm-4 text-muted">Pays</dt>
                        <dd class="col-sm-8 mb-0">{{ $tiers->pays }}</dd>
                    @endif
                </dl>
            </div>
        </div>
    </div>

    {{-- Métadonnées --}}
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header py-2 small fw-semibold">Notes internes &amp; métadonnées</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-sm-4 text-muted">Créé le</dt>
                    <dd class="col-sm-8 mb-2">{{ optional($tiers->created_at)->format('d/m/Y H:i') ?? '—' }}</dd>
                    <dt class="col-sm-4 text-muted">Modifié le</dt>
                    <dd class="col-sm-8 mb-0">{{ optional($tiers->updated_at)->format('d/m/Y H:i') ?? '—' }}</dd>
                </dl>
            </div>
        </div>
    </div>
</div>
