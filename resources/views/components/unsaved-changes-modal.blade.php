{{-- Modale de confirmation modifications non sauvegardées --}}
<template x-if="showUnsavedModal">
    <div class="modal-backdrop fade show" style="z-index: 1050;"></div>
</template>
<template x-if="showUnsavedModal">
    <div class="modal fade show" tabindex="-1" style="display: block; z-index: 1055;">
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
                    <button class="btn btn-sm btn-primary" @click="$wire.save().then(() => { isDirty = false; showUnsavedModal = false; window.location = pendingUrl; });">
                        Enregistrer et quitter
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
