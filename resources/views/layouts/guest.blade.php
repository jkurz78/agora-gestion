@php
    // $association injected by LayoutAssociationComposerProvider (Association::first() fallback — public route, no tenant boot)
    // TODO(S7): replace with CurrentAssociation::tryGet() once public routes resolve tenant from URL/subdomain.
    $nomAsso       = $association?->nom ?? 'Mon Association';
    $logoFullPath  = $association?->brandingLogoFullPath();
    $logoAsset     = ($logoFullPath && \Illuminate\Support\Facades\Storage::disk('local')->exists($logoFullPath))
        ? \App\Support\TenantAsset::url($logoFullPath)
        : asset('images/agora-gestion.svg');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? $nomAsso.' Gestion et comptabilité - Connexion' }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center mt-5">
            <div class="col-md-5">
                <div class="text-center mb-4">
                    <img src="{{ $logoAsset }}" alt="{{ $nomAsso }}" height="100" class="mb-3">
                    <h2 class="mb-0">{{ $nomAsso }}</h2>
                    <p class="text-muted mb-0">Gestion et comptabilité</p>
                </div>
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        {{ $slot }}
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="text-center mt-4 mb-3">
        <img src="{{ asset('images/agora-gestion.svg') }}" alt="AgoraGestion" height="120" class="opacity-75 d-block mx-auto">
        <small class="text-muted">{{ config('version.tag', '') }}</small>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
