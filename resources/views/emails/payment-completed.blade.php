<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirmation d'inscription</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 0;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
        }
        .header {
            padding: 40px 30px;
            text-align: center;
            background-color: #ffffff;
            border-bottom: 1px solid #e0e0e0;
        }
        .logo {
            height: 40px;
            width: auto;
        }
        .content {
            padding: 40px 30px;
        }
        .greeting {
            font-size: 16px;
            margin-bottom: 10px;
        }
        .confirmation {
            font-size: 16px;
            margin-bottom: 30px;
            color: #155724;
        }
        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #343C6A;
            margin: 30px 0 20px 0;
            border-bottom: 1px solid #e0e0e0;
            padding-bottom: 10px;
        }
        .student-block {
            margin-bottom: 30px;
        }
        .student-name {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
        }
        .class-block {
            background-color: #fafafa;
            border-left: 3px solid #343C6A;
            padding: 15px;
            margin-bottom: 15px;
        }
        .class-name {
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
        }
        .schedule-list {
            margin: 10px 0;
        }
        .schedule-item {
            color: #666;
            margin: 5px 0;
            font-size: 14px;
        }
        .telegram-link {
            color: #0088cc;
            text-decoration: none;
            font-size: 14px;
            display: block;
            margin-top: 10px;
        }
        .telegram-link:hover {
            text-decoration: underline;
        }
        .summary-section {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 4px;
            margin-top: 30px;
        }
        .summary-title {
            font-weight: 600;
            margin-bottom: 15px;
            color: #333;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin: 8px 0;
            font-size: 14px;
            color: #666;
        }
        .summary-total {
            border-top: 1px solid #dee2e6;
            padding-top: 10px;
            margin-top: 10px;
            font-weight: 600;
            color: #333;
        }
        .footer {
            text-align: center;
            padding: 30px;
            font-size: 12px;
            color: #999;
            background-color: #fafafa;
            border-top: 1px solid #e0e0e0;
        }
        .closing {
            margin-top: 30px;
            font-size: 14px;
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
        <p class="greeting">Bonjour {{ $notifiable->first_name }} {{ $notifiable->last_name }},</p>

        <p class="greeting">Votre inscription @if($schoolName)à {{ $schoolName }} @endif a été confirmée avec succès.</p>

        <h2 class="section-title">Détail de l'inscription</h2>

        @foreach($studentsData as $data)
            <div class="student-block">
                <h3 class="student-name">{{ $data['student']->first_name }} {{ $data['student']->last_name }}</h3>

                @foreach($data['enrollments'] as $enrollment)
                    <div class="class-block">
                        <div class="class-name">
                            {{ $enrollment->classroom->name }}
                            @if($enrollment->classroom->cursus)
                                - {{ $enrollment->classroom->cursus->name }}
                            @endif
                        </div>

                        @if($enrollment->classroom->schedules->count() > 0)
                            <div class="schedule-list">
                                @foreach($enrollment->classroom->schedules as $schedule)
                                    <div class="schedule-item">
                                        {{ $schedule->day }} : {{ $schedule->start_time }} - {{ $schedule->end_time }}
                                        @if($schedule->teacher_name)
                                            ({{ $schedule->teacher_name }})
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if($enrollment->classroom->telegram_link)
                            <a href="{{ $enrollment->classroom->telegram_link }}" class="telegram-link">
                                Groupe Telegram : {{ $enrollment->classroom->telegram_link }}
                            </a>
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach

        <div class="summary-section">
            <div class="summary-title">Résumé du paiement</div>
            <div class="summary-row">
                <span>Montant total</span>
                <span>{{ number_format($paymentDetails['montant_total'], 2, ',', ' ') }} €</span>
            </div>
            <div class="summary-row">
                <span>Montant payé</span>
                <span>{{ number_format($paymentDetails['montant_paye'], 2, ',', ' ') }} €</span>
            </div>
            <div class="summary-row summary-total">
                <span>Reste à payer</span>
                <span>0,00 €</span>
            </div>
        </div>

        <p class="closing">Cordialement,<br>L'équipe Toollab</p>
    </div>
    <div class="footer">
        <p>Cet email est envoyé automatiquement suite à la finalisation de votre paiement.</p>
    </div>
</div>
</body>
</html>
