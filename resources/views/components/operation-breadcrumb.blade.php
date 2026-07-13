{{-- resources/views/components/operation-breadcrumb.blade.php --}}
{{-- Navigation breadcrumb is now in the top bar (layout slots).
     This component only renders contextual info: badge, meta, and action buttons. --}}
@props([
    'operation' => null,
    'participant' => null,
    'operationMeta' => null,
    'participantMeta' => null,
])

@php
    $badgeBg = '#f0f0f0';
    $badgeText = '#555';
    if ($operation?->typeOperation?->compte) {
        $compteName = $operation->typeOperation->compte->intitule;
        if (str_contains($compteName, 'thérapeutique')) {
            $badgeBg = '#e8f0fe';
            $badgeText = '#1a56db';
        } elseif (str_contains($compteName, 'Formation')) {
            $badgeBg = '#fce8f0';
            $badgeText = '#A9014F';
        }
    }
    $meta = $participant ? ($participantMeta ?? null) : ($operationMeta ?? null);
@endphp

<div class="d-flex justify-content-between align-items-center mb-2" style="font-size: 13px; min-height: 31px;">
    <div class="d-flex align-items-center gap-2 flex-wrap">
        @if ($operation && !$participant && $operation->typeOperation?->compte)
            <span class="badge rounded-pill" style="background-color: {{ $badgeBg }}; color: {{ $badgeText }}; font-size: 11px;">
                {{ $operation->typeOperation->compte->intitule }}
            </span>
        @endif
        @if ($meta)
            <span class="text-muted">{{ $meta }}</span>
        @endif
    </div>

    {{-- Right-side slot for buttons --}}
    @if ($slot->isNotEmpty())
        <div class="d-flex align-items-center gap-2 ms-3">
            {{ $slot }}
        </div>
    @endif
</div>
