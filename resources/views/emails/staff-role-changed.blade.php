<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mise à jour de vos rôles</title>
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
            padding: 30px 20px;
            text-align: center;
            background-color: #ffffff;
            border-bottom: 1px solid #e0e0e0;
        }
        .brand {
            color: #343C6A;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 0;
        }
        .content {
            padding: 20px;
            background-color: #ffffff;
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
        <div class="brand">Toollab</div>
    </div>
    <div class="content">
        <h2>Mise à jour de vos rôles</h2>
        <p>Bonjour {{ trim(($notifiable->first_name ?? '') . ' ' . ($notifiable->last_name ?? '')) ?: $notifiable->email }},</p>

        @if($action === 'added')
            <p>Un nouveau rôle vous a été attribué dans l'établissement <strong>{{ $schoolName }}</strong>.</p>
            <p>Rôle ajouté :</p>
            <ul>
                @foreach($changedRoles as $role)
                    <li><strong>{{ $role }}</strong></li>
                @endforeach
            </ul>

            <p>Vos rôles actuels dans cet établissement sont :</p>
            <ul>
                @foreach($currentRoles as $role)
                    <li>{{ $role }}</li>
                @endforeach
            </ul>
        @elseif($action === 'removed')
            <p>Un rôle vous a été retiré dans l'établissement <strong>{{ $schoolName }}</strong>.</p>
            <p>Rôle retiré :</p>
            <ul>
                @foreach($changedRoles as $role)
                    <li><strong>{{ $role }}</strong></li>
                @endforeach
            </ul>

            @if(!empty($currentRoles))
                <p>Vos rôles restants dans cet établissement sont :</p>
                <ul>
                    @foreach($currentRoles as $role)
                        <li>{{ $role }}</li>
                    @endforeach
                </ul>
            @else
                <p>Vous n'avez plus de rôle dans cet établissement.</p>
            @endif
        @elseif($action === 'removed_from_school')
            <p>Votre accès à l'établissement <strong>{{ $schoolName }}</strong> a été retiré.</p>
            <p>Les rôles retirés étaient :</p>
            <ul>
                @foreach($changedRoles as $role)
                    <li><strong>{{ $role }}</strong></li>
                @endforeach
            </ul>
        @endif

        <p>Cordialement,<br>L'équipe Toollab</p>
    </div>
    <div class="footer">
        <p>Ce message vous informe d'un changement de droits sur Toollab.</p>
    </div>
</div>
</body>
</html>
