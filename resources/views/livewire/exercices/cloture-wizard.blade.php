<div>
    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <h4 class="mb-3">Clôture de l'exercice {{ $exerciceLabel }}</h4>

    {{-- Step 1: Contrôles pré-clôture --}}
    <div class="card mb-3 {{ $step === 1 ? 'border-primary' : '' }}" style="{{ $step === 1 ? 'border-width:2px' : '' }}">
        <div class="card-header d-flex align-items-center gap-2"
             @if ($step > 1) wire:click="goToStep(1)" style="cursor:pointer" @endif>
            <span class="badge rounded-pill {{ $step > 1 ? 'bg-success' : ($step === 1 ? 'bg-primary' : 'bg-secondary') }}">1</span>
            <strong>Contrôles pré-clôture</strong>
            @if ($step > 1)
                <span class="ms-auto small text-muted">Contrôles validés</span>
            @endif
        </div>
        @if ($step === 1)
            <div class="card-body">
                <h6>Contrôles bloquants</h6>
                <ul class="list-unstyled">
                    @foreach ($checkResult->bloquants as $check)
                        <li class="mb-1">
                            <i class="bi bi-{{ $check->ok ? 'check-circle-fill text-success' : 'x-circle-fill text-danger' }}"></i>
                            {{ $check->message }}
                        </li>
                    @endforeach
                </ul>

                <h6 class="mt-3">Avertissements</h6>
                <ul class="list-unstyled">
                    @foreach ($checkResult->avertissements as $check)
                        <li class="mb-1">
                            <i class="bi bi-{{ $check->ok ? 'check-circle-fill text-success' : 'exclamation-triangle-fill text-warning' }}"></i>
                            {{ $check->message }}
                        </li>
                    @endforeach
                </ul>

                <h6 class="mt-3">Soldes des comptes</h6>
                <table class="table table-sm">
                    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                        <tr><th>Compte</th><th class="text-end">Solde</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($checkResult->soldesComptes as $nom => $solde)
                            <tr>
                                <td>{{ $nom }}</td>
                                <td class="text-end">{{ number_format($solde, 2, ',', ' ') }} &euro;</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="d-flex justify-content-end mt-3">
                    @if ($checkResult->peutCloturer())
                        <button class="btn btn-primary" wire:click="suite">Suite <i class="bi bi-arrow-right"></i></button>
                    @else
                        <div>
                            <p class="text-danger small mb-1">Corrigez les contrôles bloquants pour continuer.</p>
                            <button class="btn btn-primary" disabled>Suite <i class="bi bi-arrow-right"></i></button>
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>

    {{-- Step 2: Récapitulatif --}}
    <div class="card mb-3 {{ $step === 2 ? 'border-primary' : '' }}" style="{{ $step === 2 ? 'border-width:2px' : '' }}">
        <div class="card-header d-flex align-items-center gap-2"
             @if ($step > 2) wire:click="goToStep(2)" style="cursor:pointer" @endif>
            <span class="badge rounded-pill {{ $step > 2 ? 'bg-success' : ($step === 2 ? 'bg-primary' : 'bg-secondary') }}">2</span>
            <strong>Récapitulatif</strong>
        </div>
        @if ($step === 2)
            <div class="card-body">

                {{-- Section 1: Soldes d'ouverture --}}
                <h6>Soldes d'ouverture au 01/09/{{ $annee }}</h6>
                <table class="table table-sm">
                    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                        <tr><th>Compte</th><th class="text-end">Solde d'ouverture</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($summary['comptesData'] as $c)
                            <tr>
                                <td>{{ $c['nom'] }}</td>
                                <td class="text-end">{{ number_format($c['solde_ouverture'], 2, ',', ' ') }} &euro;</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="fw-bold">
                        <tr>
                            <td>Total</td>
                            <td class="text-end">{{ number_format($summary['totalSoldeOuverture'], 2, ',', ' ') }} &euro;</td>
                        </tr>
                    </tfoot>
                </table>

                {{-- Section 2: Résultat de l'exercice --}}
                <h6 class="mt-4">Résultat de l'exercice</h6>
                <table class="table table-sm">
                    <tbody>
                        <tr>
                            <td>Total recettes</td>
                            <td class="text-end">{{ number_format($summary['totalRecettes'], 2, ',', ' ') }} &euro;</td>
                        </tr>
                        <tr>
                            <td>Total dépenses</td>
                            <td class="text-end">{{ number_format($summary['totalDepenses'], 2, ',', ' ') }} &euro;</td>
                        </tr>
                        <tr class="fw-bold {{ $summary['resultat'] >= 0 ? 'text-success' : 'text-danger' }}">
                            <td>Résultat : {{ $summary['resultat'] >= 0 ? 'EXCÉDENT' : 'DÉFICIT' }}</td>
                            <td class="text-end">{{ number_format(abs($summary['resultat']), 2, ',', ' ') }} &euro;</td>
                        </tr>
                    </tbody>
                </table>

                {{-- Section 3: Soldes de clôture --}}
                <h6 class="mt-4">Soldes de clôture au 31/08/{{ $annee + 1 }}</h6>
                <table class="table table-sm">
                    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                        <tr>
                            <th>Compte</th>
                            <th class="text-end">Solde d'ouverture</th>
                            <th class="text-end">Mouvements exercice</th>
                            <th class="text-end">Solde actuel</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($summary['comptesData'] as $c)
                            <tr>
                                <td>{{ $c['nom'] }}</td>
                                <td class="text-end">{{ number_format($c['solde_ouverture'], 2, ',', ' ') }} &euro;</td>
                                <td class="text-end">{{ number_format($c['mouvements'], 2, ',', ' ') }} &euro;</td>
                                <td class="text-end">{{ number_format($c['solde_reel'], 2, ',', ' ') }} &euro;</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="fw-bold">
                        <tr>
                            <td>Total</td>
                            <td class="text-end">{{ number_format($summary['totalSoldeOuverture'], 2, ',', ' ') }} &euro;</td>
                            <td class="text-end">{{ number_format($summary['totalMouvements'], 2, ',', ' ') }} &euro;</td>
                            <td class="text-end">{{ number_format($summary['totalSoldeReel'], 2, ',', ' ') }} &euro;</td>
                        </tr>
                        <tr>
                            <td>Solde théorique</td>
                            <td></td>
                            <td></td>
                            <td class="text-end">{{ number_format($summary['soldeTheorique'], 2, ',', ' ') }} &euro;</td>
                        </tr>
                    </tfoot>
                </table>

                {{-- Section 4: Réconciliation --}}
                <h6 class="mt-4">Réconciliation</h6>
                <table class="table table-sm">
                    <tbody>
                        <tr>
                            <td>Solde bancaire total</td>
                            <td class="text-end">{{ number_format($summary['totalSoldeReel'], 2, ',', ' ') }} &euro;</td>
                        </tr>
                        <tr>
                            <td>Écritures non pointées ({{ $summary['nombreNonPointees'] }} écriture{{ $summary['nombreNonPointees'] > 1 ? 's' : '' }})</td>
                            <td class="text-end">{{ number_format($summary['montantNetNonPointees'], 2, ',', ' ') }} &euro;</td>
                        </tr>
                        <tr class="fw-bold">
                            <td>Solde théorique</td>
                            <td class="text-end">{{ number_format($summary['soldeTheorique'], 2, ',', ' ') }} &euro;</td>
                        </tr>
                        <tr class="{{ $summary['ecartResiduel'] == 0 ? 'text-success' : 'text-danger' }} fw-bold">
                            <td>Écart résiduel</td>
                            <td class="text-end">{{ number_format($summary['ecartResiduel'], 2, ',', ' ') }} &euro;</td>
                        </tr>
                    </tbody>
                </table>

                <div class="d-flex justify-content-between mt-3">
                    <button class="btn btn-outline-secondary" wire:click="goToStep(1)"><i class="bi bi-arrow-left"></i> Retour</button>
                    <button class="btn btn-primary" wire:click="suite">Suite <i class="bi bi-arrow-right"></i></button>
                </div>
            </div>
        @endif
    </div>

    {{-- Step 3: Confirmation --}}
    <div class="card mb-3 {{ $step === 3 ? 'border-primary' : '' }}" style="{{ $step === 3 ? 'border-width:2px' : '' }}">
        <div class="card-header d-flex align-items-center gap-2">
            <span class="badge rounded-pill {{ $step === 3 ? 'bg-primary' : 'bg-secondary' }}">3</span>
            <strong>Confirmation</strong>
        </div>
        @if ($step === 3)
            <div class="card-body">
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <strong>Conséquences de la clôture :</strong>
                    <ul class="mb-0 mt-1">
                        <li>L'exercice sera marqué comme clôturé</li>
                        <li>Aucune modification possible sur les transactions et virements</li>
                        <li>Possibilité de réouvrir si nécessaire</li>
                    </ul>
                </div>

                <div class="d-flex justify-content-between mt-3">
                    <button class="btn btn-outline-secondary" wire:click="goToStep(2)"><i class="bi bi-arrow-left"></i> Retour</button>
                    <button class="btn btn-danger" wire:click="cloturer"
                            wire:confirm="Êtes-vous sûr de vouloir clôturer l'exercice {{ $exerciceLabel }} ?">
                        <i class="bi bi-lock"></i> Clôturer l'exercice {{ $exerciceLabel }}
                    </button>
                </div>
            </div>
        @endif
    </div>
</div>
