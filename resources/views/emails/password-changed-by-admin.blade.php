<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;font-size:14px;line-height:1.6;color:#333;max-width:600px;margin:0 auto;padding:20px">
    <h2 style="color:#3d5473">Mot de passe modifié</h2>

    <p>Bonjour {{ $user->nom }},</p>

    <p>Votre mot de passe sur <strong>{{ config('app.name') }}</strong> a été modifié par {{ $changedByName }}.</p>

    <p style="color:#c0392b"><strong>Si vous n'êtes pas à l'origine de cette demande, contactez immédiatement votre administrateur.</strong></p>

    <p>Cordialement,<br>{{ config('app.name') }}</p>
</body>
</html>
