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
        <h2>Passation de direction</h2>
        <p>Bonjour {{ trim(($notifiable->first_name ?? '') . ' ' . ($notifiable->last_name ?? '')) ?: $notifiable->email }},</p>

        @if($action === 'accepted')
            <p><strong>{{ $counterpartName }}</strong> a accepté de reprendre la direction de l'établissement <strong>{{ $schoolName }}</strong>. La passation est effective.</p>
            @if(!empty($remainingRoleNames))
                <p>{{ count($remainingRoleNames) > 1 ? 'Vos rôles' : 'Votre rôle' }} dans cet établissement : <strong>{{ implode(', ', $remainingRoleNames) }}</strong>.</p>
                <p>Vous avez été déconnecté de Toollab : reconnectez-vous pour continuer avec {{ count($remainingRoleNames) > 1 ? 'ces rôles' : 'ce rôle' }}.</p>
            @else
                <p>Conformément à votre choix, vous n'avez plus accès à cet établissement et avez été déconnecté de Toollab.</p>
            @endif
        @else
            <p>L'invitation à reprendre la direction de l'établissement <strong>{{ $schoolName }}</strong> envoyée à <strong>{{ $counterpartName }}</strong> a été refusée.</p>
            <p>Vous restez directeur de l'établissement. Vous pouvez lancer une nouvelle passation depuis les paramètres de l'établissement.</p>
        @endif

        <p>Cordialement,<br>L'équipe Toollab</p>
    </div>
    <div class="footer">
        <p>Ce message vous informe d'un changement de droits sur Toollab.</p>
    </div>
</div>
</body>
</html>
