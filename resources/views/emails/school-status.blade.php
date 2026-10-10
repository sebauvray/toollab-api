<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $status === 'suspended' ? 'Établissement suspendu' : 'Établissement réactivé' }}</title>
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
        @if($status === 'suspended')
            <h2>Établissement suspendu</h2>
            <p>Bonjour {{ $name }},</p>
            <p>L'accès à l'établissement <strong>{{ $schoolName }}</strong> sur Toollab a été suspendu. Les membres de l'équipe ne peuvent plus s'y connecter pour le moment.</p>
            @if($reason)
                <p>Motif : <em>{{ $reason }}</em></p>
            @endif
            <p>Les données de l'établissement sont conservées. Pour toute question, répondez simplement à cet e-mail.</p>
        @else
            <h2>Établissement réactivé</h2>
            <p>Bonjour {{ $name }},</p>
            <p>L'accès à l'établissement <strong>{{ $schoolName }}</strong> sur Toollab est de nouveau ouvert. Votre équipe peut se reconnecter normalement.</p>
        @endif
        <p>Cordialement,<br>L'équipe Toollab</p>
    </div>
    <div class="footer">
        <p>Ce message concerne le statut de votre établissement sur Toollab.</p>
    </div>
</div>
</body>
</html>
