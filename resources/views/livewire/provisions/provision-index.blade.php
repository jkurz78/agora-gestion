<div>
    {{-- Flash message --}}
    @if($flashMessage)
        <div class="alert alert-{{ $flashType }} alert-dismissible fade show">
            {{ $flashMessage }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" wire:click="$set('flashMessage', '')"></button>
        </div>
    @endif

    {{-- Title --}}
    <h5 class="fw-bold mb-3">Écritures de provisions — {{ $exerciceLabel }}</h5>

    {{-- Exercice clôturé alert --}}
    @if($isCloture)
        <div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
            <i class="bi bi-lock-fill"></i>
            <span>Cet exercice est <strong>clôturé</strong>. Les modifications ne sont pas autorisées.</span>
        </div>
    @endif

    {{-- Toolbar --}}
    <div class="mb-3 d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <div></div>
        @if(!$isCloture)
            <button class="btn btn-primary btn-sm" wire:click="openCreate">
                <i class="bi bi-plus-lg"></i> Ajouter une provision
            </button>
        @endif
    </div>

    {{-- Table --}}
    <div class="table-responsive">
        <table class="table table-sm table-striped table-hover" id="provTable" wire:ignore.self>
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    <th class="sortable" data-col="0" style="cursor:pointer;user-select:none">Libellé <i class="bi bi-arrow-down-up" style="font-size:.7rem"></i></th>
                    <th class="sortable" data-col="1" style="cursor:pointer;user-select:none">Sous-catégorie <i class="bi bi-arrow-down-up" style="font-size:.7rem"></i></th>
                    <th class="sortable" data-col="2" style="cursor:pointer;user-select:none">Type <i class="bi bi-arrow-down-up" style="font-size:.7rem"></i></th>
                    <th class="sortable text-end" data-col="3" style="cursor:pointer;user-select:none">Montant <i class="bi bi-arrow-down-up" style="font-size:.7rem"></i></th>
                    <th class="sortable" data-col="4" style="cursor:pointer;user-select:none">Tiers <i class="bi bi-arrow-down-up" style="font-size:.7rem"></i></th>
                    <th class="sortable" data-col="5" style="cursor:pointer;user-select:none">Opération <i class="bi bi-arrow-down-up" style="font-size:.7rem"></i></th>
                    <th class="sortable text-center" data-col="6" style="cursor:pointer;user-select:none">Séance <i class="bi bi-arrow-down-up" style="font-size:.7rem"></i></th>
                    <th class="text-center" style="width:40px">PJ</th>
                    <th style="width:90px" class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($provisions as $provision)
                    <tr wire:key="prov-{{ $provision->id }}">
                        <td>{{ $provision->libelle }}</td>
                        <td>{{ $provision->sousCategorie?->nom ?? '—' }}</td>
                        <td>
                            @if($provision->type === \App\Enums\TypeTransaction::Depense)
                                <span class="badge bg-danger">Dépense</span>
                            @else
                                <span class="badge bg-success">Recette</span>
                            @endif
                        </td>
                        <td class="text-end" data-sort="{{ $provision->montant }}">
                            {{ number_format((float) $provision->montant, 2, ',', ' ') }} €
                        </td>
                        <td>{{ $provision->tiers?->displayName() ?? '—' }}</td>
                        <td>{{ $provision->operation?->nom ?? '—' }}</td>
                        <td class="text-center">{{ $provision->seance ?? '—' }}</td>
                        <td class="text-center">
                            @if($provision->hasPieceJointe())
                                <i class="bi bi-paperclip" title="{{ $provision->piece_jointe_nom }}"></i>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-center">
                            <div class="d-flex gap-1 justify-content-center">
                                <button class="btn btn-sm btn-outline-primary"
                                        wire:click="openEdit({{ $provision->id }})"
                                        style="padding:.15rem .35rem;font-size:.75rem"
                                        title="Modifier"
                                        @if($isCloture) disabled @endif>
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger"
                                        wire:click="delete({{ $provision->id }})"
                                        wire:confirm="Supprimer cette provision ?"
                                        style="padding:.15rem .35rem;font-size:.75rem"
                                        title="Supprimer"
                                        @if($isCloture) disabled @endif>
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="text-muted">Aucune écriture de provision enregistrée pour cet exercice.</td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="fw-semibold">
                    <td colspan="3">Totaux</td>
                    <td class="text-end" colspan="6">
                        <span class="text-danger me-3">Charges : {{ number_format($totalDepenses, 2, ',', ' ') }} €</span>
                        <span class="text-success">Produits : {{ number_format($totalRecettes, 2, ',', ' ') }} €</span>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>

    {{-- ═══════════════════════════════════════════════════════════
         MODAL CREATE/EDIT
         ═══════════════════════════════════════════════════════════ --}}
    @if($showModal)
        <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center"
             style="background:rgba(0,0,0,.4);z-index:2000"
             wire:click.self="$set('showModal', false)">
            <div class="bg-white rounded p-4 shadow" style="width:600px;max-width:95vw;max-height:90vh;overflow-y:auto">
                <h5 class="fw-bold mb-3">
                    {{ $editingId ? 'Modifier la provision' : 'Ajouter une provision' }}
                </h5>

                {{-- Libellé --}}
                <div class="mb-3">
                    <label class="form-label small">Libellé <span class="text-danger">*</span></label>
                    <input type="text" wire:model="libelle"
                           class="form-control form-control-sm @error('libelle') is-invalid @enderror"
                           maxlength="255">
                    @error('libelle') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                {{-- Sous-catégorie + Type --}}
                <div class="row g-2 mb-3">
                    <div class="col-md-8">
                        <label class="form-label small">Sous-catégorie <span class="text-danger">*</span></label>
                        <select wire:model="sous_categorie_id"
                                class="form-select form-select-sm @error('sous_categorie_id') is-invalid @enderror">
                            <option value="">— Choisir —</option>
                            @foreach ($categories as $cat)
                                <optgroup label="{{ $cat->nom }} ({{ $cat->type->label() }})">
                                    @foreach ($cat->sousCategories as $sc)
                                        <option value="{{ $sc->id }}">{{ $sc->nom }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        @error('sous_categorie_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Type <span class="text-danger">*</span></label>
                        <select wire:model="type"
                                class="form-select form-select-sm @error('type') is-invalid @enderror">
                            <option value="">— Choisir —</option>
                            <option value="depense">Dépense (charge)</option>
                            <option value="recette">Recette (produit)</option>
                        </select>
                        @error('type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>

                {{-- Montant --}}
                <div class="mb-3">
                    <label class="form-label small">Montant <span class="text-danger">*</span></label>
                    <div class="input-group input-group-sm">
                        <input type="number" wire:model="montant" step="0.01"
                               class="form-control form-control-sm @error('montant') is-invalid @enderror">
                        <span class="input-group-text">€</span>
                    </div>
                    @error('montant') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                {{-- Tiers --}}
                <div class="mb-3">
                    <label class="form-label small">Tiers</label>
                    <livewire:tiers-autocomplete wire:model="tiers_id" filtre="tous" :key="'prov-tiers-'.($editingId ?? 'new').'-'.($tiers_id ?? '0')" />
                    @error('tiers_id') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                {{-- Opération --}}
                <div class="mb-3">
                    <label class="form-label small">Opération</label>
                    <select wire:model.live="operation_id"
                            class="form-select form-select-sm @error('operation_id') is-invalid @enderror">
                        <option value="">— Aucune —</option>
                        @foreach ($operations as $op)
                            <option value="{{ $op->id }}">{{ $op->nom }}</option>
                        @endforeach
                    </select>
                    @error('operation_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                {{-- Séance (visible uniquement si opération sélectionnée) --}}
                @if($operation_id !== '')
                    <div class="mb-3">
                        <label class="form-label small">Séance n°</label>
                        <input type="number" wire:model="seance" min="1"
                               class="form-control form-control-sm @error('seance') is-invalid @enderror">
                        @error('seance') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                @endif

                {{-- Notes --}}
                <div class="mb-3">
                    <label class="form-label small">Notes</label>
                    <textarea wire:model="notes" rows="3"
                              class="form-control form-control-sm @error('notes') is-invalid @enderror"
                              maxlength="2000"></textarea>
                    @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                {{-- Pièce jointe --}}
                <div class="mb-3">
                    <label class="form-label small">Pièce jointe</label>
                    <input type="file" wire:model="piece_jointe"
                           class="form-control form-control-sm @error('piece_jointe') is-invalid @enderror">
                    @error('piece_jointe') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    @if($editingId && \App\Models\Provision::find($editingId)?->hasPieceJointe())
                        <div class="form-text text-muted">
                            <i class="bi bi-paperclip"></i>
                            Fichier existant : {{ \App\Models\Provision::find($editingId)->piece_jointe_nom }}
                            (laisser vide pour conserver)
                        </div>
                    @endif
                </div>

                {{-- Actions --}}
                <div class="d-flex gap-2 justify-content-end mt-4">
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            wire:click="$set('showModal', false)">Annuler</button>
                    <button type="button" class="btn btn-sm btn-primary" wire:click="save">
                        <i class="bi bi-check-lg"></i> Enregistrer
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ═══════════════════════════════════════════════════════════
         JS: SORTING (côté client)
         ═══════════════════════════════════════════════════════════ --}}
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const table = document.getElementById('provTable');
            if (!table) return;

            const sortHeaders = table.querySelectorAll('th.sortable');
            let currentCol = null;
            let ascending = true;

            sortHeaders.forEach(function (th) {
                th.addEventListener('click', function () {
                    const col = parseInt(this.dataset.col);
                    if (currentCol === col) {
                        ascending = !ascending;
                    } else {
                        currentCol = col;
                        ascending = true;
                    }

                    const tbody = table.querySelector('tbody');
                    const rows = Array.from(tbody.querySelectorAll('tr[wire\\:key]'));

                    rows.sort(function (a, b) {
                        const aCell = a.children[col];
                        const bCell = b.children[col];
                        if (!aCell || !bCell) return 0;
                        const aVal = (aCell.dataset.sort || aCell.textContent || '').trim().toLowerCase();
                        const bVal = (bCell.dataset.sort || bCell.textContent || '').trim().toLowerCase();
                        const result = aVal.localeCompare(bVal, 'fr');
                        return ascending ? result : -result;
                    });

                    rows.forEach(function (row) { tbody.appendChild(row); });

                    sortHeaders.forEach(function (h) {
                        const icon = h.querySelector('i');
                        if (icon) icon.className = 'bi bi-arrow-down-up';
                    });
                    const icon = th.querySelector('i');
                    if (icon) {
                        icon.className = ascending ? 'bi bi-arrow-down' : 'bi bi-arrow-up';
                    }
                });
            });

            function reApplySort() {
                if (currentCol === null) return;
                const tbody = table.querySelector('tbody');
                const rows = Array.from(tbody.querySelectorAll('tr[wire\\:key]'));
                rows.sort(function (a, b) {
                    const aCell = a.children[currentCol];
                    const bCell = b.children[currentCol];
                    if (!aCell || !bCell) return 0;
                    const aVal = (aCell.dataset.sort || aCell.textContent || '').trim().toLowerCase();
                    const bVal = (bCell.dataset.sort || bCell.textContent || '').trim().toLowerCase();
                    const result = aVal.localeCompare(bVal, 'fr');
                    return ascending ? result : -result;
                });
                rows.forEach(function (row) { tbody.appendChild(row); });
            }

            Livewire.hook('morph.updated', ({ el }) => {
                if (el.id === 'provTable' || el.closest('#provTable')) {
                    requestAnimationFrame(reApplySort);
                }
            });
        });
    </script>
</div>
