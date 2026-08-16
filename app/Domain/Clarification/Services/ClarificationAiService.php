<?php

namespace App\Domain\Clarification\Services;

use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\QuestionnaireVersionModel;
use App\Infrastructure\Persistence\Eloquent\ServiceTypeModel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClarificationAiService
{
    public function classify(string $prompt): array
    {
        $apiKey = config('services.anthropic.key') ?? env('ANTHROPIC_API_KEY');
        $model = env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001');
        $timeout = (int) env('AI_TIMEOUT', 5);

        if (empty($apiKey)) {
            return $this->fallbackClassify($prompt);
        }

        try {
            $categories = CategoryModel::with('serviceTypes')->get();
            $systemPrompt = "Eres un asistente de clasificación para Lizto. Analiza el prompt y responde ÚNICAMENTE en JSON con los campos:\n"
                . "category_slug (string), service_type_slug (string), confidence (float entre 0 y 1).\n"
                . "Categorías disponibles: " . json_encode($categories->pluck('slug')->toArray());

            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout($timeout)->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => 300,
                'system' => $systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ],
            ]);

            if ($response->successful()) {
                $content = $response->json('content.0.text');
                $json = json_decode($content, true);
                if ($json && isset($json['category_slug'])) {
                    return [
                        'category_slug' => $json['category_slug'],
                        'service_type_slug' => $json['service_type_slug'] ?? null,
                        'confidence' => (float) ($json['confidence'] ?? 0.8),
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('ClarificationAiService classify failed, falling back:', ['error' => $e->getMessage()]);
        }

        return $this->fallbackClassify($prompt);
    }

    public function extract(string $prompt, QuestionnaireVersionModel $version): array
    {
        $apiKey = config('services.anthropic.key') ?? env('ANTHROPIC_API_KEY');
        $model = env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001');
        $timeout = (int) env('AI_TIMEOUT', 5);

        $validQuestions = $version->questions;
        $validKeysMap = $validQuestions->keyBy('question_key');

        if (empty($apiKey) || $validQuestions->isEmpty()) {
            return [];
        }

        try {
            $questionsSchema = $validQuestions->map(fn($q) => [
                'question_key' => $q->question_key,
                'question_text' => $q->question_text,
                'input_type' => $q->input_type,
            ]);

            $systemPrompt = "Extrae respuestas del prompt en formato JSON con la clave 'answers' conteniendo un array de objetos con:\n"
                . "question_key (string), answer_value (mixed), confidence (float entre 0 y 1).\n"
                . "Preguntas válidas: " . json_encode($questionsSchema);

            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout($timeout)->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => 500,
                'system' => $systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ],
            ]);

            if ($response->successful()) {
                $content = $response->json('content.0.text');
                $json = json_decode($content, true);
                $extracted = $json['answers'] ?? [];

                $validExtracted = [];
                foreach ($extracted as $item) {
                    $key = $item['question_key'] ?? null;
                    // BACKEND VALIDATION: Reject any AI answer referencing a question outside questionnaire version
                    if ($key && isset($validKeysMap[$key])) {
                        $validExtracted[] = [
                            'question_id' => $validKeysMap[$key]->id,
                            'question_key' => $key,
                            'answer_value' => $item['answer_value'],
                            'confidence' => (float) ($item['confidence'] ?? 0.8),
                        ];
                    }
                }
                return $validExtracted;
            }
        } catch (\Throwable $e) {
            Log::warning('ClarificationAiService extract failed, falling back:', ['error' => $e->getMessage()]);
        }

        return [];
    }

    private function fallbackClassify(string $prompt): array
    {
        $promptLower = mb_strtolower($prompt);
        
        $keywords = [
            'plomeria' => ['plomero', 'fuga', 'caño', 'agua', 'canilla', 'gotera', 'inodoro', 'destape'],
            'electricidad' => ['electricista', 'luz', 'térmica', 'cable', 'enchufe', 'cables', 'chispas'],
            'cerrajeria' => ['cerrajero', 'llave', 'cerradura', 'puerta', 'candado', 'traba'],
            'fotografia' => ['fotografo', 'fotógrafa', 'foto', 'fotos', 'boda', 'evento', 'retrato'],
            'diseno' => ['diseñador', 'diseño', 'logo', 'branding', 'flyer', 'web', 'ui'],
            'limpieza' => ['limpieza', 'limpiar', 'oficina', 'mudanza', 'profunda'],
            'contaduria' => ['contador', 'contadora', 'monotributo', 'impuestos', 'balance', 'afip'],
            'abogacia' => ['abogado', 'abogada', 'legal', 'juicio', 'despido', 'contrato', 'sucesion'],
        ];

        foreach ($keywords as $catSlug => $words) {
            foreach ($words as $word) {
                if (str_contains($promptLower, $word)) {
                    $cat = CategoryModel::where('slug', $catSlug)->first();
                    $serviceType = ServiceTypeModel::where('category_id', $cat?->id)->first();
                    return [
                        'category_slug' => $catSlug,
                        'service_type_slug' => $serviceType?->slug,
                        'confidence' => 0.85,
                    ];
                }
            }
        }

        return [
            'category_slug' => 'plomeria',
            'service_type_slug' => 'water_leak',
            'confidence' => 0.5,
        ];
    }
}
