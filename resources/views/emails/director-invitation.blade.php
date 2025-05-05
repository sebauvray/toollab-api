<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invitation Directeur d'École</title>
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
            background-color: #343C6A;
            color: #ffffff;
            padding: 20px;
            text-align: center;
        }
        .content {
            padding: 20px;
            background-color: #ffffff;
        }
        .button {
            display: inline-block;
            padding: 12px 24px;
            background-color: #343C6A;
            color: #ffffff;
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
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Toollab</h1>
    </div>
    <div class="content">
        <h2>Invitation en tant que directeur d'école</h2>
        <p>Bonjour {{ $notifiable->first_name }} {{ $notifiable->last_name }},</p>
        <p>Vous avez été invité(e) en tant que directeur/directrice de l'école <strong>{{ $schoolName }}</strong>.</p>
        <p>Pour accéder à votre compte, veuillez définir votre mot de passe en cliquant sur le bouton ci-dessous.</p>
        <p><a href="{{ $actionUrl }}" class="button">Définir mon mot de passe</a></p>
        <p>Ce lien d'invitation expirera dans 7 jours.</p>
        <p>Cordialement,<br>L'équipe Toollab</p>
    </div>
    <div class="footer">
        <p>Si vous rencontrez des problèmes en cliquant sur le bouton "Définir mon mot de passe", copiez et collez l'URL ci-dessous dans votre navigateur web: {{ $actionUrl }}</p>
    </div>
</div>
</body>
</html>
