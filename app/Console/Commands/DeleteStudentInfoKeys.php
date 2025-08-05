<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\UserInfo;

class DeleteStudentInfoKeys extends Command
{
    protected $signature = 'delete:student-info-keys';
    protected $description = 'Delete specific keys from the user_infos table';

    /**
     * The keys to be deleted.
     *
     * @var array
     */
    protected $keysToDelete = [
        'statut_scolaire',
        'abandon',
        'renvoi',
        'renvoi_motif',
        'passage',
        'redoublement',
        'autre',
        'commentaires',
        'classe_precedente'
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting deletion of specified keys from user_infos table...');

        $count = UserInfo::whereIn('key', $this->keysToDelete)->count();

        if ($count === 0) {
            $this->info('No records found with the specified keys.');
            return;
        }

        $this->info("Found {$count} records to delete.");

        if ($this->confirm('Do you wish to continue?', true)) {
            $deleted = UserInfo::whereIn('key', $this->keysToDelete)->delete();
            $this->info("Successfully deleted {$deleted} records from user_infos table.");
        } else {
            $this->info('Operation cancelled.');
        }
    }
}
