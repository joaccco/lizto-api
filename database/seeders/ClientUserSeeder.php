<?php

namespace Database\Seeders;

use App\Infrastructure\Persistence\Eloquent\UserModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ClientUserSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([['Juan Pérez', 'juan@test.com'], ['María García', 'maria@test.com'], ['Carlos López', 'carlos@test.com']] as [$name, $email]) {
            UserModel::updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => Hash::make('password'), 'status' => 'active']
            );
        }
    }
}