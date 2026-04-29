<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bienvenue sur AgoraGestion</title>
    <link rel="icon" href="{{ asset('images/favicon.svg') }}">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    @livewireStyles
</head>
<body class="bg-light">
    <div class="container py-5" style="max-width: 540px;">
        <div class="text-center mb-4">
            <img src="{{ asset('images/agora-gestion.svg') }}" alt="AgoraGestion" style="max-height: 64px;">
        </div>

        <livewire:setup.setup-form />

        <p class="text-center text-muted small mt-4 mb-0">
            <i class="bi bi-shield-check me-1"></i>
            Cette page est uniquement visible tant qu'aucun compte super-administrateur n'existe.
        </p>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    @livewireScripts
</body>
</html>
