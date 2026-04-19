<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $portailAssociation->nom }} — Portail</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    @livewireStyles
</head>
<body>
    <nav class="navbar navbar-light bg-light border-bottom">
        <div class="container-fluid">
            <span class="navbar-brand mb-0 h1">{{ $portailAssociation->nom }}</span>
        </div>
    </nav>
    <main class="container py-4">
        {{ $slot }}
    </main>
    @livewireScripts
</body>
</html>
