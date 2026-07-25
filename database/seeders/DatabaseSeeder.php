<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CategorySeeder::class,
            SurveyQuestionSeeder::class,
            ClientUserSeeder::class,
            ProviderSeeder::class,
        ]);
    }
}