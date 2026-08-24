@php
    use App\Support\Parametres\ParametresNavigation;
    $role = auth()->user()->currentRoleEnum();
@endphp

<x-app-layout>
    <x-slot:title>Paramètres</x-slot:title>

    <div class="container-fluid py-3">
        <h1 class="h4 mb-3" style="color:#1e3a5f;">Paramètres</h1>

        @foreach (ParametresNavigation::sections() as $section)
            @php $ecrans = $section->ecransVisibles($role); @endphp
            @continue(count($ecrans) === 0)

            <div class="card mb-3" id="{{ $section->cle }}">
                <div class="card-body">
                    <h2 class="h6 mb-1" style="color:#1e3a5f;">
                        <i class="bi {{ $section->icone }} me-1"></i> {{ $section->libelle }}
                    </h2>
                    <p class="text-muted small mb-3">{{ $section->description }}</p>

                    <div class="row g-2">
                        @foreach ($ecrans as $ecran)
                            <div class="col-md-6 col-lg-4">
                                <a href="{{ route($ecran->route) }}"
                                   class="d-block border rounded p-2 text-decoration-none h-100">
                                    <i class="bi {{ $ecran->icone }} me-1 text-muted"></i>
                                    <span class="fw-medium">{{ $ecran->libelle }}</span>
                                </a>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</x-app-layout>
