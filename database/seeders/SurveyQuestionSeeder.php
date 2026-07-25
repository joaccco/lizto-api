<?php

namespace Database\Seeders;

use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\SurveyQuestionModel;
use Illuminate\Database\Seeder;

class SurveyQuestionSeeder extends Seeder
{
    public function run(): void
    {
        $category = CategoryModel::where('slug', 'cerrajeria')->firstOrFail();
        $questions = [
            ['question_key' => 'property_type', 'question_text' => '¿Es para una casa, departamento, comercio o vehículo?', 'input_type' => 'single_select', 'options' => [['value' => 'casa', 'label' => 'Casa'], ['value' => 'departamento', 'label' => 'Departamento'], ['value' => 'comercio', 'label' => 'Comercio'], ['value' => 'vehiculo', 'label' => 'Vehículo']], 'is_required' => true, 'sort_order' => 1],
            ['question_key' => 'service_type', 'question_text' => '¿Qué necesitás exactamente?', 'input_type' => 'single_select', 'options' => [['value' => 'apertura', 'label' => 'Apertura de puerta'], ['value' => 'reemplazo', 'label' => 'Reemplazo de cerradura'], ['value' => 'reparacion', 'label' => 'Reparación'], ['value' => 'duplicado', 'label' => 'Duplicado de llave']], 'is_required' => true, 'sort_order' => 2],
            ['question_key' => 'has_spare_key', 'question_text' => '¿Tenés una llave de repuesto disponible?', 'input_type' => 'boolean', 'options' => null, 'is_required' => false, 'sort_order' => 3, 'condition' => ['if' => 'service_type', 'equals' => 'apertura']],
            ['question_key' => 'has_new_lock', 'question_text' => '¿Ya tenés la cerradura nueva?', 'input_type' => 'boolean', 'options' => null, 'is_required' => false, 'sort_order' => 4, 'condition' => ['if' => 'service_type', 'equals' => 'reemplazo']],
            ['question_key' => 'photo', 'question_text' => 'Si podés, subí una foto de la cerradura (opcional)', 'input_type' => 'photo', 'options' => null, 'is_required' => false, 'sort_order' => 5],
        ];

        foreach ($questions as $question) {
            SurveyQuestionModel::updateOrCreate(
                ['category_id' => $category->id, 'question_key' => $question['question_key']],
                ['category_id' => $category->id, ...$question]
            );
        }
    }
}