<?php

namespace App\Application\AI\Actions;

use App\Application\AI\DTOs\ParsedIntentDTO;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\SurveyQuestionModel;

class ParseIntentAction
{
    private array $categoryKeywords = [
        'cerrajeria' => ['cerrajero', 'cerradura', 'llave', 'candado', 'apertura', 'afuera'],
        'electricidad' => ['electricista', 'luz', 'cable', 'enchufe', 'cortocircuito', 'tablero'],
        'plomeria' => ['plomero', 'caño', 'cano', 'pérdida', 'perdida', 'agua', 'canilla'],
        'fotografia' => ['fotógrafo', 'fotografo', 'fotografía', 'fotografia', 'sesión', 'sesion', 'fotos'],
        'abogacia' => ['abogado', 'legal', 'contrato', 'juicio'],
        'contaduria' => ['contador', 'impuestos', 'factura', 'contabilidad'],
        'diseno' => ['diseñador', 'disenador', 'diseño', 'diseno', 'logo', 'branding'],
        'limpieza' => ['limpieza', 'mucama', 'ordenar'],
    ];

    private array $urgencyKeywords = [
        'immediate' => ['urgente', 'ahora', 'ya', 'inmediato', 'emergencia'],
        'today' => ['hoy', 'esta tarde', 'esta noche'],
        'scheduled' => ['mañana', 'manana', 'próximo', 'proximo', 'semana'],
    ];

    private array $complexityKeywords = [
        'simple' => ['apertura', 'duplicado', 'cambio de llave'],
        'medium' => ['instalación', 'instalacion', 'reparación', 'reparacion', 'revisar'],
        'complex' => ['sistema de seguridad', 'múltiples cerraduras', 'multiples cerraduras'],
    ];

    private array $remoteKeywords = ['online', 'virtual', 'remoto'];

    public function execute(string $prompt, ?string $urgency = null, ?array $location = null): ParsedIntentDTO
    {
        $normalizedPrompt = mb_strtolower($prompt);
        $detectedKeywords = [];

        // 1. Detección de Categoría
        $detectedCategorySlug = null;
        foreach ($this->categoryKeywords as $slug => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($normalizedPrompt, mb_strtolower($kw))) {
                    $detectedCategorySlug = $slug;
                    $detectedKeywords[] = $kw;
                    break 2;
                }
            }
        }

        $categoryModel = null;
        if ($detectedCategorySlug) {
            $categoryModel = CategoryModel::where('slug', $detectedCategorySlug)->first();
        }

        // 2. Detección de Urgencia
        $detectedUrgency = $urgency;
        if (!$detectedUrgency) {
            foreach ($this->urgencyKeywords as $urgencyType => $keywords) {
                foreach ($keywords as $kw) {
                    if (str_contains($normalizedPrompt, mb_strtolower($kw))) {
                        $detectedUrgency = $urgencyType;
                        if (!in_array($kw, $detectedKeywords)) {
                            $detectedKeywords[] = $kw;
                        }
                        break 2;
                    }
                }
            }
            if (!$detectedUrgency) {
                $detectedUrgency = 'flexible';
            }
        }

        // 3. Detección de Complejidad
        $estimatedComplexity = 'unknown';
        foreach ($this->complexityKeywords as $complexity => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($normalizedPrompt, mb_strtolower($kw))) {
                    $estimatedComplexity = $complexity;
                    break 2;
                }
            }
        }
        if ($estimatedComplexity === 'unknown' && $categoryModel !== null) {
            $estimatedComplexity = 'simple';
        }

        // 4. Remote vs Presencial
        $isRemote = false;
        foreach ($this->remoteKeywords as $kw) {
            if (str_contains($normalizedPrompt, mb_strtolower($kw))) {
                $isRemote = true;
                if (!in_array($kw, $detectedKeywords)) {
                    $detectedKeywords[] = $kw;
                }
                break;
            }
        }
        $requiresPresence = !$isRemote;

        // 5. Modo UX
        $mode = 'browse';
        if ($detectedUrgency === 'immediate') {
            $mode = 'fast';
        } elseif ($detectedCategorySlug === 'abogacia' || $detectedCategorySlug === 'contaduria') {
            $mode = 'professional';
        } elseif (in_array($detectedUrgency, ['today', 'scheduled', 'flexible'])) {
            $mode = 'browse';
        }

        // 6. Confianza y Ambigüedad
        $hasCategory = $categoryModel !== null;
        $hasUrgencyKeyword = $detectedUrgency !== 'flexible';

        if ($hasCategory && $hasUrgencyKeyword) {
            $confidence = 0.90;
            $ambiguityLevel = 'low';
        } elseif ($hasCategory) {
            $confidence = 0.70;
            $ambiguityLevel = 'low';
        } elseif ($hasUrgencyKeyword) {
            $confidence = 0.60;
            $ambiguityLevel = 'high';
        } else {
            $confidence = 0.40;
            $ambiguityLevel = 'high';
        }

        // 7. Preguntas Sugeridas
        $suggestedQuestions = [];
        if ($hasCategory) {
            $questions = SurveyQuestionModel::where('category_id', $categoryModel->id)
                ->orderBy('sort_order')
                ->get();

            foreach ($questions as $q) {
                // Si la pregunta tiene una condición, no incluirla en la evaluación inicial
                if (!empty($q->condition)) {
                    continue;
                }

                $suggestedQuestions[] = [
                    'key' => $q->question_key,
                    'text' => $q->question_text,
                    'input_type' => $q->input_type,
                    'options' => $q->options,
                    'is_required' => $q->is_required,
                ];
            }

            if ($ambiguityLevel === 'high' && count($suggestedQuestions) > 2) {
                $suggestedQuestions = array_slice($suggestedQuestions, 0, 2);
            }
        } else {
            $suggestedQuestions = [
                [
                    'key' => 'category_selection',
                    'text' => '¿Qué tipo de servicio estás buscando?',
                    'input_type' => 'single_select',
                    'options' => [
                        ['value' => 'cerrajeria', 'label' => 'Cerrajería'],
                        ['value' => 'electricidad', 'label' => 'Electricidad'],
                        ['value' => 'plomeria', 'label' => 'Plomería'],
                        ['value' => 'fotografia', 'label' => 'Fotografía'],
                        ['value' => 'abogacia', 'label' => 'Abogacía'],
                        ['value' => 'contaduria', 'label' => 'Contaduría'],
                        ['value' => 'diseno', 'label' => 'Diseño'],
                        ['value' => 'limpieza', 'label' => 'Limpieza'],
                    ],
                    'is_required' => true,
                ]
            ];
        }

        $parsedIntent = [
            'raw_intent' => trim($prompt),
            'category_slug' => $categoryModel?->slug,
            'category_id' => $categoryModel?->id,
            'urgency' => $detectedUrgency,
            'is_remote' => $isRemote,
            'requires_presence' => $requiresPresence,
            'estimated_complexity' => $estimatedComplexity,
            'ambiguity_level' => $ambiguityLevel,
            'clarification_needed' => [],
            'confidence' => $confidence,
            'detected_keywords' => array_values(array_unique($detectedKeywords)),
        ];

        return new ParsedIntentDTO(
            parsedIntent: $parsedIntent,
            suggestedQuestions: $suggestedQuestions,
            mode: $mode
        );
    }
}
