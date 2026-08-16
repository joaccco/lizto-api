<?php

namespace Database\Seeders;

use App\Domain\Clarification\Enums\QuestionnaireVersionStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\QuestionConditionModel;
use App\Infrastructure\Persistence\Eloquent\QuestionModel;
use App\Infrastructure\Persistence\Eloquent\QuestionnaireVersionModel;
use App\Infrastructure\Persistence\Eloquent\QuestionOptionModel;
use App\Infrastructure\Persistence\Eloquent\ServiceTypeModel;
use Illuminate\Database\Seeder;

class ClarificationEngineSeeder extends Seeder
{
    public function run(): void
    {
        $categoriesData = [
            'plomeria' => [
                'name' => 'Plomería',
                'service_types' => [
                    ['slug' => 'water_leak', 'name' => 'Pérdida de agua', 'always_eval' => false],
                    ['slug' => 'clog', 'name' => 'Obstrucción / Destape', 'always_eval' => false],
                    ['slug' => 'installation', 'name' => 'Instalación de artefactos', 'always_eval' => false],
                    ['slug' => 'faucet_repair', 'name' => 'Reparación de grifería', 'always_eval' => false],
                    ['slug' => 'pressure_issue', 'name' => 'Problema de presión', 'always_eval' => false],
                    ['slug' => 'other', 'name' => 'Otro servicio de plomería', 'always_eval' => false],
                ],
            ],
            'electricidad' => [
                'name' => 'Electricidad',
                'service_types' => [
                    ['slug' => 'failure', 'name' => 'Falla / Cortocircuito', 'always_eval' => false],
                    ['slug' => 'installation', 'name' => 'Instalación eléctrica', 'always_eval' => false],
                    ['slug' => 'outlet_change', 'name' => 'Cambio de tomacorrientes', 'always_eval' => false],
                    ['slug' => 'lighting', 'name' => 'Iluminación', 'always_eval' => false],
                    ['slug' => 'panel', 'name' => 'Tablero eléctrico', 'always_eval' => false],
                    ['slug' => 'other', 'name' => 'Otro servicio eléctrico', 'always_eval' => false],
                ],
            ],
            'cerrajeria' => [
                'name' => 'Cerrajería',
                'service_types' => [
                    ['slug' => 'opening', 'name' => 'Apertura de urgencia', 'always_eval' => false],
                    ['slug' => 'lock_change', 'name' => 'Cambio de cerradura', 'always_eval' => false],
                    ['slug' => 'repair', 'name' => 'Reparación de cerradura', 'always_eval' => false],
                    ['slug' => 'lost_key', 'name' => 'Llave perdida', 'always_eval' => false],
                    ['slug' => 'broken_key', 'name' => 'Llave rota en cerradura', 'always_eval' => false],
                    ['slug' => 'copy', 'name' => 'Copia de llaves', 'always_eval' => false],
                    ['slug' => 'other', 'name' => 'Otro servicio de cerrajería', 'always_eval' => false],
                ],
            ],
            'fotografia' => [
                'name' => 'Fotografía',
                'service_types' => [
                    ['slug' => 'event', 'name' => 'Cobertura de eventos', 'always_eval' => false],
                    ['slug' => 'product', 'name' => 'Fotografía de producto', 'always_eval' => false],
                    ['slug' => 'portrait', 'name' => 'Retrato / Book', 'always_eval' => false],
                    ['slug' => 'commercial', 'name' => 'Fotografía comercial', 'always_eval' => false],
                    ['slug' => 'real_estate', 'name' => 'Fotografía inmobiliaria', 'always_eval' => false],
                    ['slug' => 'social_content', 'name' => 'Contenido para redes', 'always_eval' => false],
                    ['slug' => 'other', 'name' => 'Otro servicio fotográfico', 'always_eval' => false],
                ],
            ],
            'diseno' => [
                'name' => 'Diseño',
                'service_types' => [
                    ['slug' => 'logo', 'name' => 'Diseño de logo', 'always_eval' => false],
                    ['slug' => 'branding', 'name' => 'Identidad de marca', 'always_eval' => false],
                    ['slug' => 'social_media', 'name' => 'Diseño para redes', 'always_eval' => false],
                    ['slug' => 'flyer', 'name' => 'Folleto / Flyer', 'always_eval' => false],
                    ['slug' => 'packaging', 'name' => 'Packaging', 'always_eval' => false],
                    ['slug' => 'ui_ux', 'name' => 'Diseño UI/UX', 'always_eval' => false],
                    ['slug' => 'web_design', 'name' => 'Diseño Web', 'always_eval' => false],
                    ['slug' => 'other', 'name' => 'Otro servicio de diseño', 'always_eval' => false],
                ],
            ],
            'limpieza' => [
                'name' => 'Limpieza',
                'service_types' => [
                    ['slug' => 'home', 'name' => 'Limpieza de hogar', 'always_eval' => false],
                    ['slug' => 'office', 'name' => 'Limpieza de oficina', 'always_eval' => false],
                    ['slug' => 'deep_clean', 'name' => 'Limpieza profunda', 'always_eval' => false],
                    ['slug' => 'post_construction', 'name' => 'Post obra', 'always_eval' => false],
                    ['slug' => 'moving', 'name' => 'Mudanza', 'always_eval' => false],
                    ['slug' => 'recurring', 'name' => 'Mantenimiento recurrente', 'always_eval' => false],
                    ['slug' => 'other', 'name' => 'Otro servicio de limpieza', 'always_eval' => false],
                ],
            ],
            'contaduria' => [
                'name' => 'Contaduría',
                'service_types' => [
                    ['slug' => 'monotributo', 'name' => 'Gestión Monotributo', 'always_eval' => false],
                    ['slug' => 'taxes', 'name' => 'Liquidación de impuestos', 'always_eval' => false],
                    ['slug' => 'invoicing', 'name' => 'Facturación y AFIP', 'always_eval' => false],
                    ['slug' => 'payroll_liquidation', 'name' => 'Liquidación de sueldos', 'always_eval' => false],
                    ['slug' => 'business_accounting', 'name' => 'Contabilidad PyME', 'always_eval' => false],
                    ['slug' => 'payroll', 'name' => 'Nómina', 'always_eval' => false],
                    ['slug' => 'registration', 'name' => 'Alta de sociedad / autónomo', 'always_eval' => false],
                    ['slug' => 'consulting', 'name' => 'Consultoría contable', 'always_eval' => false],
                    ['slug' => 'other', 'name' => 'Otro servicio contable', 'always_eval' => false],
                ],
            ],
            'abogacia' => [
                'name' => 'Abogacía',
                'service_types' => [
                    ['slug' => 'family', 'name' => 'Derecho de familia', 'always_eval' => true],
                    ['slug' => 'labor', 'name' => 'Derecho laboral', 'always_eval' => true],
                    ['slug' => 'civil', 'name' => 'Derecho civil / sucesiones', 'always_eval' => true],
                    ['slug' => 'commercial', 'name' => 'Derecho comercial', 'always_eval' => true],
                    ['slug' => 'contracts', 'name' => 'Revisión / Redacción de contratos', 'always_eval' => true],
                    ['slug' => 'consumer', 'name' => 'Defensa del consumidor', 'always_eval' => true],
                    ['slug' => 'criminal', 'name' => 'Derecho penal', 'always_eval' => true],
                    ['slug' => 'general_consultation', 'name' => 'Consulta legal general', 'always_eval' => true],
                    ['slug' => 'other', 'name' => 'Otro servicio jurídico', 'always_eval' => true],
                ],
            ],
        ];

        foreach ($categoriesData as $catSlug => $cData) {
            $category = CategoryModel::firstOrCreate(
                ['slug' => $catSlug],
                ['name' => $cData['name']]
            );

            foreach ($cData['service_types'] as $stData) {
                $serviceType = ServiceTypeModel::firstOrCreate(
                    ['category_id' => $category->id, 'slug' => $stData['slug']],
                    [
                        'name' => $stData['name'],
                        'always_requires_evaluation' => $stData['always_eval'],
                    ]
                );

                // Create questionnaire version v1
                $version = QuestionnaireVersionModel::firstOrCreate(
                    [
                        'service_type_id' => $serviceType->id,
                        'version_number' => 1,
                    ],
                    [
                        'status' => QuestionnaireVersionStatus::Published,
                        'published_at' => now(),
                    ]
                );

                $serviceType->update(['current_version_id' => $version->id]);

                $this->seedQuestionsForType($version, $catSlug, $stData['slug']);
            }
        }
    }

