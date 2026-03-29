<div x-show="step === 3" x-cloak data-step="3">
    <h5 class="mb-3"><i class="bi bi-file-earmark-arrow-up"></i> Documents</h5>

    @if($typeOperation->attestation_medicale_path)
        <div class="alert alert-info d-flex align-items-center mb-3">
            <i class="bi bi-download me-2"></i>
            <span>
                Téléchargez l'attestation médicale à faire remplir par votre médecin :
                <a href="{{ asset('storage/' . $typeOperation->attestation_medicale_path) }}" target="_blank" class="alert-link">
                    Télécharger le document <i class="bi bi-box-arrow-up-right"></i>
                </a>
            </span>
        </div>
    @endif

    <p class="text-muted mb-3">
        Vous pouvez joindre jusqu'à 3 documents (certificat médical, attestation, etc.).
        <br>Formats acceptés : PDF, JPG, PNG — 5 Mo maximum par fichier.
    </p>

    <div class="mb-3">
        <label class="form-label">Document 1</label>
        <input type="file" name="documents[]" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
    </div>
    <div class="mb-3">
        <label class="form-label">Document 2</label>
        <input type="file" name="documents[]" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
    </div>
    <div class="mb-3">
        <label class="form-label">Document 3</label>
        <input type="file" name="documents[]" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
    </div>
</div>
