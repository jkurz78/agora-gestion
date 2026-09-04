<div>
    {{-- Flash messages --}}
    @if (session('success'))
        <div class="alert alert-success alert-dismissible">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- En-tête --}}
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div class="d-flex align-items-start gap-3">
            <a href="{{ route('banques.rapprochement.index') }}"
               class="btn btn-outline-secondary btn-sm mt-1"
               title="Retour à la liste des rapprochements">
                <i class="bi bi-arrow-left"></i>
            </a>
            <div>
            <h4 class="mb-1">{{ $rapprochement->compte->nom }}</h4>
            @if ($rapprochement->isEnCours())
                <div class="d-flex align-items-center gap-2 mt-1">
                    <label class="text-muted small mb-0">Relevé du</label>
                    <input type="date"
                           wire:change="updateDateFin($event.target.value)"
                           value="{{ $rapprochement->date_fin->format('Y-m-d') }}"
                           class="form-control form-control-sm" style="width:auto"
                           {{ $exerciceCloture || ! $this->canEdit ? 'disabled' : '' }}>
                    <span class="badge bg-warning text-dark ms-1"><i class="bi bi-pencil"></i> En cours</span>
                </div>
                @error('date_fin') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
            @else
                <span class="text-muted">Relevé du {{ $rapprochement->date_fin->format('d/m/Y') }}</span>
                <span class="badge bg-secondary ms-2"><i class="bi bi-lock"></i> Verrouillé</span>
            @endif
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('banques.rapprochement.pdf', $rapprochement) }}" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-download"></i> Télécharger PDF
            </a>
            <a href="{{ route('banques.rapprochement.pdf', $rapprochement) }}?mode=inline" target="_blank" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-file-pdf"></i> Ouvrir PDF
            </a>
            @if ($rapprochement->isVerrouille() && $rapprochement->hasPieceJointe())
                <a href="{{ $rapprochement->pieceJointeUrl() }}" target="_blank" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-file-earmark-pdf"></i> Voir le relevé
                </a>
            @endif
        </div>
    </div>

    {{-- Bandeau de soldes --}}
    <div class="row g-3 mb-4">
        <div class="col-md-2">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Solde ouverture</div>
                    <div class="fw-bold">{{ number_format((float) $rapprochement->solde_ouverture, 2, ',', ' ') }} €</div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Solde fin (relevé)</div>
                    @if ($rapprochement->isEnCours())
                        <input type="number" step="0.01"
                               wire:change="updateSoldeFin($event.target.value)"
                               value="{{ number_format((float) $rapprochement->solde_fin, 2, '.', '') }}"
                               class="form-control form-control-sm text-center fw-bold" style="width:auto;margin:auto"
                               {{ $exerciceCloture || ! $this->canEdit ? 'disabled' : '' }}>
                        @error('solde_fin') <div class="text-danger" style="font-size:.75rem">{{ $message }}</div> @enderror
                    @else
                        <div class="fw-bold">{{ number_format((float) $rapprochement->solde_fin, 2, ',', ' ') }} €</div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Solde pointé</div>
                    <div class="fw-bold">{{ number_format($soldePointage, 2, ',', ' ') }} €</div>
                    @if ($projectedSolde !== null)
                        <div class="small text-primary fst-italic" style="font-size:.75rem">
                            &rarr; {{ number_format($projectedSolde, 2, ',', ' ') }} €
                        </div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-2">
            @php $displayEcart = $projectedEcart ?? $ecart; @endphp
            <div class="card text-center border-{{ $displayEcart == 0 ? 'success' : 'danger' }}">
                <div class="card-body py-2">
                    <div class="text-muted small">Écart</div>
                    <div class="fw-bold {{ $ecart == 0 ? 'text-success' : 'text-danger' }}">
                        {{ number_format($ecart, 2, ',', ' ') }} €
                    </div>
                    @if ($projectedEcart !== null)
                        <div class="small fst-italic {{ $projectedEcart == 0 ? 'text-success' : 'text-danger' }}" style="font-size:.75rem">
                            &rarr; {{ number_format($projectedEcart, 2, ',', ' ') }} €
                        </div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Débits pointés</div>
                    <div class="fw-bold text-danger">{{ number_format($totalDebitPointe, 2, ',', ' ') }} €</div>
                    @if ($projectedDebit !== null)
                        <div class="small text-primary fst-italic" style="font-size:.75rem">
                            &rarr; {{ number_format($projectedDebit, 2, ',', ' ') }} €
                        </div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Crédits pointés</div>
                    <div class="fw-bold text-success">{{ number_format($totalCreditPointe, 2, ',', ' ') }} €</div>
                    @if ($projectedCredit !== null)
                        <div class="small text-primary fst-italic" style="font-size:.75rem">
                            &rarr; {{ number_format($projectedCredit, 2, ',', ' ') }} €
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Actions --}}
    @if ($rapprochement->isEnCours() && ! $exerciceCloture && $this->canEdit)
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="d-flex gap-2">
                @if ($rapprochement->hasPieceJointe())
                    <a href="{{ $rapprochement->pieceJointeUrl() }}" target="_blank" class="btn btn-outline-secondary">
                        <i class="bi bi-file-earmark-pdf"></i> Voir le relevé
                    </a>
                    @if ($mouvementsReleve === null)
                        <button wire:click="lancerMatchingAutomatique"
                                wire:loading.attr="disabled"
                                wire:target="lancerMatchingAutomatique"
                                class="btn btn-outline-primary">
                            <span wire:loading.remove wire:target="lancerMatchingAutomatique">
                                <i class="bi bi-magic"></i> Pointage assisté
                            </span>
                            <span wire:loading wire:target="lancerMatchingAutomatique">
                                <span class="spinner-border spinner-border-sm" role="status"></span>
                                Analyse du relevé…
                            </span>
                        </button>
                    @endif
                @endif
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('banques.rapprochement.index') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-floppy"></i> Enregistrer et quitter
                </a>
                <button wire:click="supprimer"
                        wire:confirm="Supprimer ce rapprochement ? Toutes les écritures pointées seront dépointées."
                        class="btn btn-outline-danger">
                    <i class="bi bi-trash"></i> Supprimer
                </button>
                @if ($ecart == 0)
                    <button wire:click="verrouiller"
                            wire:confirm="Verrouiller ce rapprochement ? Cette action est irréversible. Les champs Date, Montant et Compte bancaire des écritures pointées ne pourront plus être modifiés."
                            class="btn btn-danger">
                        <i class="bi bi-lock"></i> Verrouiller
                    </button>
                @else
                    <button class="btn btn-danger" disabled
                            title="L'écart doit être nul pour verrouiller.">
                        <i class="bi bi-lock"></i> Verrouiller (écart non nul)
                    </button>
                @endif
            </div>
        </div>
    @endif

    @if ($matchingErreur)
        <div class="alert alert-warning alert-dismissible mb-4">
            <i class="bi bi-exclamation-triangle"></i> {{ $matchingErreur }}
            <button type="button" class="btn-close" wire:click="annulerPointage"></button>
        </div>
    @endif

    @if ($mouvementsReleve !== null && $candidatsMatching !== null)
        @php
            $candidatsLookup = [];
            foreach ($candidatsMatching as $tx) {
                $candidatsLookup[$tx['type'] . '-' . $tx['id']] = $tx;
            }

            $txAssociees = [];
            foreach ($associationsPointage as $mi => $assoc) {
                $txAssociees[$assoc['transaction_type'] . '-' . $assoc['transaction_id']] = $mi;
            }

            $selectedMontant = null;
            if ($mouvementSelectionne !== null && isset($mouvementsReleve[$mouvementSelectionne])) {
                $selectedMontant = round($mouvementsReleve[$mouvementSelectionne]['montant'], 2);
            }

            $txNonAssociees = [];
            foreach ($candidatsMatching as $tx) {
                if (!isset($txAssociees[$tx['type'] . '-' . $tx['id']])) {
                    $txNonAssociees[] = $tx;
                }
            }
        @endphp
        <div class="card border-primary mb-4">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <span><i class="bi bi-magic"></i> Pointage assisté</span>
                <span class="badge bg-light text-primary">{{ count($associationsPointage) }} association(s)</span>
            </div>
            <div class="card-body p-0">
                {{-- En-têtes colonnes --}}
                <div class="row g-0">
                    <div class="col-6 border-end px-3 py-2 fw-semibold small" style="background:#eef3f8">
                        <i class="bi bi-bank"></i> Relevé bancaire ({{ count($mouvementsReleve) }})
                    </div>
                    <div class="col-6 px-3 py-2 fw-semibold small" style="background:#eef3f8">
                        <i class="bi bi-journal-text"></i> Écritures comptables ({{ count($candidatsMatching) }})
                        @if ($mouvementSelectionne !== null)
                            <span class="badge bg-primary ms-1" style="font-size:.65rem">Montant : {{ number_format(abs($selectedMontant), 2, ',', ' ') }} €</span>
                        @endif
                    </div>
                </div>

                {{-- Contenu scrollable --}}
                <div style="max-height:450px;overflow-y:auto">
                    {{-- Paires face à face : chaque mouvement aligné avec son écriture --}}
                    @foreach ($mouvementsReleve as $i => $mv)
                        @php
                            $isAssociated = isset($associationsPointage[$i]);
                            $isSelected = $mouvementSelectionne === $i;
                            $assocTx = null;
                            if ($isAssociated) {
                                $assocKey = $associationsPointage[$i]['transaction_type'] . '-' . $associationsPointage[$i]['transaction_id'];
                                $assocTx = $candidatsLookup[$assocKey] ?? null;
                            }
                            $bgLeft = $isAssociated ? 'background:#d1e7dd' : ($isSelected ? 'background:#cfe2ff' : '');
                            $bgRight = $isAssociated ? 'background:#d1e7dd' : '';
                        @endphp
                        <div class="row g-0 border-bottom" wire:key="pair-{{ $i }}">
                            {{-- Gauche : mouvement du relevé --}}
                            <div class="col-6 border-end d-flex align-items-center px-3 py-2"
                                 style="cursor:pointer;{{ $bgLeft }}"
                                 wire:click="{{ $isAssociated ? "dissocier({$i})" : "selectionnerMouvement({$i})" }}">
                                <div class="flex-grow-1 small">
                                    <span class="text-muted me-1">{{ $mv['date'] ? \Carbon\Carbon::parse($mv['date'])->format('d/m') : '—' }}</span>
                                    {{ \Illuminate\Support\Str::limit($mv['libelle'] ?? '—', 30) }}
                                </div>
                                <div class="text-end text-nowrap small fw-semibold ms-2 {{ $mv['montant'] < 0 ? 'text-danger' : 'text-success' }}">
                                    {{ $mv['montant'] < 0 ? '-' : '+' }}{{ number_format(abs($mv['montant']), 2, ',', ' ') }}
                                </div>
                                @if ($isAssociated)
                                    <i class="bi bi-link text-success ms-1" style="font-size:.8rem"></i>
                                @elseif ($isSelected)
                                    <i class="bi bi-arrow-right text-primary ms-1" style="font-size:.8rem"></i>
                                @endif
                            </div>

                            {{-- Droite : écriture associée ou vide --}}
                            <div class="col-6 d-flex align-items-center px-3 py-2"
                                 style="{{ $bgRight }}{{ $isAssociated ? 'cursor:pointer' : '' }}"
                                 @if ($isAssociated) wire:click="dissocier({{ $i }})" @endif>
                                @if ($isAssociated && $assocTx)
                                    <i class="bi bi-link text-success me-1" style="font-size:.8rem"></i>
                                    <div class="flex-grow-1 small">
                                        <span class="text-muted me-1">{{ \Carbon\Carbon::parse($assocTx['date'])->format('d/m') }}</span>
                                        {{ \Illuminate\Support\Str::limit($assocTx['libelle'] ?? '—', 30) }}
                                    </div>
                                    <div class="text-end text-nowrap small fw-semibold ms-2 {{ $assocTx['montant_signe'] < 0 ? 'text-danger' : 'text-success' }}">
                                        {{ $assocTx['montant_signe'] < 0 ? '-' : '+' }}{{ number_format(abs($assocTx['montant_signe']), 2, ',', ' ') }}
                                    </div>
                                    <i class="bi bi-x-circle text-danger ms-1" style="font-size:.7rem" title="Dissocier"></i>
                                @elseif ($isSelected)
                                    <span class="small text-muted fst-italic">
                                        <i class="bi bi-arrow-down"></i> Choisir ci-dessous
                                    </span>
                                @else
                                    <span class="small text-muted">—</span>
                                @endif
                            </div>
                        </div>
                    @endforeach

                    {{-- Séparation : écritures non associées --}}
                    @if (count($txNonAssociees) > 0)
                        <div class="row g-0">
                            <div class="col-6 border-end"></div>
                            <div class="col-6 px-3 py-1 small text-muted fw-semibold" style="background:#f8f9fa;border-top:2px solid #dee2e6">
                                Écritures non associées ({{ count($txNonAssociees) }})
                            </div>
                        </div>

                        @foreach ($txNonAssociees as $tx)
                            @php
                                $txKey = $tx['type'] . '-' . $tx['id'];
                                $montantMatch = false;
                                if ($selectedMontant !== null) {
                                    $montantMatch = abs($selectedMontant - round($tx['montant_signe'], 2)) < 0.001;
                                }
                                $bgTx = $montantMatch ? 'background:#fff3cd' : '';
                                $dimmed = $mouvementSelectionne !== null && !$montantMatch;
                            @endphp
                            <div class="row g-0 border-bottom" wire:key="tx-{{ $txKey }}">
                                <div class="col-6 border-end"></div>
                                <div class="col-6 d-flex align-items-center px-3 py-2"
                                     style="{{ $montantMatch ? 'cursor:pointer;' : '' }}{{ $bgTx }}{{ $dimmed ? 'opacity:.4' : '' }}"
                                     @if ($montantMatch) wire:click="associer({{ $tx['id'] }}, '{{ $tx['type'] }}')" @endif>
                                    <div class="flex-grow-1 small">
                                        <span class="text-muted me-1">{{ \Carbon\Carbon::parse($tx['date'])->format('d/m') }}</span>
                                        {{ \Illuminate\Support\Str::limit($tx['libelle'] ?? '—', 30) }}
                                    </div>
                                    <div class="text-end text-nowrap small fw-semibold ms-2 {{ $tx['montant_signe'] < 0 ? 'text-danger' : 'text-success' }}">
                                        {{ $tx['montant_signe'] < 0 ? '-' : '+' }}{{ number_format(abs($tx['montant_signe']), 2, ',', ' ') }}
                                    </div>
                                    @if ($montantMatch)
                                        <i class="bi bi-plus-circle text-primary ms-1" style="font-size:.8rem" title="Associer"></i>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
            <div class="card-footer d-flex justify-content-end gap-2">
                <button wire:click="annulerPointage" class="btn btn-outline-secondary btn-sm">
                    Annuler
                </button>
                <button wire:click="validerAssociations"
                        class="btn btn-success btn-sm"
                        {{ count($associationsPointage) === 0 ? 'disabled' : '' }}>
                    <i class="bi bi-check2-all"></i> Pointer ({{ count($associationsPointage) }})
                </button>
            </div>
        </div>
    @endif

    {{-- Table des transactions --}}
    @if ($rapprochement->isEnCours() && ! $exerciceCloture && $this->canEdit)
        <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="masquerPointees"
                   wire:model.live="masquerPointees">
            <label class="form-check-label small text-muted" for="masquerPointees">
                Masquer les écritures pointées
            </label>
        </div>
    @endif
    <style>
        .remise-toggle-btn { line-height: 1; vertical-align: middle; }
        .remise-chevron { display: inline-block; transition: transform 0.15s ease; font-size: .65rem; }
        .remise-chevron.expanded { transform: rotate(90deg); }
        .remise-sub-row td { border-top: none; }
    </style>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Libellé</th>
                    <th>Tiers</th>
                    <th>Réf.</th>
                    <th>Mode</th>
                    <th class="text-end">Débit</th>
                    <th class="text-end">Crédit</th>
                    <th class="text-center">Pointé</th>
                </tr>
            </thead>

            @if ($transactions->isEmpty())
                <tbody style="color:#555">
                    <tr>
                        <td colspan="10" class="text-center text-muted">
                            Aucune transaction disponible pour ce compte.
                        </td>
                    </tr>
                </tbody>
            @else
                @foreach ($transactions as $tx)
                    @php
                        $hasSubRows = !empty($tx['sub_transactions']);
                        $isExpanded = $hasSubRows && isset($expandedRemises[$tx['id']]);
                        $modeBadge = match($tx['mode_paiement'] ?? null) {
                            'CHQ' => ['class' => 'bg-info text-dark',      'title' => 'Chèque'],
                            'VMT' => ['class' => 'bg-secondary',           'title' => 'Virement'],
                            'ESP' => ['class' => 'bg-success',             'title' => 'Espèces'],
                            'CB'  => ['class' => 'bg-primary',             'title' => 'Carte bancaire'],
                            'PRL' => ['class' => 'bg-warning text-dark',   'title' => 'Prélèvement'],
                            default => null,
                        };
                    @endphp

                    {{-- Ligne principale --}}
                    <tbody wire:key="{{ $tx['type'] }}-{{ $tx['id'] }}" style="color:#555">
                        <tr class="{{ $tx['pointe'] ? 'table-success' : '' }}">
                            <td class="text-muted small text-nowrap">
                                @if ($hasSubRows)
                                    <button wire:click="toggleRemiseExpand({{ $tx['id'] }})"
                                            class="btn btn-link p-0 remise-toggle-btn"
                                            style="color:#888;text-decoration:none"
                                            title="{{ $isExpanded ? 'Replier' : 'Déplier' }}">
                                        <i class="bi bi-chevron-right remise-chevron {{ $isExpanded ? 'expanded' : '' }}"></i>
                                    </button>
                                @endif
                                {{ $tx['id'] }}
                            </td>
                            <td class="text-nowrap small">{{ $tx['date']->format('d/m/Y') }}</td>
                            <td>
                                @switch($tx['type'])
                                    @case('depense')              <span class="badge bg-danger"    style="font-size:.7rem">Dépense</span>    @break
                                    @case('recette')              <span class="badge bg-success"   style="font-size:.7rem">Recette</span>    @break
                                    @case('virement_source')      <span class="badge bg-secondary" style="font-size:.7rem">Virement ↑</span> @break
                                    @case('virement_destination') <span class="badge bg-secondary" style="font-size:.7rem">Virement ↓</span> @break
                                    @case('remise')               <span class="badge"              style="background:#5a6e87;font-size:.7rem">Remise</span> @break
                                @endswitch
                            </td>
                            <td class="small">{{ $tx['label'] }}</td>
                            <td class="small text-muted">{{ $tx['tiers'] ?? '—' }}</td>
                            <td class="text-muted small">{{ $tx['reference'] ?? '—' }}</td>
                            <td class="small">
                                @if ($modeBadge)
                                    <span class="badge {{ $modeBadge['class'] }}"
                                          style="font-size:.65rem"
                                          title="{{ $modeBadge['title'] }}">{{ $tx['mode_paiement'] }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-end text-danger fw-semibold small text-nowrap">
                                @if ($tx['montant_signe'] < 0)
                                    {{ number_format(abs($tx['montant_signe']), 2, ',', ' ') }} €
                                @endif
                            </td>
                            <td class="text-end text-success fw-semibold small text-nowrap">
                                @if ($tx['montant_signe'] > 0)
                                    {{ number_format($tx['montant_signe'], 2, ',', ' ') }} €
                                @endif
                            </td>
                            <td class="text-center">
                                @if ($rapprochement->isEnCours() && ! $exerciceCloture && $this->canEdit)
                                    <input type="checkbox"
                                           wire:click="toggle('{{ $tx['type'] }}', {{ $tx['id'] }})"
                                           {{ $tx['pointe'] ? 'checked' : '' }}
                                           class="form-check-input">
                                @else
                                    <input type="checkbox"
                                           {{ $tx['pointe'] ? 'checked' : '' }}
                                           disabled
                                           class="form-check-input">
                                @endif
                            </td>
                        </tr>
                    </tbody>

                    {{-- Sous-lignes remise (dépliables) --}}
                    @if ($hasSubRows)
                        <tbody class="{{ $isExpanded ? '' : 'd-none' }}" style="color:#666">
                            @foreach ($tx['sub_transactions'] as $sub)
                                <tr class="remise-sub-row" style="background:{{ $tx['pointe'] ? '#d1e7dd' : '#f2f5f8' }}">
                                    <td class="text-muted small ps-3">↳ {{ $sub['id'] }}</td>
                                    <td class="text-nowrap small">{{ $sub['date']->format('d/m/Y') }}</td>
                                    <td></td>
                                    <td class="small">{{ $sub['label'] }}</td>
                                    <td class="small text-muted">{{ $sub['tiers'] ?? '—' }}</td>
                                    <td class="text-muted small">{{ $sub['reference'] ?? '—' }}</td>
                                    <td></td>
                                    <td class="text-end text-danger fw-semibold small text-nowrap">
                                        @if ($sub['montant_signe'] < 0)
                                            {{ number_format(abs($sub['montant_signe']), 2, ',', ' ') }} €
                                        @endif
                                    </td>
                                    <td class="text-end text-success fw-semibold small text-nowrap">
                                        @if ($sub['montant_signe'] > 0)
                                            {{ number_format($sub['montant_signe'], 2, ',', ' ') }} €
                                        @endif
                                    </td>
                                    <td></td>
                                </tr>
                            @endforeach
                        </tbody>
                    @endif
                @endforeach
            @endif
        </table>
    </div>
</div>
