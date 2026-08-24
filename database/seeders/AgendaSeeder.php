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
use Ramsey\Uuid\Uuid;

class AgendaSeeder extends Seeder
{
    public function run(): void
    {
        $clients = UserModel::whereIn('email', ['juan@test.com', 'maria@test.com', 'carlos@test.com'])->get();
        if ($clients->isEmpty()) {
            return;
        }

        $providers = ProviderProfileModel::with(['user', 'categories.category'])->get();
        if ($providers->isEmpty()) {
            return;
        }

        $now = Carbon::now();
        $year = $now->year;
        $month = $now->month;

        $categorySamplePrompts = [
            'cerrajeria' => [
                'prompts' => [
                    'Cambio de combinación de cerradura de seguridad',
                    'Apertura de puerta blindada trabada',
                    'Instalación de cerradura digital inteligente',
                    'Reparación de cerrojo de puerta principal',
                    'Duplicado e instalación de cerradura de alta seguridad',
                    'Cambio de cilindro multipunto en puerta de entrada',
                ],
                'completed_prompt' => 'Apertura urgente de puerta blindada y cambio de cerradura.',
            ],
            'cerrajeria-hogar' => [
                'prompts' => [
                    'Cambio de combinación de cerradura de casa',
                    'Apertura de puerta blindada trabada en domicilio',
                    'Instalación de cerradura digital para entrada principal',
                    'Reparación de cerrojo de puerta trasera',
                    'Duplicado e instalación de cerrojo en puerta de entrada',
                    'Cambio de cilindro multipunto residencial',
                ],
                'completed_prompt' => 'Apertura urgente de puerta blindada residencial.',
            ],
            'cerrajeria-automotor' => [
                'prompts' => [
                    'Apertura de puerta de auto con llave adentro',
                    'Copia e inmovilizador de llave codificada de vehículo',
                    'Reparación de tambor de arranque de auto',
                    'Cambio de combinación de cerradura de vehículo',
                    'Apertura urgente de baúl trabado',
                    'Reparación de telecomando y llave con chip',
                ],
                'completed_prompt' => 'Apertura urgente de auto y duplicado de llave codificada.',
            ],
            'cerrajeria-comercial' => [
                'prompts' => [
                    'Cambio de cerradura de persiana metálica de local',
                    'Instalación de cerradura electromagnética comercial',
                    'Mantenimiento de cierrapuertas hidráulico en negocio',
                    'Cambio de combinación de caja fuerte de oficina',
                    'Instalación de barra antipánico en salida de emergencia',
                    'Cerradura de seguridad para puerta de cristal comercial',
                ],
                'completed_prompt' => 'Apertura e instalación de cerradura comercial en local.',
            ],
            'electricidad' => [
                'prompts' => [
                    'Revisión y cambio de térmicas en tablero eléctrico',
                    'Instalación de disyuntor y diagnóstico de fugas',
                    'Recableado de iluminación LED en living y cocina',
                    'Instalación de tomacorrientes e interruptores reforzados',
                    'Reparación de cortocircuito en línea principal',
                    'Colocación de apliques de luz y ventilador de techo',
                ],
                'completed_prompt' => 'Instalación de tablero eléctrico principal y llaves térmicas.',
            ],
            'fotografia' => [
                'prompts' => [
                    'Sesión fotográfica para marca de indumentaria',
                    'Cobertura fotográfica de evento corporativo',
                    'Retratos profesionales en estudio para perfil',
                    'Fotografía de catálogo de productos para e-commerce',
                    'Sesión fotográfica en exteriores para campaña',
                    'Fotografía y edición de imágenes corporativas',
                ],
                'completed_prompt' => 'Sesión fotográfica completa y entrega de fotos editadas.',
            ],
            'contaduria' => [
                'prompts' => [
                    'Asesoramiento contable e inscripción en Monotributo',
                    'Liquidación de impuestos mensuales AFIP e Ingresos Brutos',
                    'Armado y certificación de balances anuales',
                    'Auditoría contable y gestión de moratorias',
                    'Declaración jurada de Ganancias y Bienes Personales',
                    'Liquidación de sueldos y cargas sociales de empleados',
                ],
                'completed_prompt' => 'Certificación contable y balance anual presentado.',
            ],
            'plomeria' => [
                'prompts' => [
                    'Reparación de pérdida de agua y cañería',
                    'Destape de caños de cocina y desagüe principal',
                    'Cambio de grifería y flexibles en baño',
                    'Instalación de termotanque y conexión de agua caliente',
                    'Reparación de filtración en columna de agua',
                    'Instalación de sanitarios y prueba de presión de agua',
                ],
                'completed_prompt' => 'Reparación urgente de pérdida de cañería y cambio de llaves de paso.',
            ],
            'abogacia' => [
                'prompts' => [
                    'Asesoramiento legal para redacción de contrato comercial',
                    'Consulta legal por sucesión y división de bienes',
                    'Patrocinio jurídico en juicio ejecutivo y reclamo',
                    'Redacción y revisión de contrato de alquiler',
                    'Asesoría legal en derecho de familia y divorcios',
                    'Mediación y negociación de acuerdo laboral',
                ],
                'completed_prompt' => 'Asesoramiento y redacción de acuerdo legal homologado.',
            ],
            'diseno' => [
                'prompts' => [
                    'Diseño de identidad visual y logotipo de marca',
                    'Diseño de banners e imágenes para redes sociales',
                    'Maquetación de folletos y catálogo corporativo',
                    'Diseño de sitio web institucional y landing page',
                    'Rediseño de imagen de marca y papelería',
                    'Diseño de packaging e ilustrativo de producto',
                ],
                'completed_prompt' => 'Diseño completo de marca y entrega de manual de identidad.',
            ],
            'limpieza' => [
                'prompts' => [
                    'Limpieza profunda de departamento pos alquiler',
                    'Limpieza y desinfección de tapizados de sillón',
                    'Limpieza de vidrios y ventanales en altura',
                    'Limpieza final de obra en casa particular',
                    'Lavado y desinfección de alfombras residenciales',
                    'Servicio de limpieza periódica para oficinas',
                ],
                'completed_prompt' => 'Limpieza profunda e higienización completa del inmueble.',
            ],
            'climatizacion' => [
                'prompts' => [
                    'Instalación de aire acondicionado Split 3000 frigorías',
                    'Carga de gas refrigerante R410a en equipo de clima',
                    'Mantenimiento preventivo y limpieza de filtros de Split',
                    'Reparación de placa electrónica de aire acondicionado',
                    'Desinstalación y traslado de equipo de aire acondicionado',
                    'Diagnóstico de fuga de gas y reparación de compresor',
                ],
                'completed_prompt' => 'Instalación y puesta en marcha de equipo de aire acondicionado Split.',
            ],
        ];

        $sampleScheduleConfig = [
            ['day' => 15, 'hour' => 9, 'minute' => 30, 'address' => 'Av. Corrientes 1240, CABA', 'status' => WorkStatus::Confirmed, 'price' => 25000.00, 'duration' => 60, 'client_index' => 0],
            ['day' => 15, 'hour' => 14, 'minute' => 0, 'address' => 'Thames 1842, Palermo', 'status' => WorkStatus::InProgress, 'price' => 32000.00, 'duration' => 90, 'client_index' => 1],
            ['day' => 18, 'hour' => 11, 'minute' => 0, 'address' => 'Av. Santa Fe 3400, Recoleta', 'status' => WorkStatus::Confirmed, 'price' => 45000.00, 'duration' => 120, 'client_index' => 2],
            ['day' => 20, 'hour' => 16, 'minute' => 30, 'address' => 'Córdoba 456, Corrientes', 'status' => WorkStatus::Confirmed, 'price' => 18000.00, 'duration' => 45, 'client_index' => 0],
            ['day' => 22, 'hour' => 10, 'minute' => 0, 'address' => 'Pellegrini 1200, Corrientes', 'status' => WorkStatus::Confirmed, 'price' => 28000.00, 'duration' => 75, 'client_index' => 1],
            ['day' => 25, 'hour' => 15, 'minute' => 30, 'address' => 'Parque San Martín, Corrientes', 'status' => WorkStatus::Confirmed, 'price' => 60000.00, 'duration' => 180, 'client_index' => 2],
        ];

        foreach ($providers as $provider) {
            $pivotCat = $provider->categories->first();

            // Defecto 1 — Leemos el category_id real de la tabla intermedia y eliminamos el fallback silencioso
            if (!$pivotCat || !$pivotCat->category_id) {
                if ($this->command) {
                    $this->command->warn("Proveedor ID {$provider->id} no tiene categoría asociada. Omitiendo generación de citas.");
                }
                continue;
            }

            $categoryId = $pivotCat->category_id;
            $categoryModel = CategoryModel::find($categoryId);

            if (!$categoryModel) {
                if ($this->command) {
                    $this->command->warn("Categoría ID {$categoryId} no existe en tabla categories. Omitiendo proveedor ID {$provider->id}.");
                }
                continue;
            }

            $categorySlug = $categoryModel->slug;
            $rubroData = $categorySamplePrompts[$categorySlug] ?? [
                'prompts' => [
                    "Servicio especializado de {$categoryModel->name}",
                    "Atención y soporte técnico de {$categoryModel->name}",
                    "Mantenimiento preventivo de {$categoryModel->name}",
                    "Reparación urgente de {$categoryModel->name}",
                    "Instalación y configuración de {$categoryModel->name}",
                    "Inspección y diagnóstico de {$categoryModel->name}",
                ],
                'completed_prompt' => "Servicio completado de {$categoryModel->name}.",
            ];

            // Defecto 2 — Asignación de prompts de muestra específicos por rubro
            foreach ($sampleScheduleConfig as $index => $app) {
                $client = $clients[$app['client_index'] % $clients->count()];
                $scheduledAt = Carbon::create($year, $month, $app['day'], $app['hour'], $app['minute'], 0);
                $promptText = $rubroData['prompts'][$index % count($rubroData['prompts'])];

                // Defecto 3 — Idempotencia quirúrgica con clave determinística UUID v5
                $srUuid = Uuid::uuid5(Uuid::NAMESPACE_DNS, "lizto.agenda.sr.{$provider->id}.{$index}")->toString();
                $sr = ServiceRequestModel::firstOrCreate(
                    ['uuid' => $srUuid],
                    [
                        'client_id' => $client->id,
                        'category_id' => $categoryId,
                        'raw_prompt' => $promptText,
                        'location_address' => $app['address'],
                        'urgency' => 'scheduled',
                        'preferred_datetime' => $scheduledAt,
                        'status' => 'provider_selected',
                    ]
                );

                $sessionUuid = Uuid::uuid5(Uuid::NAMESPACE_DNS, "lizto.agenda.session.{$provider->id}.{$index}")->toString();
                $session = MatchSessionModel::firstOrCreate(
                    ['uuid' => $sessionUuid],
                    [
                        'service_request_id' => $sr->id,
                        'status' => 'active',
                    ]
                );

                $card = MatchCardModel::firstOrCreate(
                    [
                        'match_session_id' => $session->id,
                        'provider_id' => $provider->id,
                    ],
                    [
                        'rank_position' => 1,
                        'score_total' => 0.95,
                        'card_status' => 'accepted',
                    ]
                );

                $offerUuid = Uuid::uuid5(Uuid::NAMESPACE_DNS, "lizto.agenda.offer.{$provider->id}.{$index}")->toString();
                OfferModel::firstOrCreate(
                    ['uuid' => $offerUuid],
                    [
                        'service_request_id' => $sr->id,
                        'provider_id' => $provider->id,
                        'status' => \App\Domain\Offers\Enums\OfferStatus::Accepted,
                        'proposed_price' => $app['price'],
                        'currency_code' => 'ARS',
                        'estimated_duration_min' => $app['duration'],
                        'proposed_start_at' => $scheduledAt,
                    ]
                );

                $workUuid = Uuid::uuid5(Uuid::NAMESPACE_DNS, "lizto.agenda.work.{$provider->id}.{$index}")->toString();
                $work = WorkModel::firstOrCreate(
                    ['uuid' => $workUuid],
                    [
                        'service_request_id' => $sr->id,
                        'match_card_id' => $card->id,
                        'client_id' => $client->id,
                        'provider_id' => $provider->id,
                        'status' => $app['status'],
                        'scheduled_at' => $scheduledAt,
                        'estimated_duration_min' => $app['duration'],
                        'work_address' => $app['address'],
                        'currency' => 'ARS',
                    ]
                );

                $quoteUuid = Uuid::uuid5(Uuid::NAMESPACE_DNS, "lizto.agenda.quote.{$provider->id}.{$index}")->toString();
                $quote = \App\Infrastructure\Persistence\Eloquent\WorkQuoteModel::firstOrCreate(
                    ['uuid' => $quoteUuid],
                    [
                        'work_id' => $work->id,
                        'provider_id' => $provider->id,
                        'client_id' => $client->id,
                        'amount' => $app['price'],
                        'currency' => 'ARS',
                        'terms_conditions' => 'Presupuesto acordado en matching',
                        'origin' => 'offer_acceptance',
                        'status' => 'accepted',
                        'accepted_at' => $scheduledAt,
                    ]
                );

                if ($work->wasRecentlyCreated || empty($work->agreed_price)) {
                    $work->applyAcceptedQuote($quote);
                }
            }

            // Trabajo completado adicional generado fuera del bucle (con prompt e idempotencia coherente)
            $completedSrUuid = Uuid::uuid5(Uuid::NAMESPACE_DNS, "lizto.agenda.sr_completed.{$provider->id}")->toString();
            $completedSr = ServiceRequestModel::firstOrCreate(
                ['uuid' => $completedSrUuid],
                [
                    'client_id' => $clients[1]->id ?? $clients[0]->id,
                    'category_id' => $categoryId,
                    'raw_prompt' => $rubroData['completed_prompt'],
                    'location_address' => 'Thames 1842, Palermo',
                    'urgency' => 'immediate',
                    'status' => 'completed',
                ]
            );

            $completedWorkUuid = Uuid::uuid5(Uuid::NAMESPACE_DNS, "lizto.agenda.work_completed.{$provider->id}")->toString();
            $completedWork = WorkModel::firstOrCreate(
                ['uuid' => $completedWorkUuid],
                [
                    'service_request_id' => $completedSr->id,
                    'match_card_id' => MatchCardModel::where('provider_id', $provider->id)->first()?->id ?? 1,
                    'client_id' => $clients[1]->id ?? $clients[0]->id,
                    'provider_id' => $provider->id,
                    'status' => WorkStatus::Completed,
                    'scheduled_at' => $now->copy()->subDays(5),
                    'completed_at' => $now->copy()->subDays(5)->addHour(),
                    'estimated_duration_min' => 60,
                    'work_address' => 'Thames 1842, Palermo',
                    'currency' => 'ARS',
                ]
            );

            $completedQuoteUuid = Uuid::uuid5(Uuid::NAMESPACE_DNS, "lizto.agenda.quote_completed.{$provider->id}")->toString();
            $completedQuote = \App\Infrastructure\Persistence\Eloquent\WorkQuoteModel::firstOrCreate(
                ['uuid' => $completedQuoteUuid],
                [
                    'work_id' => $completedWork->id,
                    'provider_id' => $provider->id,
                    'client_id' => $clients[1]->id ?? $clients[0]->id,
                    'amount' => 28000,
                    'currency' => 'ARS',
                    'terms_conditions' => 'Presupuesto acordado en matching',
                    'origin' => 'offer_acceptance',
                    'status' => 'accepted',
                    'accepted_at' => $now->copy()->subDays(5),
                ]
            );

            if ($completedWork->wasRecentlyCreated || empty($completedWork->agreed_price)) {
                $completedWork->applyAcceptedQuote($completedQuote);
            }

            \App\Infrastructure\Persistence\Eloquent\RatingModel::firstOrCreate([
                'work_id' => $completedWork->id,
                'reviewer_id' => $clients[1]->id ?? $clients[0]->id,
            ], [
                'reviewed_id' => $provider->user_id,
                'direction' => 'client_to_provider',
                'score' => 5,
                'comment' => 'Excelente trabajo, solucionó el problema muy rápido y con gran profesionalismo.',
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
