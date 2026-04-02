{{-- Modale de confirmation modifications non enregistrées --}}
<div x-show="showUnsavedModal" x-cloak class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5);">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">Modifications non enregistrées</h6>
            </div>
            <div class="modal-body">
                <p class="mb-0">Vous avez des modifications non enregistrées. Que souhaitez-vous faire ?</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-sm btn-outline-secondary" @click="showUnsavedModal = false; window.location = pendingUrl;">
                    Abandonner
                </button>
                <button class="btn btn-sm btn-primary" @click="showUnsavedModal = false; $wire.save().then(() => { isDirty = false; window.location = pendingUrl; });">
                    Enregistrer et quitter
                </button>
            </div>
        </div>
    </div>
</div>
