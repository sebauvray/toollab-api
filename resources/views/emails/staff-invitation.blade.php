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
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        @include('emails.partials.logo')

    </div>
    <div class="content">
        <h2>Invitation à rejoindre l'équipe</h2>
        <p>Bonjour {{ trim(($notifiable->first_name ?? '') . ' ' . ($notifiable->last_name ?? '')) ?: $notifiable->email }},</p>
        <p>Vous avez été invité(e) à rejoindre l'équipe de <strong>{{ $schoolName }}</strong>.</p>
        @if(!empty($roleNames) && count($roleNames) > 1)
            <p>Les rôles qui vous ont été attribués sont :</p>
            <ul>
                @foreach($roleNames as $role)
                    <li><strong>{{ $role }}</strong></li>
                @endforeach
            </ul>
        @elseif(!empty($roleName))
            <p>Le rôle qui vous a été attribué est : <strong>{{ $roleName }}</strong>.</p>
        @else
            <p>Un accès à cet établissement vous a été attribué.</p>
        @endif
        <p>Pour activer votre compte, cliquez sur le lien ci-dessous.</p>
        <p><a href="{{ $actionUrl }}" class="button"  style="color: white">Activer mon compte</a></p>
        <p>Ce lien d'invitation expirera dans 7 jours.</p>
        <p>Cordialement,<br>L'équipe Toollab</p>
    </div>
    <div class="footer">
        <p>Si vous rencontrez des problèmes en cliquant sur le bouton "Activer mon compte", copiez et collez l'URL ci-dessous dans votre navigateur web: {{ $actionUrl }}</p>
    </div>
</div>
</body>
</html>
