<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Passation de direction</title>
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
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        @include('emails.partials.logo')

    </div>
    <div class="content">
        <h2>Invitation à reprendre la direction</h2>
        <p>Bonjour,</p>
        <p><strong>{{ $fromName }}</strong> vous propose de reprendre la direction de l'établissement <strong>{{ $schoolName }}</strong> sur Toollab.</p>
        <p>En acceptant, vous deviendrez directeur de l'établissement et disposerez de l'ensemble des droits de gestion. Si vous n'avez pas encore de compte Toollab, vous pourrez le créer au moment de l'acceptation.</p>
        <p><a href="{{ $actionUrl }}" class="button" style="color: white">Voir l'invitation</a></p>
        <p>Ce lien est valable jusqu'au <strong>{{ $expiresAt }}</strong>. Vous pourrez également refuser l'invitation depuis cette page.</p>
        <p>Cordialement,<br>L'équipe Toollab</p>
    </div>
    <div class="footer">
        <p>Si vous n'attendiez pas cette invitation, vous pouvez ignorer cet e-mail.</p>
        <p>Si vous rencontrez des problèmes en cliquant sur le bouton "Voir l'invitation", copiez et collez l'URL ci-dessous dans votre navigateur web : {{ $actionUrl }}</p>
    </div>
</div>
</body>
</html>
