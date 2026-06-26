<x-app-layout>
    <div class="container-fluid py-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4>
                <i class="bi bi-qr-code-scan me-2"></i> Scans — {{ $campagne->titre_affiche ?: $campagne->titre }}
            </h4>
            <a href="{{ route('operations.show', $campagne->operation_id) }}#questionnaires" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Retour
            </a>
        </div>
        <livewire:questionnaire.scan-upload :campagne="$campagne" />
    </div>
</x-app-layout>
