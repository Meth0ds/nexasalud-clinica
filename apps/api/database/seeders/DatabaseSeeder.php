<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (
            app()->environment('local')
            && filter_var(env('SEED_CLINICAL_DEMO', false), FILTER_VALIDATE_BOOL)
        ) {
            $this->call(ClinicalDemoSeeder::class);
        }
    }
}
