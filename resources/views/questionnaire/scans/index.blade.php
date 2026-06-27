<x-app-layout>
    <div class="container-fluid py-3">
        <nav aria-label="breadcrumb" class="mb-2">
            <ol class="breadcrumb small mb-0">
                <li class="breadcrumb-item"><a href="{{ route('operations.index') }}">Opérations</a></li>
                <li class="breadcrumb-item"><a href="{{ route('operations.show', $campagne->operation_id) }}#questionnaires">{{ $campagne->operation->nom }}</a></li>
                <li class="breadcrumb-item active">Scans — {{ $campagne->titre_affiche ?: $campagne->titre }}</li>
            </ol>
        </nav>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4>
                <i class="bi bi-qr-code-scan me-2"></i> Scans — {{ $campagne->titre_affiche ?: $campagne->titre }}
            </h4>
        </div>
        <livewire:questionnaire.scan-upload :campagne="$campagne" />
    </div>
</x-app-layout>
