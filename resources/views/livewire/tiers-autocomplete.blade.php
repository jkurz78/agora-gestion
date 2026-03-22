<div class="position-relative" x-data="{ highlighted: -1 }">
    @if($tiersId)
        {{-- Selected state --}}
        <div class="d-flex align-items-center gap-2 px-3 py-2 border rounded" style="background:#f0e8f5;border-color:#c9a8d8!important">
            <span class="text-muted" style="font-size:.75rem">{{ $selectedType === 'entreprise' ? '🏢' : '👤' }}</span>
            <span class="fw-medium">{{ $selectedLabel }}</span>
            <button type="button" class="btn-close btn-close-sm ms-auto" wire:click="clearTiers" aria-label="Effacer"></button>
        </div>
    @else
        {{-- Search input --}}
        <input
            type="text"
            class="form-control"
            placeholder="Tapez pour rechercher un tiers…"
            wire:model.live.debounce.300ms="search"
            autocomplete="off"
            x-on:input="highlighted = -1"
            x-on:keydown.down.prevent="
                let items = $root.querySelectorAll('[data-nav-item]');
                if (items.length > 0) {
                    highlighted = Math.min(highlighted + 1, items.length - 1);
                    items[highlighted]?.scrollIntoView({ block: 'nearest' });
                }
            "
            x-on:keydown.up.prevent="
                let items = $root.querySelectorAll('[data-nav-item]');
                highlighted = Math.max(highlighted - 1, -1);
                if (highlighted >= 0) items[highlighted]?.scrollIntoView({ block: 'nearest' });
            "
            x-on:keydown.enter.prevent="
                let items = $root.querySelectorAll('[data-nav-item]');
                if (highlighted >= 0 && items[highlighted]) {
                    items[highlighted].click();
                    highlighted = -1;
                }
            "
            x-on:keydown.escape="$wire.set('open', false); highlighted = -1"
            x-on:keydown.tab="
                let items = $root.querySelectorAll('[data-nav-item]');
                if (highlighted >= 0 && items[highlighted]) {
                    items[highlighted].click();
                    highlighted = -1;
                }
            "
        >

        @if($open && (count($results) > 0 || strlen($search) > 0))
            <div class="position-absolute w-100 bg-white border border-top-0 rounded-bottom shadow-sm" style="z-index:1000;max-height:240px;overflow-y:auto">
                @foreach($results as $item)
                    <div
                        class="px-3 py-2 d-flex align-items-center gap-2"
                        data-nav-item
                        style="cursor:pointer"
                        wire:click="selectTiers({{ $item['id'] }})"
                        x-on:mouseover="highlighted = {{ $loop->index }}"
                        x-on:mouseout="highlighted = -1"
                        :style="highlighted === {{ $loop->index }} ? 'background:#f0e8f5' : ''"
                    >
                        <span style="font-size:.75rem">{{ ($item['type'] ?? '') === 'entreprise' ? '🏢' : '👤' }}</span>
                        <span>{{ $item['label'] }}</span>
                    </div>
                @endforeach

                @if(strlen($search) > 0)
                    <div
                        class="px-3 py-2 border-top fw-medium"
                        data-nav-item
                        style="cursor:pointer;color:#722281"
                        wire:click="openCreateModal"
                        x-on:mouseover="highlighted = {{ count($results) }}"
                        x-on:mouseout="highlighted = -1"
                        :style="highlighted === {{ count($results) }} ? 'background:#f9f0fc' : ''"
                    >
                        + Créer "{{ $search }}"
                    </div>
                @endif
            </div>
        @endif
    @endif

    {{-- Activate existing tiers modal --}}
    @if($showActivateModal && $existingTiers)
        <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center" style="background:rgba(0,0,0,.4);z-index:2000" wire:click.self="$set('showActivateModal', false)">
            <div class="bg-white rounded p-4" style="width:420px;max-width:95vw">
                <h6 class="fw-bold mb-3" style="color:#722281">Tiers existant</h6>

                <p class="mb-3">
                    <span style="font-size:.75rem">{{ $existingTiers['type'] === 'entreprise' ? '🏢' : '👤' }}</span>
                    <strong>{{ $existingTiers['label'] }}</strong>
                    existe déjà mais n'est pas activé pour ce contexte.
                </p>

                <p class="text-muted small mb-4">Voulez-vous l'activer et le sélectionner ?</p>

                <div class="d-flex gap-2 justify-content-end">
                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="$set('showActivateModal', false)">Annuler</button>
                    <button type="button" class="btn btn-sm text-white" style="background:#722281" wire:click="activateTiers">Activer et sélectionner</button>
                </div>
            </div>
        </div>
    @endif

</div>
