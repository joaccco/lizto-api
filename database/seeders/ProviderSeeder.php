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
        $providers = [
            // CERRAJEROS (Original 5)
            ['name' => 'Roberto Medina', 'email' => 'roberto@lizto.test', 'category_slug' => 'cerrajeria', 'bio' => 'Cerrajero con 12 años de experiencia.', 'years_experience' => 12, 'is_verified' => true, 'avg_rating' => 4.9, 'total_reviews' => 87, 'total_jobs_completed' => 124, 'completion_rate' => 98.4, 'cancellation_count' => 2, 'response_rate' => 96.0, 'avg_response_minutes' => 8, 'base_lat' => -27.4692, 'base_lng' => -58.8306, 'base_address' => 'Av. 3 de Abril 850, Corrientes', 'availability_status' => 'available', 'specialties' => ['apertura','reemplazo','reparacion','duplicado_llaves'], 'price_type' => 'fixed', 'price_from' => 8000, 'price_to' => 35000, 'radius_km' => 15, 'days' => range(1, 6), 'start' => '08:00', 'end' => '20:00'],
            ['name' => 'Diego Fernández', 'email' => 'diego@lizto.test', 'category_slug' => 'cerrajeria', 'bio' => 'Cerrajero automotor y de edificios. Trabajo las 24hs.', 'years_experience' => 6, 'is_verified' => true, 'avg_rating' => 4.6, 'total_reviews' => 43, 'total_jobs_completed' => 67, 'completion_rate' => 95.5, 'cancellation_count' => 3, 'response_rate' => 88.0, 'avg_response_minutes' => 15, 'base_lat' => -27.4850, 'base_lng' => -58.8150, 'base_address' => 'Pellegrini 1200, Corrientes', 'availability_status' => 'busy', 'busy_until' => now()->addMinutes(25), 'next_available_at' => now()->addMinutes(35), 'specialties' => ['apertura','automotor','reemplazo'], 'price_type' => 'fixed', 'price_from' => 7500, 'price_to' => 40000, 'radius_km' => 20, 'days' => range(0, 6), 'start' => '00:00', 'end' => '23:59'],
            ['name' => 'Ana Kupfer', 'email' => 'ana@lizto.test', 'category_slug' => 'cerrajeria', 'bio' => 'Cerrajera certificada. Especialista en cerraduras de seguridad.', 'years_experience' => 9, 'is_verified' => true, 'avg_rating' => 4.7, 'total_reviews' => 61, 'total_jobs_completed' => 89, 'completion_rate' => 97.8, 'cancellation_count' => 2, 'response_rate' => 94.0, 'avg_response_minutes' => 10, 'base_lat' => -27.4600, 'base_lng' => -58.8450, 'base_address' => 'San Juan 456, Corrientes', 'availability_status' => 'available', 'specialties' => ['reemplazo','instalacion','seguridad'], 'price_type' => 'fixed', 'price_from' => 9000, 'price_to' => 45000, 'radius_km' => 10, 'days' => range(1, 5), 'start' => '09:00', 'end' => '18:00'],
            ['name' => 'Carlos Villalba', 'email' => 'carlos.v@lizto.test', 'category_slug' => 'cerrajeria', 'bio' => 'Cerrajero general. Apertura y duplicado de llaves.', 'years_experience' => 3, 'is_verified' => false, 'avg_rating' => 4.2, 'total_reviews' => 12, 'total_jobs_completed' => 18, 'completion_rate' => 88.9, 'cancellation_count' => 2, 'response_rate' => 75.0, 'avg_response_minutes' => 45, 'base_lat' => -27.4900, 'base_lng' => -58.8000, 'base_address' => 'Tucumán 320, Corrientes', 'availability_status' => 'available', 'specialties' => ['apertura','duplicado'], 'price_type' => 'quote', 'price_from' => null, 'price_to' => null, 'radius_km' => 8, 'days' => range(1, 6), 'start' => '10:00', 'end' => '19:00'],
            ['name' => 'Marcela Ríos', 'email' => 'marcela@lizto.test', 'category_slug' => 'cerrajeria', 'bio' => 'Cerrajera integral. Todos los servicios, 7 días.', 'years_experience' => 11, 'is_verified' => true, 'avg_rating' => 4.8, 'total_reviews' => 29, 'total_jobs_completed' => 41, 'completion_rate' => 100.0, 'cancellation_count' => 0, 'response_rate' => 98.0, 'avg_response_minutes' => 5, 'base_lat' => -27.4750, 'base_lng' => -58.8350, 'base_address' => 'Mendoza 890, Corrientes', 'availability_status' => 'busy', 'busy_until' => now()->addMinutes(90), 'next_available_at' => now()->addMinutes(100), 'specialties' => ['apertura','reemplazo','reparacion','automotor','seguridad'], 'price_type' => 'fixed', 'price_from' => 10000, 'price_to' => 50000, 'radius_km' => 25, 'days' => range(0, 6), 'start' => '07:00', 'end' => '22:00'],

            // ELECTRICISTAS
            ['name' => 'Lucas Romero', 'email' => 'lucas@lizto.test', 'category_slug' => 'electricidad', 'bio' => 'Electricista matriculado. Instalaciones y reparaciones.', 'years_experience' => 8, 'is_verified' => true, 'avg_rating' => 4.8, 'total_reviews' => 54, 'total_jobs_completed' => 98, 'completion_rate' => 97.0, 'cancellation_count' => 2, 'response_rate' => 92.0, 'avg_response_minutes' => 12, 'base_lat' => -27.4720, 'base_lng' => -58.8280, 'base_address' => 'Corrientes', 'availability_status' => 'available', 'specialties' => ['instalacion','reparacion','tablero','iluminacion'], 'price_type' => 'hourly', 'price_from' => 5000, 'price_to' => 15000, 'radius_km' => 12, 'days' => range(1, 6), 'start' => '08:00', 'end' => '18:00'],
            ['name' => 'Patricia Vega', 'email' => 'patricia@lizto.test', 'category_slug' => 'electricidad', 'bio' => 'Electricista residencial y comercial. 24hs urgencias.', 'years_experience' => 5, 'is_verified' => true, 'avg_rating' => 4.5, 'total_reviews' => 31, 'total_jobs_completed' => 52, 'completion_rate' => 94.2, 'cancellation_count' => 3, 'response_rate' => 85.0, 'avg_response_minutes' => 20, 'base_lat' => -27.4810, 'base_lng' => -58.8190, 'base_address' => 'Corrientes', 'availability_status' => 'available', 'specialties' => ['instalacion','urgencias','tablero'], 'price_type' => 'hourly', 'price_from' => 4500, 'price_to' => 12000, 'radius_km' => 15, 'days' => range(0, 6), 'start' => '00:00', 'end' => '23:59'],

            // FOTÓGRAFOS
            ['name' => 'Valentina Cruz', 'email' => 'valentina@lizto.test', 'category_slug' => 'fotografia', 'bio' => 'Fotógrafa profesional. Eventos, productos y retratos.', 'years_experience' => 7, 'is_verified' => true, 'avg_rating' => 4.9, 'total_reviews' => 42, 'total_jobs_completed' => 73, 'completion_rate' => 98.6, 'cancellation_count' => 1, 'response_rate' => 97.0, 'avg_response_minutes' => 30, 'base_lat' => -27.4650, 'base_lng' => -58.8400, 'base_address' => 'Corrientes', 'availability_status' => 'available', 'specialties' => ['eventos','productos','retratos','marca'], 'price_type' => 'fixed', 'price_from' => 25000, 'price_to' => 80000, 'radius_km' => 20, 'days' => range(1, 6), 'start' => '09:00', 'end' => '20:00'],
            ['name' => 'Martín Sosa', 'email' => 'martin@lizto.test', 'category_slug' => 'fotografia', 'bio' => 'Fotógrafo de bodas y eventos sociales.', 'years_experience' => 10, 'is_verified' => true, 'avg_rating' => 4.7, 'total_reviews' => 67, 'total_jobs_completed' => 89, 'completion_rate' => 96.6, 'cancellation_count' => 3, 'response_rate' => 91.0, 'avg_response_minutes' => 45, 'base_lat' => -27.4780, 'base_lng' => -58.8100, 'base_address' => 'Corrientes', 'availability_status' => 'available', 'specialties' => ['bodas','eventos','social'], 'price_type' => 'fixed', 'price_from' => 40000, 'price_to' => 120000, 'radius_km' => 30, 'days' => range(0, 6), 'start' => '08:00', 'end' => '22:00'],

            // CONTADORES
            ['name' => 'Sandra Méndez', 'email' => 'sandra@lizto.test', 'category_slug' => 'contaduria', 'bio' => 'Contadora pública. Monotributo, autónomos y pymes.', 'years_experience' => 12, 'is_verified' => true, 'avg_rating' => 4.9, 'total_reviews' => 89, 'total_jobs_completed' => 156, 'completion_rate' => 99.3, 'cancellation_count' => 1, 'response_rate' => 98.0, 'avg_response_minutes' => 60, 'base_lat' => -27.4700, 'base_lng' => -58.8350, 'base_address' => 'Corrientes', 'availability_status' => 'available', 'specialties' => ['monotributo','autonomos','pymes','impuestos'], 'price_type' => 'hourly', 'price_from' => 8000, 'price_to' => 25000, 'radius_km' => 50, 'days' => range(1, 5), 'start' => '09:00', 'end' => '18:00'],
            ['name' => 'Roberto Páez', 'email' => 'roberto.p@lizto.test', 'category_slug' => 'contaduria', 'bio' => 'Contador especialista en sociedades y grandes empresas.', 'years_experience' => 18, 'is_verified' => true, 'avg_rating' => 4.8, 'total_reviews' => 112, 'total_jobs_completed' => 203, 'completion_rate' => 98.0, 'cancellation_count' => 4, 'response_rate' => 95.0, 'avg_response_minutes' => 120, 'base_lat' => -27.4750, 'base_lng' => -58.8250, 'base_address' => 'Corrientes', 'availability_status' => 'available', 'specialties' => ['sociedades','empresas','auditoria','balances'], 'price_type' => 'hourly', 'price_from' => 15000, 'price_to' => 50000, 'radius_km' => 40, 'days' => range(1, 5), 'start' => '08:00', 'end' => '17:00'],

            // PLOMEROS
            ['name' => 'Jorge Aquino', 'email' => 'jorge@lizto.test', 'category_slug' => 'plomeria', 'bio' => 'Plomero con 15 años de experiencia. Urgencias 24hs.', 'years_experience' => 15, 'is_verified' => true, 'avg_rating' => 4.6, 'total_reviews' => 78, 'total_jobs_completed' => 134, 'completion_rate' => 95.5, 'cancellation_count' => 6, 'response_rate' => 88.0, 'avg_response_minutes' => 25, 'base_lat' => -27.4680, 'base_lng' => -58.8420, 'base_address' => 'Corrientes', 'availability_status' => 'available', 'specialties' => ['perdidas','cañerias','sanitarios','urgencias'], 'price_type' => 'fixed', 'price_from' => 6000, 'price_to' => 30000, 'radius_km' => 18, 'days' => range(0, 6), 'start' => '00:00', 'end' => '23:59'],

            // ABOGADOS
            ['name' => 'Gabriela Torres', 'email' => 'gabriela@lizto.test', 'category_slug' => 'abogacia', 'bio' => 'Abogada civilista. Contratos, familia y sucesiones.', 'years_experience' => 9, 'is_verified' => true, 'avg_rating' => 4.8, 'total_reviews' => 45, 'total_jobs_completed' => 67, 'completion_rate' => 97.0, 'cancellation_count' => 2, 'response_rate' => 94.0, 'avg_response_minutes' => 180, 'base_lat' => -27.4720, 'base_lng' => -58.8300, 'base_address' => 'Corrientes', 'availability_status' => 'available', 'specialties' => ['contratos','familia','sucesiones','civil'], 'price_type' => 'hourly', 'price_from' => 20000, 'price_to' => 60000, 'radius_km' => 50, 'days' => range(1, 5), 'start' => '09:00', 'end' => '18:00'],
        ];

        foreach ($providers as $data) {
            $category = CategoryModel::where('slug', $data['category_slug'])->first();

            $user = UserModel::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name'     => $data['name'],
                    'password' => Hash::make('password'),
                    'status'   => 'active',
                ]
            );

            $profile = ProviderProfileModel::updateOrCreate(
                ['user_id' => $user->id],
                collect($data)->only([
                    'bio', 'years_experience', 'is_verified', 'avg_rating', 'total_reviews',
                    'total_jobs_completed', 'completion_rate', 'cancellation_count', 'response_rate',
                    'avg_response_minutes', 'base_lat', 'base_lng', 'base_address', 'availability_status',
                    'busy_until', 'next_available_at'
                ])->all()
            );

            if ($category) {
                ProviderCategoryModel::updateOrCreate(
                    ['provider_id' => $profile->id, 'category_id' => $category->id],
                    [
                        'specialties' => $data['specialties'],
                        'price_type'  => $data['price_type'],
                        'price_from'  => $data['price_from'],
                        'price_to'    => $data['price_to'],
                        'is_active'   => true,
                    ]
                );
            }

            ProviderServiceAreaModel::updateOrCreate(
                ['provider_id' => $profile->id, 'label' => 'Corrientes'],
                [
                    'center_lat' => $data['base_lat'],
                    'center_lng' => $data['base_lng'],
                    'radius_km'  => $data['radius_km'],
                ]
            );

            foreach ($data['days'] as $day) {
                ProviderScheduleModel::updateOrCreate(
                    ['provider_id' => $profile->id, 'day_of_week' => $day],
                    [
                        'start_time' => $data['start'],
                        'end_time'   => $data['end'],
                        'is_active'  => true,
                    ]
                );
            }
        }
    }
}