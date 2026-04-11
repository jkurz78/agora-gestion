{{-- Modal Bootstrap remplaçant window.confirm() pour wire:confirm --}}
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirmation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body" id="confirmModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-primary" id="confirmModalOk">Confirmer</button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var nativeConfirm = window.confirm;
    var confirmEl = null;

    // Capture phase : détecte le clic sur un élément wire:confirm AVANT Livewire
    document.addEventListener('click', function(e) {
        var el = e.target.closest('[wire\\:confirm]');
        if (el) {
            confirmEl = el;
            // Reset après le traitement synchrone de l'événement
            setTimeout(function() { confirmEl = null; }, 0);
        }
    }, true);

    // Remplace window.confirm — uniquement pour les clics wire:confirm
    var bootstrapConfirm = function(message) {
        if (!confirmEl) return nativeConfirm.call(window, message);

        var el = confirmEl;
        confirmEl = null;

        var modalEl = document.getElementById('confirmModal');
        var body = document.getElementById('confirmModalBody');
        var okBtn = document.getElementById('confirmModalOk');
        var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);

        body.innerText = message;
        okBtn.onclick = function() {
            bsModal.hide();
            // Re-clic avec confirm bypassé
            window.confirm = function() { return true; };
            el.click();
            window.confirm = bootstrapConfirm;
        };
        bsModal.show();

        return false;
    };

    window.confirm = bootstrapConfirm;
})();
</script>
