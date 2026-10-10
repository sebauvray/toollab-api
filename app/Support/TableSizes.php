<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Taille des tables (information_schema) et historique quotidien.
 *
 * Pas de cron en production : la photo du jour est prise à la première
 * consultation de l'admin (snapshotToday), une fois par jour au plus.
 */
class TableSizes
{
    /** Au-delà, on garde l'estimation d'InnoDB plutôt qu'un COUNT(*) coûteux. */
    private const EXACT_COUNT_BELOW = 100000;

    /** @return array<string, array{rows:int, data_bytes:int, index_bytes:int, rows_exact:bool}> */
    public static function current(): array
    {
        $tables = DB::select(
            'SELECT TABLE_NAME AS name, TABLE_ROWS AS est_rows, DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ?',
            ['BASE TABLE']
        );

        $result = [];
        foreach ($tables as $t) {
            $exact = (int) $t->est_rows < self::EXACT_COUNT_BELOW;
            $result[$t->name] = [
                'rows' => $exact ? DB::table($t->name)->count() : (int) $t->est_rows,
                'data_bytes' => (int) $t->data_bytes,
                'index_bytes' => (int) $t->index_bytes,
                'rows_exact' => $exact,
            ];
        }

        return $result;
    }

    public static function snapshotToday(): void
    {
        $today = Carbon::today()->toDateString();
        if (DB::table('table_size_snapshots')->where('snapshot_date', $today)->exists()) {
            return;
        }

        $rows = collect(self::current())->map(fn ($t, $name) => [
            'snapshot_date' => $today,
            'table_name' => $name,
            'rows' => $t['rows'],
            'data_bytes' => $t['data_bytes'],
            'index_bytes' => $t['index_bytes'],
        ])->values()->all();

        // insertOrIgnore : deux consultations simultanées ne doivent pas échouer
        DB::table('table_size_snapshots')->insertOrIgnore($rows);
    }

    /**
     * Tables triées par taille, avec la croissance depuis la photo la plus
     * proche d'il y a 7 et 30 jours (null tant que l'historique est trop court).
     */
    public static function report(): array
    {
        $current = self::current();
        $before7 = self::snapshotAtOrBefore(Carbon::today()->subDays(7));
        $before30 = self::snapshotAtOrBefore(Carbon::today()->subDays(30));

        $tables = collect($current)->map(function ($t, $name) use ($before7, $before30) {
            $size = $t['data_bytes'] + $t['index_bytes'];

            return [
                'name' => $name,
                'rows' => $t['rows'],
                'rows_exact' => $t['rows_exact'],
                'size_bytes' => $size,
                'rows_7d' => isset($before7['tables'][$name]) ? $t['rows'] - $before7['tables'][$name]['rows'] : null,
                'rows_30d' => isset($before30['tables'][$name]) ? $t['rows'] - $before30['tables'][$name]['rows'] : null,
                'size_30d' => isset($before30['tables'][$name]) ? $size - $before30['tables'][$name]['size'] : null,
            ];
        })->sortByDesc('size_bytes')->values();

        $daily = DB::table('table_size_snapshots')
            ->where('snapshot_date', '>=', Carbon::today()->subDays(29))
            ->groupBy('snapshot_date')
            ->orderBy('snapshot_date')
            ->selectRaw('snapshot_date, SUM(data_bytes + index_bytes) AS size, SUM(`rows`) AS total_rows')
            ->get();

        return [
            'total_bytes' => $tables->sum('size_bytes'),
            'total_rows' => $tables->sum('rows'),
            'tables' => $tables,
            'compared_to' => ['7d' => $before7['date'] ?? null, '30d' => $before30['date'] ?? null],
            'size_30d' => isset($before30['tables']) ? $tables->sum('size_bytes') - collect($before30['tables'])->sum('size') : null,
            'daily' => $daily->map(fn ($d) => [
                'date' => (string) $d->snapshot_date,
                'size_bytes' => (int) $d->size,
                'rows' => (int) $d->total_rows,
            ]),
        ];
    }

    /** Résumé léger pour le tableau de bord (sans COUNT(*) table par table). */
    public static function summary(): array
    {
        $total = (int) DB::scalar(
            'SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        );
        $before = self::snapshotAtOrBefore(Carbon::today()->subDays(30));

        return [
            'total_bytes' => $total,
            'size_30d' => isset($before['tables']) ? $total - collect($before['tables'])->sum('size') : null,
            'compared_to' => $before['date'] ?? null,
        ];
    }

    private static function snapshotAtOrBefore(Carbon $date): ?array
    {
        // La plus ancienne photo de la fenêtre si l'historique ne remonte pas jusque-là
        $snapshotDate = DB::table('table_size_snapshots')->where('snapshot_date', '<=', $date->toDateString())->max('snapshot_date')
            ?? DB::table('table_size_snapshots')->where('snapshot_date', '<', Carbon::today()->toDateString())->min('snapshot_date');

        if (! $snapshotDate) {
            return null;
        }

        $tables = DB::table('table_size_snapshots')->where('snapshot_date', $snapshotDate)->get()
            ->mapWithKeys(fn ($r) => [$r->table_name => ['rows' => (int) $r->rows, 'size' => (int) $r->data_bytes + (int) $r->index_bytes]])
            ->all();

        return ['date' => (string) $snapshotDate, 'tables' => $tables];
    }
}
