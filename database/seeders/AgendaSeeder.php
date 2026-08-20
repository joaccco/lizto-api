<?php

namespace Database\Seeders;

use App\Domain\Works\Enums\WorkStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\MatchCardModel;
use App\Infrastructure\Persistence\Eloquent\MatchSessionModel;
use App\Infrastructure\Persistence\Eloquent\OfferModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AgendaSeeder extends Seeder
{
    public function run(): void
    {
        $clients = UserModel::whereIn('email', ['juan@test.com', 'maria@test.com', 'carlos@test.com'])->get();
        if ($clients->isEmpty()) {
            return;
        }

        $providers = ProviderProfileModel::with(['user', 'categories'])->get();
        if ($providers->isEmpty()) {
            return;
        }

        $now = Carbon::now();
        $year = $now->year;
        $month = $now->month;

        $sampleAppointments = [
            [
                'day' => 15,
                'hour' => 9,
                'minute' => 30,
                'prompt' => 'Cambio de combinación de cerradura de seguridad',
                'address' => 'Av. Corrientes 1240, CABA',
                'status' => WorkStatus::Confirmed,
                'price' => 25000.00,
                'duration' => 60,
                'client_index' => 0,
            ],
            [
                'day' => 15,
                'hour' => 14,
                'minute' => 0,
                'prompt' => 'Apertura de puerta blindada trabada',
                'address' => 'Thames 1842, Palermo',
                'status' => WorkStatus::InProgress,
                'price' => 32000.00,
                'duration' => 90,
                'client_index' => 1,
            ],
            [
                'day' => 18,
                'hour' => 11,
                'minute' => 0,
                'prompt' => 'Instalación de cerradura digital inteligente',
                'address' => 'Av. Santa Fe 3400, Recoleta',
                'status' => WorkStatus::Confirmed,
                'price' => 45000.00,
                'duration' => 120,
                'client_index' => 2,
            ],
            [
                'day' => 20,
                'hour' => 16,
                'minute' => 30,
                'prompt' => 'Reparación de pérdida de agua y cañería',
                'address' => 'Córdoba 456, Corrientes',
                'status' => WorkStatus::Confirmed,
                'price' => 18000.00,
                'duration' => 45,
                'client_index' => 0,
            ],
            [
                'day' => 22,
                'hour' => 10,
                'minute' => 0,
                'prompt' => 'Revisión y cambio de térmicas en tablero eléctrico',
                'address' => 'Pellegrini 1200, Corrientes',
                'status' => WorkStatus::Confirmed,
                'price' => 28000.00,
                'duration' => 75,
                'client_index' => 1,
            ],
            [
                'day' => 25,
                'hour' => 15,
                'minute' => 30,
                'prompt' => 'Sesión fotográfica para marca de indumentaria',
                'address' => 'Parque San Martín, Corrientes',
                'status' => WorkStatus::Confirmed,
                'price' => 60000.00,
                'duration' => 180,
                'client_index' => 2,
            ],
        ];

        foreach ($providers as $provider) {
            $rawCatId = $provider->categories->first()?->id;
            $categoryId = ($rawCatId && CategoryModel::where('id', $rawCatId)->exists())
                ? $rawCatId
                : CategoryModel::first()?->id;

            foreach ($sampleAppointments as $app) {
                $client = $clients[$app['client_index'] % $clients->count()];
                $scheduledAt = Carbon::create($year, $month, $app['day'], $app['hour'], $app['minute'], 0);

                // Create ServiceRequest
                $sr = ServiceRequestModel::create([
                    'uuid' => (string) Str::uuid(),
                    'client_id' => $client->id,
                    'category_id' => $categoryId,
                    'raw_prompt' => $app['prompt'],
                    'location_address' => $app['address'],
                    'urgency' => 'scheduled',
                    'preferred_datetime' => $scheduledAt,
                    'status' => 'provider_selected',
                ]);

                // Create MatchSession & MatchCard
                $session = MatchSessionModel::create([
                    'uuid' => (string) Str::uuid(),
                    'service_request_id' => $sr->id,
                    'status' => 'active',
                ]);

                $card = MatchCardModel::create([
                    'match_session_id' => $session->id,
                    'provider_id' => $provider->id,
                    'rank_position' => 1,
                    'score_total' => 0.95,
                    'card_status' => 'accepted',
                ]);

                // Create Offer
                OfferModel::create([
                    'uuid' => (string) Str::uuid(),
                    'service_request_id' => $sr->id,
                    'provider_id' => $provider->id,
                    'status' => \App\Domain\Offers\Enums\OfferStatus::Accepted,
                    'proposed_price' => $app['price'],
                    'currency_code' => 'ARS',
                    'estimated_duration_min' => $app['duration'],
                    'proposed_start_at' => $scheduledAt,
                ]);

                // Create Work
                $work = WorkModel::create([
                    'uuid' => (string) Str::uuid(),
                    'service_request_id' => $sr->id,
                    'match_card_id' => $card->id,
                    'client_id' => $client->id,
                    'provider_id' => $provider->id,
                    'status' => $app['status'],
                    'scheduled_at' => $scheduledAt,
                    'estimated_duration_min' => $app['duration'],
                    'work_address' => $app['address'],
                    'agreed_price' => $app['price'],
                    'currency' => 'ARS',
                ]);
            }

            // Create a real completed work with rating for the provider
            $completedSr = ServiceRequestModel::create([
                'uuid' => (string) Str::uuid(),
                'client_id' => $clients[1]->id ?? $clients[0]->id,
                'category_id' => $categoryId,
                'raw_prompt' => 'Apertura urgente de puerta blindada y cambio de cerradura.',
                'location_address' => 'Thames 1842, Palermo',
                'urgency' => 'immediate',
                'status' => 'completed',
            ]);

            $completedWork = WorkModel::create([
                'uuid' => (string) Str::uuid(),
                'service_request_id' => $completedSr->id,
                'match_card_id' => MatchCardModel::first()?->id ?? 1,
                'client_id' => $clients[1]->id ?? $clients[0]->id,
                'provider_id' => $provider->id,
                'status' => WorkStatus::Completed,
                'scheduled_at' => $now->copy()->subDays(5),
                'completed_at' => $now->copy()->subDays(5)->addHour(),
                'estimated_duration_min' => 60,
                'work_address' => 'Thames 1842, Palermo',
                'agreed_price' => 28000,
                'currency' => 'ARS',
            ]);

            \App\Infrastructure\Persistence\Eloquent\RatingModel::firstOrCreate([
                'work_id' => $completedWork->id,
                'reviewer_id' => $clients[1]->id ?? $clients[0]->id,
            ], [
                'reviewed_id' => $provider->user_id,
                'direction' => 'client_to_provider',
                'score' => 5,
                'comment' => 'Excelente trabajo, solucionó el problema muy rápido y sin romper la puerta.',
                'created_at' => $now->copy()->subDays(4),
            ]);

            $allRatings = \App\Infrastructure\Persistence\Eloquent\RatingModel::where('reviewed_id', $provider->user_id)->get();
            if ($allRatings->count() > 0) {
                $provider->update([
                    'avg_rating' => round($allRatings->avg('score'), 2),
                    'total_reviews' => $allRatings->count(),
                ]);
            }
        }
    }
}
