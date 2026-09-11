<?php

namespace Database\Seeders;

use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Domain\Works\Enums\WorkStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\MatchCardModel;
use App\Infrastructure\Persistence\Eloquent\MatchSessionModel;
use App\Infrastructure\Persistence\Eloquent\OfferModel;
use App\Infrastructure\Persistence\Eloquent\ProviderCategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderDocumentModel;
use App\Infrastructure\Persistence\Eloquent\ProviderLocationModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use App\Infrastructure\Persistence\Eloquent\WorkQuoteModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StagingSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('Iniciando StagingSeeder (10 clients, 20 providers, 50 works con fake_data_source = staging-test)...');

        // Asegurar que roles y categorías base existan
        $this->call([
            RoleSeeder::class,
            CategorySeeder::class,
        ]);

        $categories = CategoryModel::all();
        $password = Hash::make('StagingPassword123!');

        // 1. Crear 10 clientes staging
        $clients = [];
        for ($i = 1; $i <= 10; $i++) {
            $client = UserModel::firstOrCreate(
                ['email' => "staging_client_{$i}@lizto.test"],
                [
                    'uuid' => (string) Str::uuid(),
                    'name' => "Cliente Staging {$i}",
                    'password' => $password,
                    'status' => 'active',
                ]
            );
            $client->assignRole('client');
            $clients[] = $client;
        }

        // 2. Crear 20 proveedores staging con perfiles y categorías
        $providers = [];
        $zones = [
            ['name' => 'Palermo', 'lat' => -34.5889, 'lng' => -58.4305],
            ['name' => 'Recoleta', 'lat' => -34.5875, 'lng' => -58.3974],
            ['name' => 'Belgrano', 'lat' => -34.5627, 'lng' => -58.4564],
            ['name' => 'Balvanera', 'lat' => -34.6037, 'lng' => -58.3816],
            ['name' => 'Caballito', 'lat' => -34.6177, 'lng' => -58.4447],
        ];

        for ($j = 1; $j <= 20; $j++) {
            $pUser = UserModel::firstOrCreate(
                ['email' => "staging_provider_{$j}@lizto.test"],
                [
                    'uuid' => (string) Str::uuid(),
                    'name' => "Profesional Staging {$j}",
                    'password' => $password,
                    'status' => 'active',
                ]
            );
            $pUser->assignRole('provider');

            $cat = $categories->get(($j - 1) % $categories->count());
            $zone = $zones[($j - 1) % count($zones)];

            $profile = ProviderProfileModel::updateOrCreate(
                ['user_id' => $pUser->id],
                [
                    'uuid' => (string) Str::uuid(),
                    'category_id' => $cat->id,
                    'bio' => "Profesional certificado en {$cat->name} para entorno de Staging.",
                    'years_experience' => 3 + ($j % 15),
                    'is_verified' => true,
                    'status' => ProviderProfileStatus::Verified,
                    'avg_rating' => 4.5 + (($j % 5) * 0.1),
                    'total_reviews' => 10 + $j,
                    'total_jobs_completed' => 15 + ($j * 2),
                    'availability_status' => 'available',
                    'coverage_radius_km' => 20,
                    'base_lat' => $zone['lat'],
                    'base_lng' => $zone['lng'],
                    'base_address' => "{$zone['name']}, CABA",
                ]
            );

            ProviderCategoryModel::updateOrCreate(
                ['provider_id' => $profile->id, 'category_id' => $cat->id],
                [
                    'specialties' => ['urgencias', 'mantenimiento', 'instalacion'],
                    'price_type' => 'fixed',
                    'price_from' => 5000,
                    'price_to' => 30000,
                    'is_active' => true,
                ]
            );

            ProviderDocumentModel::updateOrCreate(
                ['provider_id' => $profile->id, 'document_type' => 'identity'],
                [
                    'uuid' => (string) Str::uuid(),
                    'document_number' => "STG" . str_pad($j, 6, '0', STR_PAD_LEFT),
                    'status' => 'verified',
                    'verified_at' => now(),
                    'file_path' => "staging/docs/doc_{$j}.pdf",
                ]
            );

            $identity = \App\Models\Identity::updateOrCreate(
                ['user_id' => $pUser->id],
                [
                    'dni' => '30' . str_pad((string) $j, 6, '0', STR_PAD_LEFT),
                    'firstname' => "Profesional",
                    'lastname' => "Staging {$j}",
                    'status' => 'approved',
                    'verified_at' => now(),
                    'verified_by' => 'staging_seeder',
                ]
            );

            \App\Models\ProfessionalMVU::updateOrCreate(
                ['provider_id' => $profile->id],
                [
                    'identity_id' => $identity->id,
                    'identity_verified_at' => now(),
                    'antecedentes_status' => 'approved',
                    'antecedentes_cert_uploaded_at' => now(),
                    'matrícula_number' => 'MAT-STG-' . $j,
                    'matrícula_verified_at' => now(),
                    'skills_verified' => true,
                    'overall_verification_status' => 'approved',
                ]
            );

            // Ubicación reciente para geo-tracking
            ProviderLocationModel::create([
                'provider_id' => $profile->id,
                'latitude' => $zone['lat'] + (rand(-10, 10) * 0.001),
                'longitude' => $zone['lng'] + (rand(-10, 10) * 0.001),
                'accuracy_meters' => 10,
                'heading' => ($j * 25) % 360,
                'speed_kmh' => 15.0 + ($j % 20),
            ]);

            $providers[] = ['user' => $pUser, 'profile' => $profile, 'category' => $cat];
        }

        // 3. Crear 50 trabajos staging con estados variados y fake_data_source = 'staging-test'
        $statuses = [
            WorkStatus::Confirmed,
            WorkStatus::InProgress,
            WorkStatus::Completed,
            WorkStatus::Cancelled,
            WorkStatus::PendingDiagnosisQuote,
        ];

        for ($k = 1; $k <= 50; $k++) {
            $client = $clients[($k - 1) % count($clients)];
            $provData = $providers[($k - 1) % count($providers)];
            $profile = $provData['profile'];
            $cat = $provData['category'];
            $status = $statuses[($k - 1) % count($statuses)];
            $zone = $zones[($k - 1) % count($zones)];

            $sr = ServiceRequestModel::create([
                'uuid' => (string) Str::uuid(),
                'client_id' => $client->id,
                'category_id' => $cat->id,
                'raw_prompt' => "Solicitud Staging #{$k}: Servicio de {$cat->name}",
                'status' => 'matching_active',
                'location_lat' => $zone['lat'],
                'location_lng' => $zone['lng'],
                'location_address' => "{$zone['name']}, Buenos Aires",
            ]);

            $session = MatchSessionModel::create([
                'uuid' => (string) Str::uuid(),
                'service_request_id' => $sr->id,
                'status' => 'active',
            ]);

            $card = MatchCardModel::create([
                'match_session_id' => $session->id,
                'provider_id' => $profile->id,
                'rank_position' => 1,
                'score_total' => 0.95,
                'card_status' => 'accepted',
            ]);

            $offer = OfferModel::create([
                'uuid' => (string) Str::uuid(),
                'service_request_id' => $sr->id,
                'provider_id' => $profile->id,
                'status' => \App\Domain\Offers\Enums\OfferStatus::Accepted,
                'proposed_price' => 12000 + ($k * 500),
                'currency_code' => 'ARS',
                'estimated_duration_min' => 60,
                'proposed_start_at' => now()->addHours($k),
            ]);

            $work = WorkModel::create([
                'uuid' => (string) Str::uuid(),
                'service_request_id' => $sr->id,
                'match_card_id' => $card->id,
                'client_id' => $client->id,
                'provider_id' => $profile->id,
                'status' => $status,
                'fake_data_source' => 'staging-test',
                'currency' => 'ARS',
                'agreed_price' => 12000 + ($k * 500),
                'scheduled_at' => now()->addHours($k),
                'confirmed_at' => now()->subHours(1),
                'completed_at' => $status === WorkStatus::Completed ? now()->subMinutes(10) : null,
                'estimated_duration_min' => 60,
                'work_lat' => $zone['lat'],
                'work_lng' => $zone['lng'],
                'work_address' => "{$zone['name']}, Buenos Aires",
            ]);

            WorkQuoteModel::create([
                'uuid' => (string) Str::uuid(),
                'work_id' => $work->id,
                'provider_id' => $profile->id,
                'client_id' => $client->id,
                'amount' => 12000 + ($k * 500),
                'currency' => 'ARS',
                'status' => 'accepted',
                'accepted_at' => now()->subHours(1),
            ]);
        }

        $this->command?->info('StagingSeeder completado con éxito.');
    }
}
