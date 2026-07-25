<?php

namespace Database\Seeders;

use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderCategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ProviderScheduleModel;
use App\Infrastructure\Persistence\Eloquent\ProviderServiceAreaModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ProviderSeeder extends Seeder
{
    public function run(): void
    {
        $category = CategoryModel::where('slug', 'cerrajeria')->firstOrFail();
        $providers = [
            ['name' => 'Roberto Medina', 'email' => 'roberto@lizto.test', 'bio' => 'Cerrajero con 12 años de experiencia.', 'years_experience' => 12, 'is_verified' => true, 'avg_rating' => 4.9, 'total_reviews' => 87, 'total_jobs_completed' => 124, 'completion_rate' => 98.4, 'cancellation_count' => 2, 'response_rate' => 96.0, 'avg_response_minutes' => 8, 'base_lat' => -27.4692, 'base_lng' => -58.8306, 'base_address' => 'Av. 3 de Abril 850, Corrientes', 'availability_status' => 'available', 'specialties' => ['apertura','reemplazo','reparacion','duplicado_llaves'], 'price_type' => 'fixed', 'price_from' => 8000, 'price_to' => 35000, 'radius_km' => 15, 'days' => range(1, 6), 'start' => '08:00', 'end' => '20:00'],
            ['name' => 'Diego Fernández', 'email' => 'diego@lizto.test', 'bio' => 'Cerrajero automotor y de edificios. Trabajo las 24hs.', 'years_experience' => 6, 'is_verified' => true, 'avg_rating' => 4.6, 'total_reviews' => 43, 'total_jobs_completed' => 67, 'completion_rate' => 95.5, 'cancellation_count' => 3, 'response_rate' => 88.0, 'avg_response_minutes' => 15, 'base_lat' => -27.4850, 'base_lng' => -58.8150, 'base_address' => 'Pellegrini 1200, Corrientes', 'availability_status' => 'busy', 'busy_until' => now()->addMinutes(25), 'next_available_at' => now()->addMinutes(35), 'specialties' => ['apertura','automotor','reemplazo'], 'price_type' => 'fixed', 'price_from' => 7500, 'price_to' => 40000, 'radius_km' => 20, 'days' => range(0, 6), 'start' => '00:00', 'end' => '23:59'],
            ['name' => 'Ana Kupfer', 'email' => 'ana@lizto.test', 'bio' => 'Cerrajera certificada. Especialista en cerraduras de seguridad.', 'years_experience' => 9, 'is_verified' => true, 'avg_rating' => 4.7, 'total_reviews' => 61, 'total_jobs_completed' => 89, 'completion_rate' => 97.8, 'cancellation_count' => 2, 'response_rate' => 94.0, 'avg_response_minutes' => 10, 'base_lat' => -27.4600, 'base_lng' => -58.8450, 'base_address' => 'San Juan 456, Corrientes', 'availability_status' => 'available', 'specialties' => ['reemplazo','instalacion','seguridad'], 'price_type' => 'fixed', 'price_from' => 9000, 'price_to' => 45000, 'radius_km' => 10, 'days' => range(1, 5), 'start' => '09:00', 'end' => '18:00'],
            ['name' => 'Carlos Villalba', 'email' => 'carlos.v@lizto.test', 'bio' => 'Cerrajero general. Apertura y duplicado de llaves.', 'years_experience' => 3, 'is_verified' => false, 'avg_rating' => 4.2, 'total_reviews' => 12, 'total_jobs_completed' => 18, 'completion_rate' => 88.9, 'cancellation_count' => 2, 'response_rate' => 75.0, 'avg_response_minutes' => 45, 'base_lat' => -27.4900, 'base_lng' => -58.8000, 'base_address' => 'Tucumán 320, Corrientes', 'availability_status' => 'available', 'specialties' => ['apertura','duplicado'], 'price_type' => 'quote', 'price_from' => null, 'price_to' => null, 'radius_km' => 8, 'days' => range(1, 6), 'start' => '10:00', 'end' => '19:00'],
            ['name' => 'Marcela Ríos', 'email' => 'marcela@lizto.test', 'bio' => 'Cerrajera integral. Todos los servicios, 7 días.', 'years_experience' => 11, 'is_verified' => true, 'avg_rating' => 4.8, 'total_reviews' => 29, 'total_jobs_completed' => 41, 'completion_rate' => 100.0, 'cancellation_count' => 0, 'response_rate' => 98.0, 'avg_response_minutes' => 5, 'base_lat' => -27.4750, 'base_lng' => -58.8350, 'base_address' => 'Mendoza 890, Corrientes', 'availability_status' => 'busy', 'busy_until' => now()->addMinutes(90), 'next_available_at' => now()->addMinutes(100), 'specialties' => ['apertura','reemplazo','reparacion','automotor','seguridad'], 'price_type' => 'fixed', 'price_from' => 10000, 'price_to' => 50000, 'radius_km' => 25, 'days' => range(0, 6), 'start' => '07:00', 'end' => '22:00'],
        ];

        foreach ($providers as $data) {
            $user = UserModel::updateOrCreate(['email' => $data['email']], ['name' => $data['name'], 'password' => Hash::make('password'), 'status' => 'active']);
            $profile = ProviderProfileModel::updateOrCreate(['user_id' => $user->id], collect($data)->only(['bio','years_experience','is_verified','avg_rating','total_reviews','total_jobs_completed','completion_rate','cancellation_count','response_rate','avg_response_minutes','base_lat','base_lng','base_address','availability_status','busy_until','next_available_at'])->all());
            ProviderCategoryModel::updateOrCreate(['provider_id' => $profile->id, 'category_id' => $category->id], ['specialties' => $data['specialties'], 'price_type' => $data['price_type'], 'price_from' => $data['price_from'], 'price_to' => $data['price_to'], 'is_active' => true]);
            ProviderServiceAreaModel::updateOrCreate(['provider_id' => $profile->id, 'label' => 'Corrientes'], ['center_lat' => $data['base_lat'], 'center_lng' => $data['base_lng'], 'radius_km' => $data['radius_km']]);
            foreach ($data['days'] as $day) {
                ProviderScheduleModel::updateOrCreate(['provider_id' => $profile->id, 'day_of_week' => $day], ['start_time' => $data['start'], 'end_time' => $data['end'], 'is_active' => true]);
            }
        }
    }
}