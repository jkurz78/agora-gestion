<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;font-size:14px;line-height:1.6;color:#333;max-width:600px;margin:0 auto;padding:20px">
    <h2 style="color:#3d5473">Code de vérification</h2>

    <p>Votre code de connexion est :</p>

    <div style="background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:20px;text-align:center;margin:20px 0">
        <strong style="font-size:28px;letter-spacing:6px;color:#3d5473">{{ $code }}</strong>
    </div>

    <p>Ce code expire dans <strong>10 minutes</strong>.</p>

    <p style="color:#888;font-size:12px">Si vous n'avez pas demandé ce code, ignorez cet email.</p>

    <p>Cordialement,<br>{{ config('app.name') }}</p>
</body>
</html>
