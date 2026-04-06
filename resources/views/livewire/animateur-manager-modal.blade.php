@if($showModal)
@php $clearSS = "sessionStorage.removeItem('pj-preview-url'); sessionStorage.removeItem('pj-preview-mime'); sessionStorage.removeItem('pj-preview-name')"; @endphp
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)" wire:click.self="closeModal" @click.self="{{ $clearSS }}">
    <div class="modal-dialog {{ ($modalStep === 'form' && ($previewUrl || $modalPieceJointe)) ? 'modal-xl' : 'modal-lg' }}">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title">
                    @if($modalStep === 'upload')
                        Joindre un justificatif
                    @else
                        {{ $isEditing ? 'Modifier la facture' : 'Nouvelle facture d\'encadrement' }}
                    @endif
                </h6>
                <button type="button" class="btn-close" wire:click="closeModal" @click="{{ $clearSS }}"></button>
            </div>

            @if($modalStep === 'upload')
                {{-- Step 1: Upload — passe automatiquement au formulaire dès upload terminé --}}
                <div class="modal-body text-center py-5">
                    <div class="mb-4">
                        <i class="bi bi-cloud-arrow-up" style="font-size:3rem;color:#6c757d"></i>
                        <p class="mt-2 text-muted">Uploadez la facture du fournisseur pour l'afficher pendant la saisie</p>
                    </div>

                    <label class="btn btn-primary btn-lg mb-3">
                        <i class="bi bi-upload me-2"></i> Choisir un fichier
                        <input type="file" wire:model="modalPieceJointe" accept=".pdf,.jpg,.jpeg,.png" class="d-none"
                               @change="
                                   const file = $event.target.files[0];
                                   if (file) {
                                       sessionStorage.setItem('pj-preview-url', URL.createObjectURL(file));
                                       sessionStorage.setItem('pj-preview-mime', file.type);
                                       sessionStorage.setItem('pj-preview-name', file.name);
                                   }
                               ">
                    </label>
                    <div wire:loading wire:target="modalPieceJointe" class="mt-2">
                        <div class="spinner-border spinner-border-sm text-primary"></div>
                        <span class="text-muted small">Upload en cours...</span>
                    </div>

                    @error('modalPieceJointe') <div class="text-danger small mt-2">{{ $message }}</div> @enderror

                    <div class="mt-4">
                        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="skipUpload" @click="{{ $clearSS }}">
                            Ignorer <i class="bi bi-arrow-right ms-1"></i>
                        </button>
                    </div>
                </div>

            @else
                {{-- Step 2: Formulaire (avec ou sans split view) --}}
                <div class="modal-body" style="overflow-x:hidden"
                     x-data="{
                         previewUrl: sessionStorage.getItem('pj-preview-url') || @js($previewUrl),
                         previewMime: sessionStorage.getItem('pj-preview-mime') || @js($previewMime),
                         previewName: sessionStorage.getItem('pj-preview-name') || @js($existingPieceJointeNom),
                         scale: 1
                     }">

                    <div class="row g-0">
                        {{-- Colonne gauche : prévisualisation --}}
                        <template x-if="previewUrl">
                        <div class="col-md-5 pe-2">
                            <div class="d-flex flex-column" style="height:70vh">
                                <template x-if="previewMime && previewMime.startsWith('image/')">
                                    <div class="flex-grow-1 overflow-auto text-center border rounded p-2" style="min-height:0">
                                        <div class="mb-2">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="scale = Math.max(0.25, scale - 0.25)"><i class="bi bi-dash-lg"></i></button>
                                            <span class="mx-2 small" x-text="Math.round(scale * 100) + '%'"></span>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="scale = Math.min(3, scale + 0.25)"><i class="bi bi-plus-lg"></i></button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary ms-1" @click="scale = 1">1:1</button>
                                        </div>
                                        <img :src="previewUrl" :style="'transform: scale(' + scale + '); transform-origin: top center'" class="img-fluid">
                                    </div>
                                </template>
                                <template x-if="!previewMime || !previewMime.startsWith('image/')">
                                    <div class="flex-grow-1 rounded position-relative" style="min-height:0">
                                        <iframe :src="previewUrl + '#navpanes=0'" style="position:absolute;top:0;left:0;right:0;bottom:0;border:none;width:100%;height:100%"></iframe>
                                    </div>
                                </template>

                                <div class="text-center py-1 small text-muted" x-text="previewName"></div>
                            </div>
                        </div>
                        </template>

                        {{-- Colonne droite (ou pleine largeur) : formulaire --}}
                        <div :class="previewUrl ? 'col-md-7' : 'col-12'">
                            {{-- Error message --}}
                            @if($errorMessage)
                                <div class="alert alert-danger py-2 small">{{ $errorMessage }}</div>
                            @endif

                            {{-- Validation errors --}}
                            @if($errors->any())
                                <div class="alert alert-danger py-2 small">
                                    <ul class="mb-0 ps-3">
                                        @foreach($errors->all() as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            {{-- Tiers (read-only) --}}
                            <div class="row mb-3">
                                <div class="col-12">
                                    <label class="form-label fw-medium" style="font-size:13px">Tiers</label>
                                    <input type="text" class="form-control form-control-sm" value="{{ $modalTiersLabel }}" disabled>
                                </div>
                            </div>

                            {{-- Date + Reference + Mode paiement + Compte --}}
                            <div class="row mb-3 g-2">
                                <div class="col-md-3">
                                    <label class="form-label" style="font-size:12px">Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control form-control-sm" wire:model="modalDate">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" style="font-size:12px">N° facture <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-sm" wire:model="modalReference" placeholder="FA-001"
                                           x-init="$nextTick(() => $el.focus())" autofocus>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" style="font-size:12px">Mode paiement</label>
                                    <select class="form-select form-select-sm" wire:model="modalModePaiement">
                                        <option value="">--</option>
                                        @foreach($modesPaiement as $mode)
                                            <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" style="font-size:12px">Compte bancaire</label>
                                    <select class="form-select form-select-sm" wire:model="modalCompteId">
                                        <option value="">--</option>
                                        @foreach($comptes as $compte)
                                            <option value="{{ $compte->id }}">{{ $compte->nom }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            {{-- Lines table --}}
                            <label class="form-label fw-medium" style="font-size:13px">Lignes de d&eacute;pense</label>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered mb-0" style="font-size:12px">
                                    <thead>
                                        <tr style="background:#3d5473;color:#fff">
                                            <th style="min-width:160px">Op&eacute;ration</th>
                                            <th style="min-width:80px">S&eacute;ance</th>
                                            <th style="min-width:200px">Sous-cat&eacute;gorie</th>
                                            <th style="min-width:90px">Montant</th>
                                            <th style="width:40px"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($modalLignes as $idx => $ligne)
                                            <tr wire:key="modal-ligne-{{ $idx }}">
                                                <td>
                                                    <select class="form-select form-select-sm" wire:model="modalLignes.{{ $idx }}.operation_id" style="font-size:11px">
                                                        <option value="">--</option>
                                                        @foreach($this->modalOperations as $op)
                                                            <option value="{{ $op['id'] }}">{{ $op['nom'] }}</option>
                                                        @endforeach
                                                    </select>
                                                </td>
                                                <td>
                                                    @php
                                                        $opId = $ligne['operation_id'] ?? null;
                                                        $nbSeances = null;
                                                        if ($opId) {
                                                            foreach ($this->modalOperations as $op) {
                                                                if ((int) $op['id'] === (int) $opId) {
                                                                    $nbSeances = $op['nombre_seances'];
                                                                    break;
                                                                }
                                                            }
                                                        }
                                                    @endphp
                                                    @if($nbSeances)
                                                        <select class="form-select form-select-sm" wire:model="modalLignes.{{ $idx }}.seance" style="font-size:11px">
                                                            <option value="">--</option>
                                                            @for($s = 1; $s <= $nbSeances; $s++)
                                                                <option value="{{ $s }}">S{{ $s }}</option>
                                                            @endfor
                                                        </select>
                                                    @else
                                                        <input type="number" class="form-control form-control-sm" wire:model="modalLignes.{{ $idx }}.seance" min="1" style="font-size:11px" placeholder="N&deg;">
                                                    @endif
                                                </td>
                                                <td>
                                                    <livewire:sous-categorie-autocomplete
                                                        wire:model="modalLignes.{{ $idx }}.sous_categorie_id"
                                                        filtre="depense"
                                                        :key="'sc-ac-'.$idx.'-'.($ligne['sous_categorie_id'] ?? 'null')"
                                                    />
                                                </td>
                                                <td>
                                                    <input type="number" step="0.01" min="0.01"
                                                           class="form-control form-control-sm text-end"
                                                           wire:model="modalLignes.{{ $idx }}.montant"
                                                           style="font-size:12px"
                                                           placeholder="0,00">
                                                </td>
                                                <td class="text-center align-middle">
                                                    @if(count($modalLignes) > 1)
                                                        <button type="button" class="btn btn-sm p-0" style="color:#dc3545;font-size:14px;border:none;background:none"
                                                                wire:click="removeModalLigne({{ $idx }})"
                                                                title="Supprimer cette ligne">&times;</button>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <div class="d-flex gap-2 mt-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="addModalLigne">
                                    <i class="bi bi-plus"></i> Ajouter une ligne
                                </button>
                                <template x-if="!previewUrl">
                                    <label class="btn btn-sm btn-outline-secondary mb-0">
                                        <i class="bi bi-paperclip"></i> Joindre un justificatif
                                        <input type="file" wire:model="modalPieceJointe" accept=".pdf,.jpg,.jpeg,.png" class="d-none"
                                               @change="
                                                   const file = $event.target.files[0];
                                                   if (file) {
                                                       const url = URL.createObjectURL(file);
                                                       sessionStorage.setItem('pj-preview-url', url);
                                                       sessionStorage.setItem('pj-preview-mime', file.type);
                                                       sessionStorage.setItem('pj-preview-name', file.name);
                                                       previewUrl = url;
                                                       previewMime = file.type;
                                                       previewName = file.name;
                                                   }
                                               ">
                                    </label>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer py-2 d-flex justify-content-between align-items-center">
                    <div style="font-size:13px">
                        @php
                            $modalTotal = 0;
                            foreach ($modalLignes as $l) {
                                $modalTotal += (float) ($l['montant'] ?? 0);
                            }
                        @endphp
                        <strong>Total : {{ number_format($modalTotal, 2, ',', "\u{202F}") }} &euro;</strong>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="closeModal" @click="{{ $clearSS }}">Annuler</button>
                        <button type="button" class="btn btn-sm btn-primary" wire:click="saveTransaction"
                                wire:loading.attr="disabled" wire:target="saveTransaction"
                                @click="{{ $clearSS }}">
                            <span wire:loading.remove wire:target="saveTransaction">
                                <i class="bi bi-check-lg me-1"></i>{{ $isEditing ? 'Mettre &agrave; jour' : 'Enregistrer' }}
                            </span>
                            <span wire:loading wire:target="saveTransaction">
                                <i class="bi bi-hourglass-split me-1"></i>En cours...
                            </span>
                        </button>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
@endif
