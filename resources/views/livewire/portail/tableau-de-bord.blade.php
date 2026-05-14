<div>
    @php
        $descriptions = [
            'mon-profil'           => 'Coordonnées et préférences',
            'notes-de-frais'       => 'Saisir et suivre vos remboursements',
            'factures-partenaires' => 'Déposer vos factures fournisseurs',
            'historique-depenses'  => 'Vos remboursements passés',
        ];
    @endphp

    <h4 class="mb-1">Bonjour {{ $tiers->prenom }}</h4>
    <p class="text-muted mb-4">Bienvenue sur votre espace personnel.</p>

    @if ($sections->isNotEmpty())
        <p class="text-uppercase text-muted" style="font-size:.72rem;letter-spacing:.05em;">Vos espaces</p>

        <div class="row g-3">
            @foreach ($sections as $section)
                @php
                    $routeShortName = preg_replace('/^portail\./', '', $section->routeName);
                    $url = \Illuminate\Support\Facades\Route::has($section->routeName)
                        ? \App\Support\PortailRoute::to($routeShortName, $association)
                        : '#';
                    $desc = $descriptions[$section->id] ?? $section->label;
                @endphp
                <div class="col-12 col-md-6">
                    <a href="{{ $url }}" class="card text-decoration-none text-dark h-100">
                        <div class="card-body d-flex align-items-center gap-3">
                            <div class="d-flex align-items-center justify-content-center rounded flex-shrink-0"
                                 style="width:44px;height:44px;background:#3d5473;">
                                <i class="bi {{ $section->icon }} text-white fs-5"></i>
                            </div>
                            <div>
                                <div class="fw-semibold">{{ $section->label }}</div>
                                <div class="text-muted small">{{ $desc }}</div>
                            </div>
                        </div>
                    </a>
                </div>
            @endforeach
        </div>
    @endif
</div>
