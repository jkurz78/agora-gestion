<div>
    <button type="button" class="btn btn-sm btn-outline-warning" wire:click="openPicker">
        <i class="bi bi-people me-1"></i>Fusionner ce tiers vers…
    </button>

    @if($showPicker)
    <div class="modal fade show d-block" tabindex="-1" style="background-color:rgba(0,0,0,.5)"
         wire:click.self="closePicker">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-people-fill me-2"></i>Fusionner « {{ $tiers->displayName() }} » vers…
                    </h5>
                    <button type="button" class="btn-close" wire:click="closePicker"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-2">
                        Choisissez le tiers <strong>survivant</strong>. Toutes les transactions, factures, devis et autres
                        références du tiers actuel y seront rattachées, puis le tiers actuel sera supprimé.
                    </p>
                    <livewire:tiers-autocomplete
                        :key="'fusion-target-'.$tiers->id"
                        filtre="tous"
                        context="fusion-target" />
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="closePicker">Annuler</button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- The full-merge modal is mounted globally on the page; this component
         only dispatches `open-tiers-merge` and listens for `tiers-merge-confirmed`. --}}
</div>
