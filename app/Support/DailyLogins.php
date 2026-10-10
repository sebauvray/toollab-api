<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Compteur journalier des connexions (hors « se connecter en tant que »). */
class DailyLogins
{
    /** À appeler avant la mise à jour de last_login_at. */
    public static function record(User $user): void
    {
        try {
            $firstToday = $user->last_login_at === null || $user->last_login_at->lt(today());
            DB::table('daily_logins')->upsert(
                [['date' => today()->toDateString(), 'logins' => 1, 'unique_users' => $firstToday ? 1 : 0]],
                ['date'],
                [
                    'logins' => DB::raw('logins + 1'),
                    'unique_users' => DB::raw('unique_users + '.($firstToday ? 1 : 0)),
                ]
            );
        } catch (\Throwable $e) {
            // Une statistique ne doit jamais empêcher une connexion
            report($e);
        }
    }

    /** Les $days derniers jours (aujourd'hui inclus), jours sans connexion à 0. */
    public static function series(int $days = 30): array
    {
        $start = today()->subDays($days - 1);
        $rows = DB::table('daily_logins')->where('date', '>=', $start->toDateString())->get()
            ->keyBy(fn ($r) => substr((string) $r->date, 0, 10));

        return collect(range(0, $days - 1))->map(function ($i) use ($start, $rows) {
            $date = $start->copy()->addDays($i)->toDateString();
            $row = $rows[$date] ?? null;

            return ['date' => $date, 'logins' => (int) ($row->logins ?? 0), 'unique_users' => (int) ($row->unique_users ?? 0)];
        })->all();
    }

    /** Date du premier compteur : la courbe n'a de sens qu'à partir de là. */
    public static function since(): ?string
    {
        $first = DB::table('daily_logins')->min('date');

        return $first ? substr((string) $first, 0, 10) : null;
    }
}
