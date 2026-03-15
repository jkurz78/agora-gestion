@php
    $association   = \App\Models\Association::find(1);
    $nomAsso       = $association?->nom ?? 'Soigner•Vivre•Sourire';
    $logoAsset     = ($association?->logo_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($association->logo_path))
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($association->logo_path)
        : asset('images/logo.png');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? $nomAsso.' Comptabilité - Connexion' }}</title>
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
                    <p class="text-muted mb-0">Comptabilité</p>
                </div>
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        {{ $slot }}
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
