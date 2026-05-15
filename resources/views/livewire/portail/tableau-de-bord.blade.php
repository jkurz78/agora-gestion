<div>
    @php
        $descriptions = [
            'mon-profil'           => 'Coordonnées et préférences',
            'notes-de-frais'       => 'Saisir et suivre vos remboursements',
            'factures-partenaires' => 'Déposer vos factures fournisseurs',
            'historique-depenses'  => 'Vos remboursements passés',
            'mes-adhesions'        => 'Historique adhésions et reçus de cotisation',
            'mes-dons'             => 'Historique dons et reçus fiscaux',
        ];
        $sectionsParGroupe = $sections->groupBy(fn ($s) => $s->groupe ?? 'Autres');
    @endphp

    <h4 class="mb-1">Bonjour {{ $tiers->prenom }}</h4>
    <p class="text-muted mb-4">Bienvenue sur votre espace personnel.</p>

    @foreach ($sectionsParGroupe as $groupe => $sectionsDuGroupe)
        <div class="bg-white border rounded shadow-sm p-3 mb-3">
            <div class="text-uppercase text-muted mb-3"
                 style="font-size:.72rem;letter-spacing:.05em;font-weight:600;">
                {{ $groupe }}
            </div>
            <div class="row g-3">
                @foreach ($sectionsDuGroupe as $section)
                    @php
                        $routeShortName = preg_replace('/^portail\./', '', $section->routeName);
                        $url = \Illuminate\Support\Facades\Route::has($section->routeName)
                            ? \App\Support\PortailRoute::to($routeShortName, $association)
                            : '#';
                        $desc = $descriptions[$section->id] ?? $section->label;
                    @endphp
                    <div class="col-12 col-md-6">
                        <a href="{{ $url }}"
                           class="d-flex align-items-center gap-3 p-2 rounded text-decoration-none text-dark"
                           style="background:#f8f9fa;transition:background .15s;"
                           onmouseover="this.style.background='#eef2f7'"
                           onmouseout="this.style.background='#f8f9fa'">
                            <div class="d-flex align-items-center justify-content-center rounded flex-shrink-0"
                                 style="width:44px;height:44px;background:#3d5473;">
                                <i class="bi {{ $section->icon }} text-white fs-5"></i>
                            </div>
                            <div>
                                <div class="fw-semibold">{{ $section->label }}</div>
                                <div class="text-muted small">{{ $desc }}</div>
                            </div>
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
