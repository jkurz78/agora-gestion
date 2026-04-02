{{-- resources/views/components/operation-breadcrumb.blade.php --}}
@props([
    'operation' => null,
    'participant' => null,
    'operationMeta' => null,
    'participantMeta' => null,
])

@php
    // Determine breadcrumb level
    $level = 1;
    if ($operation && $participant) {
        $level = 3;
    } elseif ($operation) {
        $level = 2;
    }

    // Badge colors by sous-catégorie name
    $badgeBg = '#f0f0f0';
    $badgeText = '#555';
    if ($operation?->typeOperation?->sousCategorie) {
        $sousCategorieName = $operation->typeOperation->sousCategorie->nom;
        if (str_contains($sousCategorieName, 'thérapeutique')) {
            $badgeBg = '#e8f0fe';
            $badgeText = '#1a56db';
        } elseif (str_contains($sousCategorieName, 'Formation')) {
            $badgeBg = '#fce8f0';
            $badgeText = '#A9014F';
        }
    }

    $linkColor = '#A9014F';
@endphp

<div class="d-flex justify-content-between align-items-center mb-3" style="font-size: 13px;">
    <div class="d-flex align-items-center gap-2 flex-wrap">
        {{-- "← Retour" button on levels 2 and 3 --}}
        @if ($level === 2)
            <a href="{{ route('gestion.operations') }}" class="text-decoration-none me-2" style="color: {{ $linkColor }};">
                <i class="bi bi-arrow-left me-1"></i>Retour
            </a>
        @elseif ($level === 3)
            <a href="{{ route('gestion.operations.show', $operation) }}" class="text-decoration-none me-2" style="color: {{ $linkColor }};">
                <i class="bi bi-arrow-left me-1"></i>Retour
            </a>
        @endif

        {{-- Breadcrumb segments --}}
        @if ($level === 1)
            <span class="fw-bold text-dark">Gestion des opérations</span>
        @elseif ($level === 2)
            <a href="{{ route('gestion.operations') }}" class="text-decoration-none" style="color: {{ $linkColor }};">Gestion des opérations</a>
            <span class="text-muted">/</span>
            <span class="fw-bold text-dark">{{ $operation->nom }}</span>

            @if ($operation->typeOperation?->sousCategorie)
                <span class="badge rounded-pill" style="background-color: {{ $badgeBg }}; color: {{ $badgeText }}; font-size: 11px;">
                    {{ $operation->typeOperation->sousCategorie->nom }}
                </span>
            @endif

            @if ($operationMeta)
                <span class="text-muted">{{ $operationMeta }}</span>
            @endif
        @elseif ($level === 3)
            <a href="{{ route('gestion.operations') }}" class="text-decoration-none" style="color: {{ $linkColor }};">Gestion des opérations</a>
            <span class="text-muted">/</span>
            <a href="{{ route('gestion.operations.show', $operation) }}" class="text-decoration-none" style="color: {{ $linkColor }};">{{ $operation->nom }}</a>
            <span class="text-muted">/</span>
            <span class="fw-bold text-dark">{{ $participant->tiers?->displayName() ?? 'Participant' }}</span>

            @if ($participantMeta)
                <span class="text-muted">{{ $participantMeta }}</span>
            @endif
        @endif
    </div>

    {{-- Right-side slot for buttons --}}
    @if ($slot->isNotEmpty())
        <div class="d-flex align-items-center gap-2 ms-3">
            {{ $slot }}
        </div>
    @endif
</div>
