@php
    $logoFullPath = $portailAssociation?->brandingLogoFullPath();
    $logoAsset    = ($logoFullPath && \Illuminate\Support\Facades\Storage::disk('local')->exists($logoFullPath))
        ? \App\Support\TenantAsset::url($logoFullPath)
        : asset('images/agora-gestion.svg');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $portailAssociation->nom }} — Portail</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    @livewireStyles
</head>
<body class="bg-light">
    <nav class="navbar navbar-light bg-white border-bottom shadow-sm">
        <div class="container d-flex align-items-center">
            <img src="{{ $logoAsset }}" alt="{{ $portailAssociation->nom }}" height="40" class="me-3">
            <span class="navbar-brand mb-0 h5">{{ $portailAssociation->nom }}</span>
        </div>
    </nav>
    <main class="container py-5">
        {{ $slot }}
    </main>
    @livewireScripts
</body>
</html>
