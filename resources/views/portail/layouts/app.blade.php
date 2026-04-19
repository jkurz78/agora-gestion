@php
    $nomAsso  = $portailAssociation->nom;
    $logoUrl  = route('portail.logo', ['association' => $portailAssociation->slug]);
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $nomAsso }} — Portail</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    @livewireStyles
</head>
<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center mt-5">
            <div class="col-md-5">
                <div class="text-center mb-4">
                    <img src="{{ $logoUrl }}" alt="{{ $nomAsso }}" height="100" class="mb-3">
                    <h2 class="mb-0">{{ $nomAsso }}</h2>
                    <p class="text-muted mb-0">Portail</p>
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
        <img src="{{ asset('images/agora-gestion.svg') }}" alt="AgoraGestion" height="80" class="opacity-75 d-block mx-auto">
        <small class="text-muted">{{ config('version.tag', '') }}</small>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    @livewireScripts
</body>
</html>
