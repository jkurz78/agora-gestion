<div>
    {{-- Flash message --}}
    @if($flashMessage)
        <div class="alert alert-{{ $flashType }} alert-dismissible fade show">
            {{ $flashMessage }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" wire:click="$set('flashMessage', '')"></button>
        </div>
    @endif

    {{-- Toolbar --}}
    <div class="mb-3 d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <div class="btn-group btn-group-sm" role="group" aria-label="Filtre type">
                <input type="radio" class="btn-check" name="scTypeFilter" id="scAll" value="all" checked autocomplete="off">
                <label class="btn btn-outline-secondary" for="scAll">Tout</label>
                <input type="radio" class="btn-check" name="scTypeFilter" id="scRecette" value="recette" autocomplete="off">
                <label class="btn btn-outline-secondary" for="scRecette">Recettes</label>
                <input type="radio" class="btn-check" name="scTypeFilter" id="scDepense" value="depense" autocomplete="off">
                <label class="btn btn-outline-secondary" for="scDepense">Dépenses</label>
            </div>
            <select id="scCatFilter" class="form-select form-select-sm" style="width:auto;">
                <option value="">— Toutes les catégories —</option>
                @foreach ($categories as $cat)
                    <option value="{{ $cat->id }}">{{ $cat->nom }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn btn-primary btn-sm" wire:click="openCreate">
            <i class="bi bi-plus-lg"></i> Ajouter une sous-catégorie
        </button>
    </div>

    {{-- Table --}}
    <div class="table-responsive">
        <table class="table table-sm table-striped table-hover" id="scTable" wire:ignore.self>
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    <th class="sortable" data-col="0" style="cursor:pointer;user-select:none">Catégorie <i class="bi bi-arrow-down-up" style="font-size:.7rem"></i></th>
                    <th class="sortable" data-col="1" style="cursor:pointer;user-select:none">Nom <i class="bi bi-arrow-down-up" style="font-size:.7rem"></i></th>
                    <th class="sortable" data-col="2" style="cursor:pointer;user-select:none">Code CERFA <i class="bi bi-arrow-down-up" style="font-size:.7rem"></i></th>
                    <th style="width:100px" class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($sousCategories as $sc)
                    <tr wire:key="sc-{{ $sc->id }}"
                        data-type="{{ $sc->categorie->type->value }}"
                        data-categorie="{{ $sc->categorie_id }}">
                        {{-- Catégorie (non éditable inline) --}}
                        <td>{{ $sc->categorie->nom }}</td>

                        {{-- Nom (éditable inline) --}}
                        <td wire:ignore.self
                            x-data="{ editing: false, value: @js($sc->nom), original: @js($sc->nom) }"
                            @click="if (!editing) { editing = true; $nextTick(() => $refs.input.focus()) }"
                            style="cursor:pointer">
                            <template x-if="!editing">
                                <span x-text="value"></span>
                            </template>
                            <template x-if="editing">
                                <input x-ref="input" type="text" x-model="value"
                                       class="form-control form-control-sm"
                                       maxlength="100"
                                       @keydown.enter="if (value.trim()) { $wire.updateField({{ $sc->id }}, 'nom', value); editing = false; original = value } else { value = original; editing = false }"
                                       @keydown.escape="value = original; editing = false"
                                       @blur="if (value.trim() && value !== original) { $wire.updateField({{ $sc->id }}, 'nom', value); original = value }; editing = false"
                                       @click.stop>
                            </template>
                        </td>

                        {{-- Code CERFA (éditable inline) --}}
                        <td wire:ignore.self
                            x-data="{ editing: false, value: @js($sc->code_cerfa ?? ''), original: @js($sc->code_cerfa ?? '') }"
                            @click="if (!editing) { editing = true; $nextTick(() => $refs.input.focus()) }"
                            style="cursor:pointer">
                            <template x-if="!editing">
                                <span x-text="value || '—'" :class="{ 'text-muted': !value }"></span>
                            </template>
                            <template x-if="editing">
                                <input x-ref="input" type="text" x-model="value"
                                       class="form-control form-control-sm"
                                       maxlength="10"
                                       @keydown.enter="$wire.updateField({{ $sc->id }}, 'code_cerfa', value); editing = false; original = value"
                                       @keydown.escape="value = original; editing = false"
                                       @blur="if (value !== original) { $wire.updateField({{ $sc->id }}, 'code_cerfa', value); original = value }; editing = false"
                                       @click.stop>
                            </template>
                        </td>

                        {{-- Actions --}}
                        <td class="text-center">
                            <div class="d-flex gap-1 justify-content-center">
                                <button class="btn btn-sm btn-outline-primary"
                                        wire:click="openEdit({{ $sc->id }})"
                                        style="padding:.15rem .35rem;font-size:.75rem"
                                        title="Modifier">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger"
                                        wire:click="delete({{ $sc->id }})"
                                        wire:confirm="Supprimer cette sous-catégorie ?"
                                        style="padding:.15rem .35rem;font-size:.75rem"
                                        title="Supprimer">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-muted">Aucune sous-catégorie enregistrée.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ═══════════════════════════════════════════════════════════
         MODAL CREATE/EDIT
         ═══════════════════════════════════════════════════════════ --}}
    @if($showModal)
        <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center"
             style="background:rgba(0,0,0,.4);z-index:2000"
             wire:click.self="$set('showModal', false)">
            <div class="bg-white rounded p-4 shadow" style="width:500px;max-width:95vw;max-height:90vh;overflow-y:auto">
                <h5 class="fw-bold mb-3">
                    {{ $editingId ? 'Modifier la sous-catégorie' : 'Ajouter une sous-catégorie' }}
                </h5>

                {{-- Catégorie --}}
                <div class="mb-3">
                    <label class="form-label small">Catégorie <span class="text-danger">*</span></label>
                    <select wire:model="categorie_id" class="form-select form-select-sm @error('categorie_id') is-invalid @enderror">
                        <option value="">— Choisir —</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->nom }} ({{ $cat->type->label() }})</option>
                        @endforeach
                    </select>
                    @error('categorie_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                {{-- Nom + Code CERFA --}}
                <div class="row g-2 mb-3">
                    <div class="col-md-8">
                        <label class="form-label small">Nom <span class="text-danger">*</span></label>
                        <input type="text" wire:model="nom" class="form-control form-control-sm @error('nom') is-invalid @enderror" maxlength="100">
                        @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Code CERFA</label>
                        <input type="text" wire:model="code_cerfa" class="form-control form-control-sm @error('code_cerfa') is-invalid @enderror" maxlength="10">
                        @error('code_cerfa') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>

                {{-- Actions --}}
                <div class="d-flex gap-2 justify-content-end mt-4">
                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="$set('showModal', false)">Annuler</button>
                    <button type="button" class="btn btn-sm btn-primary" wire:click="save">
                        <i class="bi bi-check-lg"></i> Enregistrer
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ═══════════════════════════════════════════════════════════
         JS: SORTING + FILTERING (côté client)
         ═══════════════════════════════════════════════════════════ --}}
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const table = document.getElementById('scTable');
            if (!table) return;

            // ── Sorting ──────────────────────────────────────────
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
                    const rows = Array.from(tbody.querySelectorAll('tr[data-type]'));

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

                    // Update sort indicators
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

            // ── Filtering ────────────────────────────────────────
            function filterSousCategories() {
                var typeVal = document.querySelector('input[name="scTypeFilter"]:checked')?.value || 'all';
                var catVal = document.getElementById('scCatFilter')?.value || '';

                document.querySelectorAll('#scTable tr[data-type]').forEach(function (row) {
                    var typeOk = typeVal === 'all' || row.dataset.type === typeVal;
                    var catOk = catVal === '' || row.dataset.categorie === catVal;

                    row.style.display = (typeOk && catOk) ? '' : 'none';
                });
            }

            // Type filter
            document.querySelectorAll('input[name="scTypeFilter"]').forEach(function (r) {
                r.addEventListener('change', filterSousCategories);
            });

            // Category filter
            var catFilter = document.getElementById('scCatFilter');
            if (catFilter) catFilter.addEventListener('change', filterSousCategories);

            // ── Re-apply sort after Livewire morph ───────────────
            function reApplySort() {
                if (currentCol === null) return;
                const tbody = table.querySelector('tbody');
                const rows = Array.from(tbody.querySelectorAll('tr[data-type]'));
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
                filterSousCategories();
            }

            Livewire.hook('morph.updated', ({ el }) => {
                if (el.id === 'scTable' || el.closest('#scTable')) {
                    requestAnimationFrame(reApplySort);
                }
            });
        });
    </script>
</div>
