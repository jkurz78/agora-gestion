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
               href="{{ route('compta.dashboard') }}">
                <span class="d-inline-block rounded-circle me-2" style="width:10px;height:10px;background-color:{{ Espace::Compta->color() }}"></span>
                {{ Espace::Compta->label() }}
            </a>
        </li>
        <li>
            <a class="dropdown-item {{ $currentEspace === Espace::Gestion ? 'active' : '' }}"
               href="{{ route('gestion.dashboard') }}">
                <span class="d-inline-block rounded-circle me-2" style="width:10px;height:10px;background-color:{{ Espace::Gestion->color() }}"></span>
                {{ Espace::Gestion->label() }}
            </a>
        </li>
    </ul>
</div>
