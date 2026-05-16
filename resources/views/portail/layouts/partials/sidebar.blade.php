@php
    /** @var \App\Models\Tiers|null $tiers */
    /** @var \App\Models\Association $portailAssociation */
    $sections = app(\App\Services\Portail\PortailSectionsResolver::class)->resolve($tiers ?? null);
    $groupes = $sections->groupBy(fn (\App\Support\Portail\PortailSectionDTO $s): string => $s->groupe ?? '');
@endphp

<nav class="sidebar-nav d-flex flex-column h-100">
    <div class="flex-grow-1">
        @foreach ($groupes as $groupe => $items)
            @if ($groupe !== '')
                <div class="nav-section-label">{{ $groupe }}</div>
            @endif

            @foreach ($items as $section)
                @php
                    $routeShortName = preg_replace('/^portail\./', '', $section->routeName);
                    $extraParams = $section->routeParams ?? [];
                    $url = \Illuminate\Support\Facades\Route::has($section->routeName)
                        ? \App\Support\PortailRoute::to($routeShortName, $portailAssociation, $extraParams)
                        : '#';
                    $isActive = request()->routeIs($section->routeName)
                        && collect($extraParams)->every(
                            fn ($v, $k) => (string) request()->route($k) === (string) $v
                        );
                @endphp
                <a href="{{ $url }}"
                   class="nav-link{{ $isActive ? ' active' : '' }}"
                   @if ($isActive) aria-current="page" @endif>
                    <i class="bi {{ $section->icon }}"></i>
                    {{ $section->label }}
                    @if ($section->badge !== null)
                        <span class="badge bg-primary rounded-pill ms-auto">{{ $section->badge }}</span>
                    @endif
                </a>
            @endforeach
        @endforeach
    </div>

    <div>
        <hr>
        <form method="POST" action="{{ \App\Support\PortailRoute::to('logout', $portailAssociation) }}">
            @csrf
            <button type="submit" class="nav-link w-100 text-start border-0 bg-transparent text-danger">
                <i class="bi bi-box-arrow-right"></i>
                Se déconnecter
            </button>
        </form>
    </div>
</nav>
