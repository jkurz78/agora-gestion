@php
    $association = \App\Models\Association::find(1);
    $nomAsso     = $association?->nom ?? 'Soigner Vivre Sourire';
    $logoAsset   = ($association?->logo_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($association->logo_path))
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($association->logo_path)
        : asset('images/logo.png');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Formulaire participant') &mdash; {{ $nomAsso }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    @include('partials.colors')
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center mt-5">
            <div class="col-md-10 col-lg-8">
                <div class="text-center mb-4">
                    <img src="{{ $logoAsset }}" alt="{{ $nomAsso }}" height="80" class="mb-2">
                    <h5 class="text-muted mb-0">{{ $nomAsso }}</h5>
                </div>

                @yield('content')

                <p class="text-center text-muted small mt-4 mb-5">
                    {{ $nomAsso }} &mdash; Formulaire
                </p>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js"></script>
    @yield('scripts')
</body>
</html>