    private function seedQuestionsForType(QuestionnaireVersionModel $version, string $catSlug, string $typeSlug): void
    {
        if ($catSlug === 'plomeria' && $typeSlug === 'water_leak') {
            $q1 = QuestionModel::create([
                'questionnaire_version_id' => $version->id,
                'question_key' => 'location',
                'question_text' => '¿Dónde se encuentra la pérdida?',
                'input_type' => 'single_select',
                'is_required' => true,
                'position' => 1,
                'used_for_matching' => true,
                'used_for_quote' => true,
            ]);
            QuestionOptionModel::create(['question_id' => $q1->id, 'label' => 'Cocina', 'value' => 'cocina', 'position' => 1]);
            QuestionOptionModel::create(['question_id' => $q1->id, 'label' => 'Baño', 'value' => 'bano', 'position' => 2]);
            QuestionOptionModel::create(['question_id' => $q1->id, 'label' => 'Lavadero', 'value' => 'lavadero', 'position' => 3]);
            QuestionOptionModel::create(['question_id' => $q1->id, 'label' => 'Tanque / Cañería principal', 'value' => 'tanque', 'position' => 4]);

            $q2 = QuestionModel::create([
                'questionnaire_version_id' => $version->id,
                'question_key' => 'active_now',
                'question_text' => '¿La pérdida está activa ahora?',
                'input_type' => 'boolean',
                'is_required' => true,
                'position' => 2,
                'used_for_matching' => true,
                'used_for_quote' => true,
            ]);

            $q3 = QuestionModel::create([
                'questionnaire_version_id' => $version->id,
                'question_key' => 'can_shut_valve',
                'question_text' => '¿Podés cerrar la llave de paso?',
                'input_type' => 'boolean',
                'is_required' => false,
                'position' => 3,
                'used_for_quote' => true,
            ]);
            QuestionConditionModel::create([
                'question_id' => $q3->id,
                'depends_on_question_id' => $q2->id,
                'depends_on_question_key' => 'active_now',
                'operator' => 'equals',
                'expected_value' => true,
            ]);

            $q4 = QuestionModel::create([
                'questionnaire_version_id' => $version->id,
                'question_key' => 'severity',
                'question_text' => '¿Cuál es la severidad de la pérdida?',
                'input_type' => 'single_select',
                'is_required' => true,
                'position' => 4,
                'used_for_quote' => true,
            ]);
            QuestionOptionModel::create(['question_id' => $q4->id, 'label' => 'Goteo leve', 'value' => 'goteo', 'position' => 1]);
            QuestionOptionModel::create(['question_id' => $q4->id, 'label' => 'Flujo pequeño constante', 'value' => 'flujo', 'position' => 2]);
            QuestionOptionModel::create(['question_id' => $q4->id, 'label' => 'Mucha agua / Inundando', 'value' => 'inundacion', 'position' => 3]);
        } else {
            // Default generic seed questions per service type
            $q1 = QuestionModel::create([
                'questionnaire_version_id' => $version->id,
                'question_key' => 'service_detail',
                'question_text' => '¿Qué detalle necesitas para este trabajo?',
                'input_type' => 'short_text',
                'is_required' => true,
                'position' => 1,
                'used_for_matching' => true,
                'used_for_quote' => true,
            ]);

            $q2 = QuestionModel::create([
                'questionnaire_version_id' => $version->id,
                'question_key' => 'urgency_level',
                'question_text' => '¿Cuándo necesitas que se realice?',
                'input_type' => 'single_select',
                'is_required' => true,
                'position' => 2,
                'used_for_matching' => true,
                'used_for_quote' => true,
            ]);
            QuestionOptionModel::create(['question_id' => $q2->id, 'label' => 'Lo antes posible', 'value' => 'urgente', 'position' => 1]);
            QuestionOptionModel::create(['question_id' => $q2->id, 'label' => 'Esta semana', 'value' => 'esta_semana', 'position' => 2]);
            QuestionOptionModel::create(['question_id' => $q2->id, 'label' => 'A coordinar', 'value' => 'coordinar', 'position' => 3]);
        }
    }
}
