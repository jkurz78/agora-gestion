<div>
    @if (! $showForm)
        {{-- Les boutons sont dans transaction-list.blade.php --}}
    @else
        <div class="position-fixed top-0 start-0 w-100 h-100" style="background:rgba(0,0,0,.5);z-index:1040;overflow-y:auto" wire:click.self="resetForm">
        <div class="container py-4">
        <div class="card mb-4">
            @php
                $formEntityLabel = match($sousCategorieFilter) {
                    'pour_dons'         => 'don',
                    'pour_cotisations'  => 'cotisation',
                    'pour_inscriptions' => 'inscription',
                    default             => null,
                };
            @endphp
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    @if($exerciceCloture)
                        Visualiser {{ $formEntityLabel ? ($formEntityLabel === 'cotisation' || $formEntityLabel === 'inscription' ? 'la ' : 'le ') . $formEntityLabel : ($type === 'depense' ? 'la dépense' : 'la recette') }}
                    @elseif($formEntityLabel)
                        {{ $transactionId ? 'Modifier le ' : ($formEntityLabel === 'cotisation' || $formEntityLabel === 'inscription' ? 'Nouvelle ' : 'Nouveau ') }}{{ $formEntityLabel }}
                    @else
                        {{ $transactionId ? 'Modifier la ' : 'Nouvelle ' }}{{ $type === 'depense' ? 'dépense' : 'recette' }}
                    @endif
                </h5>
                <button wire:click="resetForm" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-x-lg"></i> Annuler
                </button>
            </div>
            @if ($transactionId && $transaction_numero_piece)
                <div class="px-3 pt-2 text-muted small">
                    N° pièce : <strong>{{ $transaction_numero_piece }}</strong>
                </div>
            @endif
            <div class="card-body">
                @if ($transactionId && $linkedNdf)
                    <div class="alert alert-info d-flex align-items-center gap-2 mb-3">
                        <i class="bi bi-receipt fs-5"></i>
                        <div>
                            Cette transaction provient de la note de frais
                            <a href="{{ route('comptabilite.ndf.show', ['noteDeFrais' => $linkedNdf->id]) }}" target="_blank" class="fw-semibold">
                                NDF #{{ $linkedNdf->id }}
                            </a>
                            @if ($linkedNdf->libelle)({{ $linkedNdf->libelle }})@endif.
                        </div>
                    </div>
                @endif
                @if ($ocrMode && $ocrWaitingForFile)
                    <div class="text-center py-5">
                        <div class="mb-4">
                            <i class="bi bi-cloud-arrow-up" style="font-size:3rem;color:#6c757d"></i>
                            <p class="mt-2 text-muted">Sélectionnez la facture fournisseur à analyser</p>
                        </div>
                        <label class="btn btn-primary btn-lg mb-3">
                            <i class="bi bi-upload me-2"></i> Choisir un fichier
                            <input type="file" wire:model="pieceJointe" accept=".pdf,.jpg,.jpeg,.png" class="d-none"
                                   @change="const f = $event.target.files[0]; if (f) { sessionStorage.setItem('pj-ocr-preview-url', URL.createObjectURL(f)); sessionStorage.setItem('pj-ocr-preview-name', f.name); }">
                        </label>
                        <div wire:loading wire:target="pieceJointe" class="mt-2">
                            <div class="spinner-border spinner-border-sm text-primary"></div>
                            <span class="text-muted small">Upload en cours...</span>
                        </div>
                        @error('pieceJointe') <div class="text-danger small mt-2">{{ $message }}</div> @enderror
                    </div>
                @else
                @if ($ocrAnalyzing)
                    <div class="alert alert-info py-2 d-flex align-items-center gap-2">
                        <div class="spinner-border spinner-border-sm"></div>
                        Analyse de la facture en cours...
                    </div>
                @endif

                @if ($ocrError)
                    <div class="alert alert-danger py-2">
                        <i class="bi bi-exclamation-triangle"></i> {{ $ocrError }}
                        <div class="mt-2">
                            <button type="button" wire:click="retryOcr" class="btn btn-sm btn-outline-danger">
                                <i class="bi bi-arrow-clockwise"></i> Réessayer
                            </button>
                            <button type="button" wire:click="$set('ocrError', null)" class="btn btn-sm btn-outline-secondary">
                                Ignorer
                            </button>
                        </div>
                    </div>
                @endif

                @if (! empty($ocrWarnings))
                    <div class="alert alert-warning py-2 small">
                        @foreach ($ocrWarnings as $warning)
                            <div><i class="bi bi-exclamation-triangle"></i> {{ $warning }}</div>
                        @endforeach
                    </div>
                @endif

                @if(!$sousCategorieFilter)
                <div class="mb-3">
                    @if ($type === 'depense')
                        <span class="badge bg-danger fs-6">Dépense</span>
                    @else
                        <span class="badge bg-success fs-6">Recette</span>
                    @endif
                </div>
                @endif

                <form wire:submit="save">
                    @if ($isLocked && $isLockedByFacture)
                        <div class="alert alert-warning small py-2 mb-3">
                            <i class="bi bi-lock"></i> Cette transaction est verrouillée (rapprochement/remise + facture). Seuls le libellé et les notes peuvent être modifiés.
                        </div>
                    @elseif ($isLockedByFacture)
                        <div class="alert alert-warning small py-2 mb-3">
                            <i class="bi bi-lock"></i> Cette transaction est liée à une facture validée. Seuls le libellé et les notes peuvent être modifiés.
                        </div>
                    @endif
                    <div class="row g-3 mb-4">
                        <div class="col-md-2">
                            <label for="date" class="form-label">
                                Date <span class="text-danger">*</span>
                                @if ($isLocked) <i class="bi bi-lock text-warning" title="Champ verrouillé par un rapprochement"></i> @endif
                            </label>
                            <x-date-input name="date" wire:model="date" :value="$date" :disabled="$isLocked || $exerciceCloture" />
                            @error('date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-2">
                            <label for="reference" class="form-label">Référence</label>
                            <input type="text" wire:model="reference" id="reference"
                                   class="form-control @error('reference') is-invalid @enderror"
                                   {{ $exerciceCloture ? 'disabled' : '' }}>
                            @error('reference') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label for="libelle" class="form-label">Libellé</label>
                            <input type="text" wire:model="libelle" id="libelle"
                                   class="form-control @error('libelle') is-invalid @enderror"
                                   {{ $exerciceCloture ? 'disabled' : '' }}>
                            @error('libelle') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Tiers</label>
                            <livewire:tiers-autocomplete wire:model="tiers_id" filtre="{{ $type === 'depense' ? 'depenses' : 'recettes' }}" :defaultSearch="$ocrTiersNom ?? ''" :key="'transaction-tiers-'.($transactionId ?? 'new').'-'.($tiers_id ?? '0').'-'.($ocrTiersNom ?? '')" />
                            @error('tiers_id') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-2">
                            <label for="mode_paiement" class="form-label">Mode paiement <span class="text-danger">*</span></label>
                            <select wire:model="mode_paiement" id="mode_paiement"
                                    class="form-select @error('mode_paiement') is-invalid @enderror"
                                    {{ $exerciceCloture ? 'disabled' : '' }}>
                                <option value="">-- Choisir --</option>
                                @foreach ($modesPaiement as $mode)
                                    <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
                                @endforeach
                            </select>
                            @error('mode_paiement') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label for="compte_id" class="form-label">
                                Compte bancaire
                                @if ($isLocked || $isLockedByFacture) <i class="bi bi-lock text-warning" title="Champ verrouillé"></i> @endif
                            </label>
                            @if ($isLocked || $isLockedByFacture)
                                <input type="text" value="{{ \App\Models\CompteBancaire::find($compte_id)?->nom ?? '—' }}"
                                       class="form-control bg-light" disabled>
                            @else
                                <select wire:model="compte_id" id="compte_id" class="form-select"
                                        {{ $exerciceCloture ? 'disabled' : '' }}>
                                    <option value="">-- Aucun --</option>
                                    @foreach ($comptes as $compte)
                                        @if (! $compte->est_systeme || $type === 'recette')
                                            <option value="{{ $compte->id }}">{{ $compte->nom }}</option>
                                        @endif
                                    @endforeach
                                </select>
                            @endif
                        </div>
                        <div class="{{ $ocrMode ? 'col-md-2' : 'col-md-2' }}">
                            <label class="form-label">
                                Montant total
                                @if ($isLocked) <i class="bi bi-lock text-warning" title="Champ verrouillé par un rapprochement"></i> @endif
                            </label>
                            <div class="form-control bg-light fw-bold text-end">
                                {{ number_format($this->montantTotal, 2, ',', ' ') }} €
                            </div>
                        </div>
                        {{-- En mode OCR : PJ sur la même ligne que Montant total --}}
                        @if ($ocrMode && $type === 'depense' && ! $exerciceCloture)
                        <div class="col-md-3">
                            <label class="form-label"><i class="bi bi-paperclip"></i> Justificatif</label>
                            @if ($pieceJointe)
                                <div class="d-flex align-items-center gap-1">
                                    <span class="small text-success text-truncate" style="max-width:150px"><i class="bi bi-check-circle"></i> {{ $pieceJointe->getClientOriginalName() }}</span>
                                </div>
                            @endif
                        </div>
                        @endif
                        <div class="{{ $ocrMode ? 'col-md-8' : 'col-12' }}">
                            <label for="notes" class="form-label">Notes</label>
                            <input type="text" wire:model="notes" id="notes" class="form-control"
                                   {{ $exerciceCloture ? 'disabled' : '' }}>
                        </div>

                        {{-- Pièce jointe (dépenses uniquement, hors OCR mode qui l'affiche au-dessus) --}}
                        @if ($type === 'depense' && ! $exerciceCloture && ! $ocrMode)
                        <div class="col-12">
                            <label class="form-label"><i class="bi bi-paperclip"></i> Justificatif</label>

                            @if ($existingPieceJointeNom && ! $pieceJointe)
                                <div class="d-flex align-items-center gap-2">
                                    <span class="small text-muted">{{ $existingPieceJointeNom }}</span>
                                    <a href="{{ $existingPieceJointeUrl }}" target="_blank" class="btn btn-sm btn-outline-primary" title="Consulter">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    @if ($this->canEdit)
                                    <button type="button" wire:click="deletePieceJointe" wire:confirm="Supprimer le justificatif ?"
                                            class="btn btn-sm btn-outline-danger" title="Supprimer">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    @endif
                                    <label class="btn btn-sm btn-outline-secondary mb-0" title="Remplacer">
                                        <i class="bi bi-arrow-repeat"></i> Remplacer
                                        <input type="file" wire:model="pieceJointe" accept=".pdf,.jpg,.jpeg,.png" class="d-none">
                                    </label>
                                </div>
                            @else
                                <div class="d-flex align-items-center gap-2" x-data="{ tempUrl: null }">
                                    <label class="btn btn-sm btn-outline-secondary mb-0">
                                        <i class="bi bi-paperclip"></i> Joindre un justificatif
                                        <input type="file" wire:model="pieceJointe" accept=".pdf,.jpg,.jpeg,.png" class="d-none"
                                               @change="const f = $event.target.files[0]; if (f) { tempUrl = URL.createObjectURL(f); sessionStorage.setItem('pj-ocr-preview-url', tempUrl); }">
                                    </label>
                                    @if ($pieceJointe)
                                        <span class="small text-success"><i class="bi bi-check-circle"></i> {{ $pieceJointe->getClientOriginalName() }}</span>
                                        <a :href="tempUrl" target="_blank" class="btn btn-sm btn-outline-primary" title="Visualiser" x-show="tempUrl">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    @endif
                                    <div wire:loading wire:target="pieceJointe" class="spinner-border spinner-border-sm text-primary"></div>
                                </div>
                            @endif

                            @error('pieceJointe') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                        </div>
                        @endif
                    </div>

                    {{-- Lignes section --}}
                    <h6 class="mb-2">Lignes de {{ $formEntityLabel ?? ($type === 'depense' ? 'dépense' : 'recette') }}</h6>
                    @error('lignes')
                        <div class="alert alert-danger py-2">{{ $message }}</div>
                    @enderror

                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Sous-catégorie <span class="text-danger">*</span></th>
                                    <th>Opération</th>
                                    <th style="width: 100px;">Séance</th>
                                    <th style="width: 130px;">Montant <span class="text-danger">*</span></th>
                                    <th>Notes</th>
                                    <th style="width: 50px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($lignes as $index => $ligne)
                                    <tr wire:key="ligne-{{ $index }}">
                                        <td style="min-width:220px">
                                            @if ($isLockedByFacture)
                                                @php $sc = \App\Models\SousCategorie::find($ligne['sous_categorie_id']); @endphp
                                                <span class="form-control-plaintext">{{ $sc?->nom ?? '—' }}</span>
                                            @else
                                                <livewire:sous-categorie-autocomplete
                                                    :key="'sc-tx-'.$index.'-'.($sousCategorieFilter ?? 'all')"
                                                    wire:model="lignes.{{ $index }}.sous_categorie_id"
                                                    filtre="{{ $type }}"
                                                    :sousCategorieFlag="$sousCategorieFilter"
                                                />
                                            @endif
                                            @error('lignes.' . $index . '.sous_categorie_id')
                                                <div class="text-danger small mt-1">{{ $message }}</div>
                                            @enderror
                                        </td>
                                        <td>
                                            <select wire:model.live="lignes.{{ $index }}.operation_id"
                                                    class="form-select form-select-sm"
                                                    {{ $exerciceCloture || $isLockedByFacture ? 'disabled' : '' }}>
                                                <option value="">-- Aucune --</option>
                                                @foreach ($operations->groupBy(fn ($op) => $op->typeOperation?->nom ?? 'Sans type') as $typeName => $ops)
                                                    <optgroup label="{{ $typeName }}">
                                                        @foreach ($ops as $op)
                                                            <option value="{{ $op->id }}">{{ $op->nom }}</option>
                                                        @endforeach
                                                    </optgroup>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td>
                                            @php
                                                $selectedOp = $ligne['operation_id'] !== '' ? $operations->firstWhere('id', (int) $ligne['operation_id']) : null;
                                                $nbSeances = $selectedOp?->nombre_seances;
                                            @endphp
                                            @if ($nbSeances)
                                                <select wire:model="lignes.{{ $index }}.seance"
                                                        class="form-select form-select-sm"
                                                        {{ $exerciceCloture || $isLockedByFacture ? 'disabled' : '' }}>
                                                    <option value="">--</option>
                                                    @for ($s = 1; $s <= $nbSeances; $s++)
                                                        <option value="{{ $s }}">{{ $s }}</option>
                                                    @endfor
                                                </select>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($isLocked || $isLockedByFacture)
                                                <span class="form-control-plaintext">{{ number_format((float) ($ligne['montant'] ?? 0), 2, ',', ' ') }} €</span>
                                            @else
                                                <input type="number" wire:model.live="lignes.{{ $index }}.montant"
                                                       step="0.01" min="0.01"
                                                       class="form-control form-control-sm @error('lignes.' . $index . '.montant') is-invalid @enderror"
                                                       {{ $exerciceCloture ? 'disabled' : '' }}>
                                                @error('lignes.' . $index . '.montant')
                                                    <div class="invalid-feedback">{{ $message }}</div>
                                                @enderror
                                            @endif
                                        </td>
                                        <td>
                                            <input type="text" wire:model="lignes.{{ $index }}.notes"
                                                   class="form-control form-control-sm"
                                                   {{ $exerciceCloture ? 'disabled' : '' }}>
                                            {{-- PJ niveau ligne --}}
                                            @if (! $exerciceCloture)
                                            <div class="mt-1">
                                                @if (! empty($lignes[$index]['piece_jointe_upload']))
                                                    {{-- Nouveau fichier uploadé, pas encore sauvé --}}
                                                    <span class="badge bg-info text-nowrap" title="{{ $lignes[$index]['piece_jointe_upload']->getClientOriginalName() }}">
                                                        <i class="bi bi-paperclip"></i> {{ $lignes[$index]['piece_jointe_upload']->getClientOriginalName() }}
                                                    </span>
                                                    <button type="button" wire:click="$set('lignes.{{ $index }}.piece_jointe_upload', null)" class="btn btn-sm btn-link text-danger p-0 ms-1" title="Annuler l'upload">
                                                        <i class="bi bi-x-circle"></i>
                                                    </button>
                                                @elseif (! empty($lignes[$index]['piece_jointe_path']) && empty($lignes[$index]['piece_jointe_remove']))
                                                    {{-- Fichier existant : icône trombone + dropdown actions --}}
                                                    <div class="dropdown d-inline-block">
                                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="dropdown" aria-expanded="false"
                                                                title="{{ $lignes[$index]['piece_jointe_filename'] ?? 'Pièce jointe' }}">
                                                            <i class="bi bi-paperclip"></i>
                                                        </button>
                                                        <ul class="dropdown-menu">
                                                            <li>
                                                                <a class="dropdown-item" href="{{ $lignes[$index]['piece_jointe_existing_url'] }}" target="_blank">
                                                                    <i class="bi bi-eye me-2"></i>Consulter
                                                                </a>
                                                            </li>
                                                            @if (! $isLockedByFacture)
                                                            <li>
                                                                <label class="dropdown-item mb-0" style="cursor:pointer;">
                                                                    <i class="bi bi-arrow-repeat me-2"></i>Remplacer
                                                                    <input type="file" wire:model="lignes.{{ $index }}.piece_jointe_upload" accept=".pdf,.jpg,.jpeg,.png" class="d-none">
                                                                </label>
                                                            </li>
                                                            <li><hr class="dropdown-divider"></li>
                                                            <li>
                                                                <button type="button" class="dropdown-item text-danger" wire:click="$set('lignes.{{ $index }}.piece_jointe_remove', true)">
                                                                    <i class="bi bi-trash me-2"></i>Supprimer
                                                                </button>
                                                            </li>
                                                            @endif
                                                        </ul>
                                                    </div>
                                                @elseif (! empty($lignes[$index]['piece_jointe_remove']))
                                                    <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle"></i> Sera supprimée</span>
                                                    <button type="button" wire:click="$set('lignes.{{ $index }}.piece_jointe_remove', false)" class="btn btn-sm btn-link p-0 ms-1" title="Annuler la suppression">
                                                        <i class="bi bi-arrow-counterclockwise"></i>
                                                    </button>
                                                @else
                                                    {{-- Aucune PJ --}}
                                                    <label class="btn btn-sm btn-outline-secondary" title="Ajouter un justificatif">
                                                        <i class="bi bi-paperclip"></i>
                                                        <input type="file" wire:model="lignes.{{ $index }}.piece_jointe_upload" accept=".pdf,.jpg,.jpeg,.png" class="d-none">
                                                    </label>
                                                @endif
                                                @error("lignes.{$index}.piece_jointe_upload") <div class="text-danger small">{{ $message }}</div> @enderror
                                                <span wire:loading wire:target="lignes.{{ $index }}.piece_jointe_upload" class="spinner-border spinner-border-sm ms-1"></span>
                                            </div>
                                            @elseif (! empty($lignes[$index]['piece_jointe_path']))
                                                {{-- Mode lecture seule : lien consulter uniquement --}}
                                                <div class="mt-1">
                                                    <a href="{{ $lignes[$index]['piece_jointe_existing_url'] }}" target="_blank" class="btn btn-sm btn-outline-primary">
                                                        <i class="bi bi-paperclip"></i> Consulter
                                                    </a>
                                                </div>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            @if (! $isLocked && ! $isLockedByFacture && ! $exerciceCloture)
                                                <button type="button" wire:click="removeLigne({{ $index }})"
                                                        class="btn btn-sm btn-outline-danger">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            @endif
                                            @if ($isLocked && ! $isLockedByFacture && ! $exerciceCloture && ($ligne['id'] ?? null) !== null)
                                                <button type="button"
                                                        wire:click="ouvrirVentilation({{ $ligne['id'] }})"
                                                        class="btn btn-sm btn-outline-warning ms-1">
                                                    <i class="bi bi-scissors"></i>
                                                    {{ in_array($ligne['id'], $lignesAffectations) ? 'Modifier la ventilation' : 'Ventiler' }}
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-muted text-center">Aucune ligne. Cliquez sur "Ajouter une ligne".</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3" class="text-end fw-bold">Total lignes :</td>
                                    <td class="fw-bold">
                                        {{ number_format($this->montantTotal, 2, ',', ' ') }} &euro;
                                    </td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    @if ($ventilationLigneId && ! $exerciceCloture)
                        <div class="border border-primary border-2 rounded p-3 mb-3" style="background:#f0f7ff">
                            <div class="fw-bold text-primary mb-2">
                                <i class="bi bi-scissors"></i>
                                Ventilation — {{ $ventilationLigneSousCategorie }} ({{ number_format((float) $ventilationLigneMontant, 2, ',', ' ') }} €)
                            </div>

                            <table class="table table-sm mb-2">
                                <thead class="table-light">
                                    <tr>
                                        <th>Opération</th>
                                        <th style="width:100px">Séance</th>
                                        <th style="width:120px">Montant *</th>
                                        <th>Notes</th>
                                        <th style="width:40px"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($affectations as $ai => $aff)
                                    <tr wire:key="aff-{{ $ai }}">
                                        <td>
                                            <select wire:model.live="affectations.{{ $ai }}.operation_id" class="form-select form-select-sm">
                                                <option value="">— Aucune (reste non affecté) —</option>
                                                @foreach ($operations->groupBy(fn ($op) => $op->typeOperation?->nom ?? 'Sans type') as $typeName => $ops)
                                                    <optgroup label="{{ $typeName }}">
                                                        @foreach ($ops as $op)
                                                            <option value="{{ $op->id }}">{{ $op->nom }}</option>
                                                        @endforeach
                                                    </optgroup>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td>
                                            @php
                                                $selOp = $aff['operation_id'] !== '' ? $operations->firstWhere('id', (int) $aff['operation_id']) : null;
                                            @endphp
                                            @if ($selOp?->nombre_seances)
                                                <select wire:model="affectations.{{ $ai }}.seance" class="form-select form-select-sm">
                                                    <option value="">--</option>
                                                    @for ($s = 1; $s <= $selOp->nombre_seances; $s++)
                                                        <option value="{{ $s }}">{{ $s }}</option>
                                                    @endfor
                                                </select>
                                            @endif
                                        </td>
                                        <td>
                                            <input type="number" wire:model.live="affectations.{{ $ai }}.montant"
                                                   step="0.01" min="0.01"
                                                   class="form-control form-control-sm text-end @error('affectations.'.$ai.'.montant') is-invalid @enderror">
                                            @error('affectations.'.$ai.'.montant') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                        </td>
                                        <td>
                                            <input type="text" wire:model="affectations.{{ $ai }}.notes" class="form-control form-control-sm">
                                        </td>
                                        <td class="text-center">
                                            <button type="button" wire:click="removeAffectation({{ $ai }})" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>

                            @php
                                $resteEn100 = (int) round((float) $ventilationLigneMontant * 100)
                                            - (int) round(collect($affectations)->sum(fn($a) => (float)($a['montant'] ?? 0)) * 100);
                                $reste = $resteEn100 / 100;
                            @endphp

                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <button type="button" wire:click="addAffectation" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-plus-lg"></i> Ajouter une ligne
                                </button>
                                <span class="badge {{ $resteEn100 === 0 ? 'bg-success' : 'bg-warning text-dark' }}">
                                    Reste : {{ number_format($reste, 2, ',', ' ') }} €
                                </span>
                                <div class="ms-auto d-flex gap-2">
                                    @if ($ventilationHasAffectations)
                                    <button type="button" wire:click="supprimerVentilation" class="btn btn-sm btn-outline-danger"
                                            wire:confirm="Supprimer toute la ventilation ?">
                                        Annuler la ventilation
                                    </button>
                                    @endif
                                    <button type="button" wire:click="fermerVentilation" class="btn btn-sm btn-secondary">Fermer</button>
                                    <button type="button" wire:click="saveVentilation"
                                            class="btn btn-sm btn-success"
                                            @if($resteEn100 !== 0) disabled title="La somme doit être exacte" @endif>
                                        <i class="bi bi-check-lg"></i> Enregistrer
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endif

                    <div class="d-flex gap-2">
                        @if (! $isLocked && ! $isLockedByFacture && ! $exerciceCloture && $this->canEdit)
                            <button type="button" wire:click="addLigne" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-plus-lg"></i> Ajouter une ligne
                            </button>
                        @endif
                        <div class="ms-auto">
                            <button type="button" wire:click="resetForm" class="btn btn-secondary">{{ $exerciceCloture || ! $this->canEdit ? 'Fermer' : 'Annuler' }}</button>
                            @if (! $exerciceCloture && $this->canEdit)
                            <button type="submit" class="btn btn-success"
                                    @if ($isLocked || $isLockedByFacture) title="Certains champs sont verrouillés (facture validée ou rapprochement)." @endif>
                                {{ $transactionId ? 'Mettre à jour' : 'Enregistrer' }}
                            </button>
                            @endif
                        </div>
                    </div>
                </form>

                @if ($ocrMode && ($pieceJointe || $existingPieceJointeNom))
                    <hr class="my-3">
                    <div style="height:40vh" x-data="{ pUrl: @js($incomingDocumentPreviewUrl) || sessionStorage.getItem('pj-ocr-preview-url') }">
                        <template x-if="pUrl">
                            <div class="position-relative rounded" style="height:100%;overflow:hidden">
                                <iframe :src="pUrl + '#navpanes=0'" style="position:absolute;top:0;left:0;right:0;bottom:0;border:none;width:100%;height:100%"></iframe>
                            </div>
                        </template>
                    </div>
                @endif
                @endif
            </div>
        </div>
        </div>
        </div>
    @endif

</div>
