<div class="position-relative"
     x-data="{
         highlighted: -1,
         dropTop: 0, dropLeft: 0, dropWidth: 0,
         updateDropPos() {
             let el = this.$refs.searchInput;
             if (!el) return;
             let r = el.getBoundingClientRect();
             this.dropTop  = r.bottom + window.scrollY;
             this.dropLeft = r.left   + window.scrollX;
             this.dropWidth = r.width;
         }
     }">
    @if($sousCategorieId)
        {{-- Selected state --}}
        <div class="d-flex align-items-center gap-2 px-3 py-2 border rounded" style="background:#f0e8f5;border-color:#c9a8d8!important">
            <span class="text-muted small">{{ $selectedCategorieLabel }}</span>
            <span class="text-muted">/</span>
            <span class="fw-medium">{{ $selectedLabel }}</span>
            <button type="button" class="btn-close btn-close-sm ms-auto" wire:click="clearSousCategorie" aria-label="Effacer"></button>
        </div>
    @else
        {{-- Search input --}}
        <input
            x-ref="searchInput"
            type="text"
            class="form-control"
            placeholder="Tapez pour rechercher une catégorie…"
            wire:model.live.debounce.300ms="search"
            autocomplete="off"
            x-on:input="highlighted = -1; updateDropPos()"
            x-on:focus="updateDropPos()"
            x-on:keydown.down.prevent="
                let items = document.querySelectorAll('[data-sc-nav-{{ $this->getId() }}]');
                if (items.length > 0) {
                    highlighted = Math.min(highlighted + 1, items.length - 1);
                    items[highlighted]?.scrollIntoView({ block: 'nearest' });
                }
            "
            x-on:keydown.up.prevent="
                let items = document.querySelectorAll('[data-sc-nav-{{ $this->getId() }}]');
                highlighted = Math.max(highlighted - 1, -1);
                if (highlighted >= 0) items[highlighted]?.scrollIntoView({ block: 'nearest' });
            "
            x-on:keydown.enter.prevent="
                let items = document.querySelectorAll('[data-sc-nav-{{ $this->getId() }}]');
                if (highlighted >= 0 && items[highlighted]) {
                    items[highlighted].click();
                    highlighted = -1;
                }
            "
            x-on:keydown.escape="$wire.set('open', false); highlighted = -1"
        >

        @if($open && (count($results) > 0 || strlen($search) > 0))
            <div
                x-init="updateDropPos()"
                style="max-height:280px;overflow-y:auto;background:white;border:1px solid #dee2e6;border-top:none;border-radius:0 0 .375rem .375rem;box-shadow:0 .25rem .5rem rgba(0,0,0,.1)"
                :style="{ position: 'fixed', top: dropTop+'px', left: dropLeft+'px', width: dropWidth+'px', zIndex: 9999 }"
            >
                @php $navIndex = 0; @endphp

                @foreach($results as $group)
                    <div class="px-3 py-1 fw-semibold" style="background:#f3f0f7;font-size:.75rem;color:#722281;letter-spacing:.03em;text-transform:uppercase">
                        {{ $group['categorie_nom'] }}
                    </div>

                    @foreach($group['items'] as $item)
                        <div
                            class="px-4 py-2 d-flex align-items-center gap-2"
                            data-sc-nav-{{ $this->getId() }}
                            style="cursor:pointer"
                            wire:click="selectSousCategorie({{ $item['id'] }})"
                            x-on:mouseover="highlighted = {{ $navIndex }}"
                            x-on:mouseout="highlighted = -1"
                            :style="highlighted === {{ $navIndex }} ? 'background:#f0e8f5' : ''"
                        >
                            <span>{{ $item['nom'] }}</span>
                            @if($item['code_cerfa'])
                                <span class="ms-auto text-muted small">{{ $item['code_cerfa'] }}</span>
                            @endif
                        </div>
                        @php $navIndex++; @endphp
                    @endforeach
                @endforeach

                @if(strlen($search) > 0)
                    <div
                        class="px-3 py-2 border-top fw-medium"
                        data-sc-nav-{{ $this->getId() }}
                        style="cursor:pointer;color:#722281"
                        wire:click="openCreateModal"
                        x-on:mouseover="highlighted = {{ $navIndex }}"
                        x-on:mouseout="highlighted = -1"
                        :style="highlighted === {{ $navIndex }} ? 'background:#f9f0fc' : ''"
                    >
                        + Créer "{{ $search }}"
                    </div>
                @endif
            </div>
        @endif
    @endif

    {{-- Create modal --}}
    @if($showCreateModal)
        <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center" style="background:rgba(0,0,0,.4);z-index:10000" wire:click.self="$set('showCreateModal', false)">
            <div class="bg-white rounded p-4" style="width:420px;max-width:95vw">
                <h6 class="fw-bold mb-3" style="color:#722281">Créer une nouvelle sous-catégorie</h6>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Nom <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" wire:model="newNom">
                    @error('newNom') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Catégorie <span class="text-danger">*</span></label>
                    <select class="form-select" wire:model="newCategorieId">
                        <option value="">— Choisir une catégorie —</option>
                        @foreach($categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->nom }}</option>
                        @endforeach
                    </select>
                    @error('newCategorieId') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold">Code CERFA <span class="text-muted fw-normal">(optionnel)</span></label>
                    <input type="text" class="form-control" wire:model="newCodeCerfa" placeholder="ex : 641">
                    @error('newCodeCerfa') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="d-flex gap-2 justify-content-end">
                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="$set('showCreateModal', false)">Annuler</button>
                    <button type="button" class="btn btn-sm text-white" style="background:#722281" wire:click="confirmCreate">Créer et sélectionner</button>
                </div>
            </div>
        </div>
    @endif
</div>
