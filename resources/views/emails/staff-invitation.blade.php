<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invitation à rejoindre l'équipe</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            padding: 40px 30px;
            text-align: center;
            background-color: #ffffff;
            border-bottom: 1px solid #e0e0e0;
        }
        .content {
            padding: 20px;
            background-color: #ffffff;
        }
        .button {
            display: inline-block;
            padding: 12px 24px;
            background-color: #343C6A;
            text-decoration: none;
            border-radius: 4px;
            margin: 20px 0;
        }
        .footer {
            text-align: center;
            padding: 20px;
            font-size: 12px;
            color: #666;
        }
        .logo {
            max-width: 276px;
            height: auto;
            display: block;
            margin: 0 auto;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <img src="{{ asset('images/logo-toollab.png') }}" alt="Toollab Logo" class="logo">
    </div>
    <div class="content">
        <h2>Invitation à rejoindre l'équipe</h2>
        <p>Bonjour {{ $notifiable->first_name }} {{ $notifiable->last_name }},</p>
        <p>Vous avez été invité(e) à rejoindre l'équipe de <strong>{{ $schoolName }}</strong> en tant que <strong>{{ $roleName }}</strong>.</p>
        <p>Pour accéder à votre compte, veuillez définir votre mot de passe en cliquant sur le bouton ci-dessous.</p>
        <p><a href="{{ $actionUrl }}" class="button"  style="color: white">Définir mon mot de passe</a></p>
        <p>Ce lien d'invitation expirera dans 7 jours.</p>
        <p>Cordialement,<br>L'équipe Toollab</p>
    </div>
    <div class="footer">
        <p>Si vous rencontrez des problèmes en cliquant sur le bouton "Définir mon mot de passe", copiez et collez l'URL ci-dessous dans votre navigateur web: {{ $actionUrl }}</p>
    </div>
</div>
</body>
</html>
