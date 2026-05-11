@props([
    'id',
    'titre',
    'compteur' => null,
])

<div class="card mb-3">
    <div class="card-header py-2 d-flex justify-content-between align-items-center"
         role="button"
         data-bs-toggle="collapse"
         data-bs-target="#section-{{ $id }}-body"
         aria-expanded="true"
         aria-controls="section-{{ $id }}-body">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-chevron-down section-chevron"></i>
            <span class="fw-semibold">{{ $titre }}</span>
            @if ($compteur !== null)
                <span class="badge text-bg-secondary">{{ $compteur }}</span>
            @endif
        </div>
    </div>
    <div id="section-{{ $id }}-body" class="collapse show">
        <div class="card-body p-0">
            {{ $slot }}
        </div>
    </div>
</div>
