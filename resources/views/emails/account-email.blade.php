<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject }}</title>
</head>
<body style="font-family:Arial,sans-serif; line-height:1.6; color:#333; margin:0; padding:0; background-color:#ffffff;">
<div style="max-width:600px; margin:0 auto; padding:20px;">
    <div style="padding:40px 30px; text-align:center; border-bottom:1px solid #e0e0e0;">
        @include('emails.partials.logo')
    </div>
    <div style="padding:20px;">
        <h2>{{ $subject }}</h2>
        <p>{{ $greeting ?: 'Bonjour,' }}</p>
        @foreach ($introLines as $line)
            <p>{{ $line }}</p>
        @endforeach
        <p><a href="{{ $actionUrl }}" style="display:inline-block; padding:12px 24px; background-color:#343C6A; color:#ffffff; text-decoration:none; border-radius:4px; margin:20px 0;">{{ $actionText }}</a></p>
        @foreach ($outroLines as $line)
            <p>{{ $line }}</p>
        @endforeach
        <p>Cordialement,<br>L'équipe Toollab</p>
    </div>
    <div style="text-align:center; padding:20px; font-size:12px; color:#666; overflow-wrap:anywhere; word-break:break-word;">
        <p>Si le bouton « {{ $actionText }} » ne fonctionne pas, copiez et collez ce lien dans votre navigateur :</p>
        <a href="{{ $actionUrl }}" style="color:#343C6A;">{{ $actionUrl }}</a>
    </div>
</div>
</body>
</html>
