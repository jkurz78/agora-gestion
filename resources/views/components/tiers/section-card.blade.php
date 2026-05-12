@props([
    'id',
    'titre',
    'compteur' => null,
    'headerExtra' => null,
])

<div class="card mb-3">
    <div class="card-header py-2 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2"
             role="button"
             data-bs-toggle="collapse"
             data-bs-target="#section-{{ $id }}-body"
             aria-expanded="true"
             aria-controls="section-{{ $id }}-body"
             style="cursor: pointer;">
            <i class="bi bi-chevron-down section-chevron"></i>
            <span class="fw-semibold">{{ $titre }}</span>
            @if ($compteur !== null)
                <span class="badge text-bg-secondary">{{ $compteur }}</span>
            @endif
        </div>
        @if ($headerExtra)
            <div class="ms-auto">
                {{ $headerExtra }}
            </div>
        @endif
    </div>
    <div id="section-{{ $id }}-body" class="collapse show">
        <div class="card-body p-0">
            {{ $slot }}
        </div>
    </div>
</div>
