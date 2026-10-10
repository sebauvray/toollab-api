<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subjectLine }}</title>
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
        <p>Bonjour {{ $name }},</p>
        {!! nl2br(e($body)) !!}
        <p style="margin-top: 24px;">{{ $senderName }}<br>L'équipe Toollab</p>
    </div>
    <div class="footer">
        <p>Message envoyé par l'équipe Toollab au sujet de {{ $schoolName }}. Répondez directement à cet e-mail pour lui écrire.</p>
    </div>
</div>
</body>
</html>
