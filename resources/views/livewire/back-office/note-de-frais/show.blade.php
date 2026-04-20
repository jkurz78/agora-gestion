<div>
    {{-- Flash messages --}}
    @if (session()->has('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
        </div>
    @endif
    @if (session()->has('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
        </div>
    @endif

    {{-- Fil d'Ariane --}}
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item">
                <a href="{{ route('comptabilite.ndf.index') }}">Notes de frais</a>
            </li>
            <li class="breadcrumb-item active" aria-current="page">
                Détail — {{ $ndf->libelle ?? 'NDF #'.$ndf->id }}
            </li>
        </ol>
    </nav>

    {{-- En-tête NDF --}}
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center"
             style="background-color:#3d5473;color:#fff;">
            <h2 class="h5 mb-0">
                <i class="bi bi-receipt me-2"></i>Note de frais
            </h2>
            @php $statut = $ndf->statut; @endphp
            @switch($statut->value)
                @case('soumise')
                    <span class="badge bg-warning text-dark fs-6">{{ $statut->label() }}</span>
                    @break
                @case('rejetee')
                    <span class="badge bg-danger fs-6">{{ $statut->label() }}</span>
                    @break
                @case('validee')
                    <span class="badge bg-success fs-6">{{ $statut->label() }}</span>
                    @break
                @case('payee')
                    <span class="badge bg-info text-dark fs-6">{{ $statut->label() }}</span>
                    @break
                @default
                    <span class="badge bg-secondary fs-6">{{ $statut->label() }}</span>
            @endswitch
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <dt class="small text-muted">Tiers</dt>
                    <dd class="mb-0 fw-semibold">
                        {{ $ndf->tiers?->prenom }} {{ $ndf->tiers?->nom }}
                    </dd>
                </div>
                <div class="col-md-2">
                    <dt class="small text-muted">Date</dt>
                    <dd class="mb-0">{{ $ndf->date?->format('d/m/Y') }}</dd>
                </div>
                <div class="col-md-4">
                    <dt class="small text-muted">Libellé</dt>
                    <dd class="mb-0">{{ $ndf->libelle ?? '—' }}</dd>
                </div>
                <div class="col-md-2">
                    <dt class="small text-muted">Total</dt>
                    <dd class="mb-0 fw-semibold">
                        {{ number_format((float) $ndf->lignes->sum('montant'), 2, ',', ' ') }} €
                    </dd>
                </div>
                @if ($ndf->submitted_at)
                    <div class="col-md-4">
                        <dt class="small text-muted">Soumise le</dt>
                        <dd class="mb-0">{{ $ndf->submitted_at->format('d/m/Y à H:i') }}</dd>
                    </div>
                @endif
                @if ($ndf->validee_at)
                    <div class="col-md-4">
                        <dt class="small text-muted">Validée le</dt>
                        <dd class="mb-0">{{ $ndf->validee_at->format('d/m/Y à H:i') }}</dd>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Panneau Transaction (statut Validée ou Payée) --}}
    @if (in_array($statut->value, ['validee', 'payee'], true) && $ndf->transaction_id)
        <div class="alert alert-success d-flex align-items-center gap-3 mb-4">
            <i class="bi bi-check-circle-fill fs-4"></i>
            <div>
                <strong>Transaction #{{ $ndf->transaction_id }}</strong> créée.
                <a href="{{ url('/comptabilite/transactions?edit='.$ndf->transaction_id) }}"
                   class="ms-2 btn btn-sm btn-outline-success"
                   target="_blank">
                    <i class="bi bi-box-arrow-up-right me-1"></i>Ouvrir la transaction comptable
                </a>
            </div>
        </div>
    @endif

    {{-- Motif de rejet (statut Rejetée) --}}
    @if ($statut->value === 'rejetee' && $ndf->motif_rejet)
        <div class="alert alert-danger mb-4">
            <strong>Motif du rejet :</strong> {{ $ndf->motif_rejet }}
        </div>
    @endif

    {{-- Lignes NDF --}}
    <div class="card mb-4">
        <div class="card-header" style="background-color:#3d5473;color:#fff;">
            <h3 class="h6 mb-0">Lignes de la note de frais</h3>
        </div>
        <div class="card-body p-0">
            @if ($ndf->lignes->isEmpty())
                <div class="p-3 text-muted">Aucune ligne.</div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover table-sm align-middle mb-0">
                        <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                            <tr>
                                <th>#</th>
                                <th>Libellé</th>
                                <th>Sous-catégorie</th>
                                <th>Opération</th>
                                <th>Séance</th>
                                <th class="text-end">Montant</th>
                                <th>Pièce jointe</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($ndf->lignes as $index => $ligne)
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>{{ $ligne->libelle ?? '—' }}</td>
                                    <td>{{ $ligne->sousCategorie?->nom ?? '—' }}</td>
                                    <td>{{ $ligne->operation?->nom ?? '—' }}</td>
                                    <td>{{ $ligne->seance ?? '—' }}</td>
                                    <td class="text-end" data-sort="{{ number_format((float) $ligne->montant, 2, '.', '') }}">
                                        {{ number_format((float) $ligne->montant, 2, ',', ' ') }} €
                                    </td>
                                    <td>
                                        @if ($ligne->piece_jointe_path)
                                            <a href="{{ route('comptabilite.ndf.piece-jointe', [$ndf, $ligne]) }}"
                                               target="_blank"
                                               class="btn btn-sm btn-outline-secondary">
                                                <i class="bi bi-paperclip me-1"></i>Ouvrir PJ
                                            </a>
                                        @else
                                            <span class="text-muted small">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- Actions — statut Soumise uniquement --}}
    @if ($statut->value === 'soumise')
        <div class="d-flex gap-2 mb-4">
            <button type="button"
                    class="btn btn-success"
                    wire:click="openMiniForm">
                <i class="bi bi-check-lg me-1"></i>Valider &amp; comptabiliser
            </button>
            <button type="button"
                    class="btn btn-danger"
                    wire:click="openRejectModal">
                <i class="bi bi-x-circle me-1"></i>Rejeter
            </button>
        </div>

        {{-- Mini-form de validation --}}
        @if ($showMiniForm)
            <div class="card border-success mb-4">
                <div class="card-header bg-success text-white">
                    <h4 class="h6 mb-0"><i class="bi bi-check-circle me-2"></i>Comptabiliser la note de frais</h4>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        {{-- Compte bancaire --}}
                        <div class="col-md-4">
                            <label for="compteId" class="form-label">Compte bancaire <span class="text-danger">*</span></label>
                            <select id="compteId"
                                    wire:model="compteId"
                                    class="form-select @error('compteId') is-invalid @enderror">
                                <option value="">— Sélectionner —</option>
                                @foreach ($comptesBancaires as $compte)
                                    <option value="{{ $compte->id }}">{{ $compte->nom }}</option>
                                @endforeach
                            </select>
                            @error('compteId')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        {{-- Mode de règlement --}}
                        <div class="col-md-4">
                            <label for="modePaiement" class="form-label">Mode de règlement <span class="text-danger">*</span></label>
                            <select id="modePaiement"
                                    wire:model="modePaiement"
                                    class="form-select @error('modePaiement') is-invalid @enderror">
                                @foreach ($modesPaiement as $mode)
                                    <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
                                @endforeach
                            </select>
                            @error('modePaiement')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        {{-- Date de comptabilisation --}}
                        <div class="col-md-4">
                            <label for="dateComptabilisation" class="form-label">Date de comptabilisation <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="date"
                                       id="dateComptabilisation"
                                       wire:model="dateComptabilisation"
                                       class="form-control @error('dateComptabilisation') is-invalid @enderror">
                                <button type="button"
                                        class="btn btn-outline-secondary"
                                        wire:click="setDateToday">
                                    Aujourd'hui
                                </button>
                                @error('dateComptabilisation')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <div class="mt-3 d-flex gap-2">
                        <button type="button"
                                class="btn btn-success"
                                wire:click="confirmValidation"
                                wire:loading.attr="disabled">
                            <span wire:loading wire:target="confirmValidation" class="spinner-border spinner-border-sm me-1"></span>
                            Confirmer la validation
                        </button>
                        <button type="button"
                                class="btn btn-outline-secondary"
                                wire:click="$set('showMiniForm', false)">
                            Annuler
                        </button>
                    </div>
                </div>
            </div>
        @endif
    @endif

    {{-- Lien retour liste --}}
    <div class="mt-2">
        <a href="{{ route('comptabilite.ndf.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Retour à la liste
        </a>
    </div>

    {{-- Modal Rejet Bootstrap --}}
    <div class="modal fade {{ $showRejectModal ? 'show d-block' : '' }}"
         id="rejectModal"
         tabindex="-1"
         role="dialog"
         aria-labelledby="rejectModalLabel"
         aria-modal="true"
         style="{{ $showRejectModal ? 'background:rgba(0,0,0,.5)' : '' }}">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="rejectModalLabel">
                        <i class="bi bi-x-circle text-danger me-2"></i>Rejeter la note de frais
                    </h5>
                    <button type="button"
                            class="btn-close"
                            wire:click="$set('showRejectModal', false)"
                            aria-label="Fermer"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="motifRejet" class="form-label">
                            Motif du rejet <span class="text-danger">*</span>
                        </label>
                        <textarea id="motifRejet"
                                  wire:model="motifRejet"
                                  rows="4"
                                  class="form-control @error('motifRejet') is-invalid @enderror"
                                  placeholder="Expliquez la raison du rejet…"></textarea>
                        @error('motifRejet')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button"
                            class="btn btn-outline-secondary"
                            wire:click="$set('showRejectModal', false)">
                        Annuler
                    </button>
                    <button type="button"
                            class="btn btn-danger"
                            wire:click="confirmRejection"
                            wire:loading.attr="disabled">
                        <span wire:loading wire:target="confirmRejection" class="spinner-border spinner-border-sm me-1"></span>
                        Confirmer le rejet
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
