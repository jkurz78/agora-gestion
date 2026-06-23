<div>
    {{-- Compteurs --}}
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <div class="fs-3 fw-bold">{{ $resultats['nb_invitations'] }}</div>
                    <div class="text-muted small">Invitations envoyées</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <div class="fs-3 fw-bold">{{ $resultats['nb_soumissions'] }}</div>
                    <div class="text-muted small">Réponses soumises</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <div class="fs-3 fw-bold">{{ $resultats['taux'] }}%</div>
                    <div class="text-muted small">Taux de réponse</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Bandeau d'avertissement anonymat — systématique (contient "petit groupe") --}}
    <div class="alert alert-info d-flex gap-2 align-items-start mb-4" role="alert">
        <i class="bi bi-shield-check fs-5 flex-shrink-0"></i>
        <div>
            <strong>Questionnaire confidentiel et non nominatif.</strong>
            Sur un petit groupe, certains retours peuvent rester reconnaissables.
            Les identités ne sont visibles que dans la section « Souhaitent être recontactés » pour les participants qui l'ont explicitement demandé.
        </div>
    </div>

    {{-- Résultats par question --}}
    @forelse ($resultats['questions'] as $q)
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold">{{ $q['libelle'] }}</span>
                <span class="badge bg-secondary">{{ $q['type']->label() }}</span>
            </div>
            <div class="card-body">
                @if ($q['type'] === \App\Enums\TypeQuestion::Satisfaction || $q['type'] === \App\Enums\TypeQuestion::Ressenti)
                    @if ($q['moyenne'] !== null)
                        <div class="mb-2">
                            <span class="fs-4 fw-bold">{{ number_format($q['moyenne'], 1, ',', '') }}</span>
                            <span class="text-muted small ms-1">/ {{ $q['type'] === \App\Enums\TypeQuestion::Satisfaction ? '5' : '100' }}</span>
                            <span class="text-muted small ms-2">({{ $q['n'] }} réponse{{ $q['n'] > 1 ? 's' : '' }})</span>
                        </div>
                        @if (!empty($q['distribution']))
                            <div class="d-flex gap-2 flex-wrap">
                                @foreach ($q['distribution'] as $note => $nb)
                                    <span class="badge bg-light text-dark border">{{ $note }} → {{ $nb }}×</span>
                                @endforeach
                            </div>
                        @endif
                    @else
                        <span class="text-muted">Aucune réponse.</span>
                    @endif

                @elseif ($q['type'] === \App\Enums\TypeQuestion::CaseACocher)
                    <div class="d-flex gap-3">
                        <span><strong>Oui :</strong> {{ $q['oui'] }}</span>
                        <span><strong>Non :</strong> {{ $q['non'] }}</span>
                        <span class="text-muted small">({{ $q['n'] }} réponse{{ $q['n'] > 1 ? 's' : '' }})</span>
                    </div>
                    @if ($q['n'] > 0)
                        <div class="progress mt-2" style="height:8px">
                            <div class="progress-bar bg-success" style="width:{{ $q['n'] > 0 ? round($q['oui'] / $q['n'] * 100) : 0 }}%"></div>
                        </div>
                        <div class="text-muted small mt-1">
                            {{ $q['n'] > 0 ? round($q['oui'] / $q['n'] * 100) : 0 }}% oui
                        </div>
                    @endif

                @elseif ($q['type'] === \App\Enums\TypeQuestion::ChoixUnique)
                    @forelse ($q['repartition'] as $item)
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span>{{ $item['libelle'] }}</span>
                            <span class="badge bg-primary rounded-pill">{{ $item['count'] }}</span>
                        </div>
                    @empty
                        <span class="text-muted">Aucune réponse.</span>
                    @endforelse
                    <div class="text-muted small mt-1">{{ $q['n'] }} réponse{{ $q['n'] > 1 ? 's' : '' }}</div>

                @elseif ($q['type'] === \App\Enums\TypeQuestion::TexteCourt || $q['type'] === \App\Enums\TypeQuestion::TexteLong)
                    @forelse ($q['verbatims'] as $verbatim)
                        <blockquote class="blockquote border-start border-3 ps-3 mb-2">
                            <p class="mb-0 fst-italic text-muted small">« {{ $verbatim }} »</p>
                        </blockquote>
                    @empty
                        <span class="text-muted">Aucune réponse texte.</span>
                    @endforelse
                @endif
            </div>
        </div>
    @empty
        <p class="text-muted">Ce questionnaire ne comporte aucune question.</p>
    @endforelse

    {{-- Section contacts : identité nominative uniquement si consentement explicite --}}
    @if ($contacts->isNotEmpty())
        <div class="card mt-4 border-primary">
            <div class="card-header bg-primary text-white fw-semibold">
                Souhaitent être recontactés ({{ $contacts->count() }})
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    @foreach ($contacts as $submission)
                        @php
                            $tiers = $submission->invitation?->participant?->tiers;
                        @endphp
                        <li class="list-group-item">
                            {{ $tiers?->displayName() ?? '—' }}
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif
</div>
