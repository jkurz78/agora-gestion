<x-app-layout>
    <div class="container-fluid py-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4>
                <i class="bi bi-robot me-2"></i> Validation OCR
            </h4>
            <a href="{{ route('questionnaires.campagnes.scans', $scan->campaign_id) }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Retour aux scans
            </a>
        </div>
        <livewire:questionnaire.assistant-saisie :scan="$scan" />
    </div>
</x-app-layout>
