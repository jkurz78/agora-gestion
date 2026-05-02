<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'AgoraGestion')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f9fafb; }
        .public-card { max-width: 560px; margin: 80px auto; background: #ffffff; border-radius: 12px; padding: 40px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .public-card h1 { font-size: 22px; color: #111827; margin-bottom: 16px; }
        .public-card p { color: #374151; line-height: 1.6; }
        .public-card .footer { margin-top: 24px; padding-top: 16px; border-top: 1px solid #e5e7eb; font-size: 13px; color: #6b7280; }
    </style>
</head>
<body>
<main>
    <div class="public-card">
        @yield('content')
    </div>
</main>
</body>
</html>
