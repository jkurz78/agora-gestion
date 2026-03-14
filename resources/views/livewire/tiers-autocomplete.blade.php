<div class="position-relative">
    @if($tiersId)
        {{-- Selected state --}}
        <div class="d-flex align-items-center gap-2 px-3 py-2 border rounded" style="background:#f0e8f5;border-color:#c9a8d8!important">
            <span class="text-muted">{{ $selectedType === 'entreprise' ? '🏢' : '👤' }}</span>
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
        >

        @if($open && (count($results) > 0 || strlen($search) > 0))
            <div class="position-absolute w-100 bg-white border border-top-0 rounded-bottom shadow-sm" style="z-index:1000;max-height:240px;overflow-y:auto">
                @foreach($results as $item)
                    <div
                        class="px-3 py-2 d-flex align-items-center gap-2"
                        style="cursor:pointer"
                        wire:click="selectTiers({{ $item['id'] }})"
                        onmouseover="this.style.background='#f0e8f5'"
                        onmouseout="this.style.background=''"
                    >
                        <span>{{ ($item['type'] ?? '') === 'entreprise' ? '🏢' : '👤' }}</span>
                        <span>{{ $item['label'] }}</span>
                    </div>
                @endforeach

                @if(strlen($search) > 0)
                    <div
                        class="px-3 py-2 border-top fw-medium"
                        style="cursor:pointer;color:#722281"
                        wire:click="openCreateModal"
                        onmouseover="this.style.background='#f9f0fc'"
                        onmouseout="this.style.background=''"
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
                    <span>{{ $existingTiers['type'] === 'entreprise' ? '🏢' : '👤' }}</span>
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

    {{-- Create modal --}}
    @if($showCreateModal)
        <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center" style="background:rgba(0,0,0,.4);z-index:2000" wire:click.self="$set('showCreateModal', false)">
            <div class="bg-white rounded p-4" style="width:400px;max-width:95vw">
                <h6 class="fw-bold mb-3" style="color:#722281">Créer un nouveau tiers</h6>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Nom <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" wire:model="newNom">
                    @error('newNom') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Type</label>
                    <div class="d-flex gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" wire:model="newType" value="entreprise" id="tc_entreprise">
                            <label class="form-check-label" for="tc_entreprise">Entreprise</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" wire:model="newType" value="particulier" id="tc_particulier">
                            <label class="form-check-label" for="tc_particulier">Particulier</label>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Utilisable pour</label>
                    <div class="d-flex gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" wire:model="newPourDepenses" id="tc_depenses">
                            <label class="form-check-label" for="tc_depenses">Dépenses</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" wire:model="newPourRecettes" id="tc_recettes">
                            <label class="form-check-label" for="tc_recettes">Recettes</label>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2 justify-content-end">
                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="$set('showCreateModal', false)">Annuler</button>
                    <button type="button" class="btn btn-sm text-white" style="background:#722281" wire:click="confirmCreate">Créer et sélectionner</button>
                </div>
            </div>
        </div>
    @endif
</div>
