<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = Carbon::now();
        $startYear = $now->month >= 9 ? $now->year : $now->year - 1;
        $label = $startYear . '-' . ($startYear + 1);

        DB::transaction(function () use ($now, $label) {
            foreach (DB::table('schools')->select('id')->get() as $school) {
                $yearId = DB::table('school_years')
                    ->where('school_id', $school->id)
                    ->where('label', $label)
                    ->value('id')
                    ?? DB::table('school_years')->insertGetId([
                        'school_id' => $school->id,
                        'label' => $label,
                        'opened_at' => $now,
                        'closed_at' => null,
                        'is_active' => true,
                        'created_by' => null,
                        'updated_by' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                DB::table('classrooms')
                    ->where('school_id', $school->id)
                    ->whereNull('school_year_id')
                    ->update(['school_year_id' => $yearId]);

                $cursusIds = DB::table('cursus')->where('school_id', $school->id)->pluck('id');
                if ($cursusIds->isNotEmpty()) {
                    DB::table('tarifs')->whereIn('cursus_id', $cursusIds)->whereNull('school_year_id')->update(['school_year_id' => $yearId]);
                    DB::table('reduction_familiales')->whereIn('cursus_id', $cursusIds)->whereNull('school_year_id')->update(['school_year_id' => $yearId]);
                    DB::table('reduction_multi_cursuses')->whereIn('cursus_beneficiaire_id', $cursusIds)->whereNull('school_year_id')->update(['school_year_id' => $yearId]);
                }

                $familyIds = DB::table('families')->where('school_id', $school->id)->pluck('id');
                if ($familyIds->isNotEmpty()) {
                    DB::table('paiements')->whereIn('family_id', $familyIds)->whereNull('school_year_id')->update(['school_year_id' => $yearId]);
                }
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            foreach (['paiements', 'reduction_multi_cursuses', 'reduction_familiales', 'tarifs', 'classrooms'] as $table) {
                DB::table($table)->update(['school_year_id' => null]);
            }
            DB::table('school_years')->where('label', 'LIKE', '____-____')->delete();
        });
    }
};
