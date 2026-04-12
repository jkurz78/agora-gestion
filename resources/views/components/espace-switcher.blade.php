{{-- resources/views/components/espace-switcher.blade.php --}}
@php
    use App\Enums\Espace;
    $currentEspace = $espace ?? Espace::Compta;
    $otherEspace = $currentEspace === Espace::Compta ? Espace::Gestion : Espace::Compta;
@endphp
<div class="dropdown d-block">
    <a class="text-decoration-none dropdown-toggle" href="#"
       role="button" data-bs-toggle="dropdown" aria-expanded="false"
       style="color: rgba(255,255,255,0.85); font-size: .85rem;">
        {{ $currentEspace->label() }}
    </a>
    <ul class="dropdown-menu" style="min-width: 180px;">
        <li>
            <a class="dropdown-item {{ $currentEspace === Espace::Compta ? 'active' : '' }}"
               href="{{ route('dashboard') }}">
                @if ($currentEspace === Espace::Compta)
                    <i class="bi bi-check-lg me-1"></i>
                @else
                    <i class="bi bi-arrow-right me-1 opacity-50"></i>
                @endif
                {{ Espace::Compta->label() }}
            </a>
        </li>
        <li>
            <a class="dropdown-item {{ $currentEspace === Espace::Gestion ? 'active' : '' }}"
               href="{{ route('dashboard') }}">
                @if ($currentEspace === Espace::Gestion)
                    <i class="bi bi-check-lg me-1"></i>
                @else
                    <i class="bi bi-arrow-right me-1 opacity-50"></i>
                @endif
                {{ Espace::Gestion->label() }}
            </a>
        </li>
    </ul>
</div>
