<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Questionnaire de satisfaction' }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6fb; }
        .questionnaire-card { max-width: 640px; margin: 3rem auto; }
    </style>
</head>
<body>
    <div class="container questionnaire-card">
        @php $assoEntete = \App\Support\CurrentAssociation::tryGet(); @endphp
        @if ($assoEntete)
            <div class="text-center mb-4">
                @php $logoEntete = $assoEntete->brandingLogoDataUri(); @endphp
                @if ($logoEntete)
                    <img src="{{ $logoEntete }}" alt="{{ $assoEntete->nom }}" style="max-height:72px;width:auto" class="mb-2">
                @endif
                <h1 class="h5 mb-0">{{ $assoEntete->nom }}</h1>
            </div>
        @endif

        <div class="card shadow-sm">
            <div class="card-body p-4">
                @yield('content')
            </div>
        </div>
        <p class="text-center text-muted small mt-3">AgoraGestion — Vos réponses sont confidentielles.</p>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
