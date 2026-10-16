<?php

namespace Database\Seeders;

use App\Infrastructure\Persistence\Eloquent\UserModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Seed a default administrator user for development and testing.
     *
     * @throws \RuntimeException If executed in a production environment.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('AdminUserSeeder is strictly prohibited in production environment.');
        }

        $user = UserModel::updateOrCreate(
            ['email' => 'admin@lizto.test'],
            [
                'name' => 'Admin Lizto',
                'password' => Hash::make('password'),
                'status' => 'active',
            ]
        );

        $user->syncRoles(['admin']);
    }
}
