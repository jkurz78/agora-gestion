<x-app-layout>
    <div class="container-fluid py-3">
        @php $campagne = $scan->campaign; @endphp
        <nav aria-label="breadcrumb" class="mb-2">
            <ol class="breadcrumb small mb-0">
                <li class="breadcrumb-item"><a href="{{ route('operations.index') }}">Opérations</a></li>
                <li class="breadcrumb-item"><a href="{{ route('operations.show', $campagne->operation_id) }}#questionnaires">{{ $campagne->operation->nom }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('questionnaires.campagnes.scans', $campagne) }}">Scans</a></li>
                <li class="breadcrumb-item active">Validation OCR</li>
            </ol>
        </nav>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4>
                <i class="bi bi-robot me-2"></i> Validation OCR
            </h4>
        </div>
        <livewire:questionnaire.assistant-saisie :scan="$scan" />
    </div>
</x-app-layout>
