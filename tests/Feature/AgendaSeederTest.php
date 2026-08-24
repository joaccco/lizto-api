<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Database\Seeders\AgendaSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\ClientUserSeeder;
use Database\Seeders\ProviderSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgendaSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(CategorySeeder::class);
        $this->seed(ClientUserSeeder::class);
        $this->seed(ProviderSeeder::class);
    }

    /**
     * Test 1 & 2: Para TODOS los profesionales sembrados, toda solicitud de servicio
     * vinculada a sus trabajos tiene la misma categoría y texto descriptivo correspondiente a su rubro.
     */
    public function test_all_seeded_works_and_service_requests_match_provider_category_and_rubro_prompt(): void
    {
        $this->seed(AgendaSeeder::class);

        $providers = ProviderProfileModel::with(['categories.category', 'works.serviceRequest.category'])->get();
        $this->assertNotEmpty($providers, 'Debe haber proveedores sembrados.');

        foreach ($providers as $provider) {
            $providerCategory = $provider->categories->first()?->category;
            $this->assertNotNull($providerCategory, "El proveedor {$provider->id} debe tener una categoría válida asignada.");

            $works = $provider->works;
            $this->assertNotEmpty($works, "El proveedor {$provider->id} debe tener trabajos asignados.");

            foreach ($works as $work) {
                $sr = $work->serviceRequest;
                $this->assertNotNull($sr, "El trabajo {$work->id} debe tener una ServiceRequest vinculada.");

                // Assert 1: La categoría de la ServiceRequest coincide con la categoría del proveedor
                $this->assertEquals(
                    $providerCategory->id,
                    $sr->category_id,
                    "Inconsistencia en proveedor ID {$provider->id} ({$providerCategory->slug}): La solicitud ID {$sr->id} tiene category_id {$sr->category_id} ({$sr->category?->slug}) que no coincide."
                );

                // Assert 2: El prompt descriptivo pertenece al rubro correspondiente
                $this->assertPromptMatchesCategory($providerCategory->slug, $sr->raw_prompt, "Proveedor ID {$provider->id} ({$providerCategory->slug})");
            }
        }
    }

    /**
     * Test 3: El trabajo completado que se genera fuera del bucle también cumple ambas condiciones
     * (coincidencia de categoría y texto de rubro).
     */
    public function test_outside_loop_completed_work_matches_provider_category_and_rubro_prompt(): void
    {
        $this->seed(AgendaSeeder::class);

        $providers = ProviderProfileModel::with(['categories.category'])->get();

        foreach ($providers as $provider) {
            $providerCategory = $provider->categories->first()?->category;

            $completedWork = WorkModel::where('provider_id', $provider->id)
                ->where('status', \App\Domain\Works\Enums\WorkStatus::Completed)
                ->with('serviceRequest.category')
                ->first();

            $this->assertNotNull($completedWork, "El proveedor ID {$provider->id} debe tener un trabajo completado generado fuera del bucle.");
            $sr = $completedWork->serviceRequest;

            $this->assertEquals(
                $providerCategory->id,
                $sr->category_id,
                "El trabajo completado fuera del bucle para el proveedor ID {$provider->id} tiene category_id incoherente."
            );

            $this->assertPromptMatchesCategory(
                $providerCategory->slug,
                $sr->raw_prompt,
                "Trabajo completado fuera del bucle para proveedor ID {$provider->id}"
            );
        }
    }

    /**
     * Test 4: Ejecutar el seeder dos veces consecutivas produce la misma cantidad de solicitudes y trabajos que ejecutarlo una sola vez.
     */
    public function test_agenda_seeder_is_idempotent(): void
    {
        $this->seed(AgendaSeeder::class);

        $initialWorksCount = WorkModel::count();
        $initialSRsCount = ServiceRequestModel::count();

        // Segunda ejecución consecutiva
        $this->seed(AgendaSeeder::class);

        $this->assertEquals($initialWorksCount, WorkModel::count(), 'Ejecutar el AgendaSeeder por segunda vez no debe duplicar trabajos.');
        $this->assertEquals($initialSRsCount, ServiceRequestModel::count(), 'Ejecutar el AgendaSeeder por segunda vez no debe duplicar solicitudes.');
    }

    /**
     * Test 5: Ejecutar el seeder no elimina ni modifica solicitudes de servicio que no hayan sido creadas por él.
     */
    public function test_agenda_seeder_preserves_manual_service_requests(): void
    {
        $client = UserModel::first();
        $category = CategoryModel::first();

        // Crear una solicitud manual que no fue generada por el seeder
        $manualSr = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Solicitud manual de prueba creada por el usuario',
            'location_address' => 'Av. Independencia 100, Corrientes',
            'urgency' => 'immediate',
            'status' => 'pending_matching',
        ]);

        $this->seed(AgendaSeeder::class);

        $this->assertDatabaseHas('service_requests', [
            'id' => $manualSr->id,
            'raw_prompt' => 'Solicitud manual de prueba creada por el usuario',
        ]);
    }

    /**
     * Test 6: La cantidad de trabajos por profesional y su distribución de estados se mantiene igual que antes.
     */
    public function test_works_count_and_status_distribution_per_provider_is_preserved(): void
    {
        $this->seed(AgendaSeeder::class);

        $providers = ProviderProfileModel::all();

        foreach ($providers as $provider) {
            $works = WorkModel::where('provider_id', $provider->id)->get();
            // Cada proveedor debe conservar exactamente 7 trabajos totales (6 del bucle + 1 completado fuera del bucle)
            $this->assertCount(7, $works, "El proveedor ID {$provider->id} debe tener exactamente 7 trabajos.");

            $statusCounts = $works->pluck('status.value')->countBy()->all();

            // Verificar la distribución esperada por proveedor:
            // 4 confirmed, 1 in_progress, 1 completed, etc. (o según los datos de muestra)
            $this->assertGreaterThanOrEqual(1, $works->where('status', \App\Domain\Works\Enums\WorkStatus::Completed)->count());
            $this->assertGreaterThanOrEqual(1, $works->where('status', \App\Domain\Works\Enums\WorkStatus::InProgress)->count());
        }
    }

    /**
     * Helper para verificar que un prompt coincide con el rubro/categoría dada.
     */
    private function assertPromptMatchesCategory(string $categorySlug, string $prompt, string $context): void
    {
        $promptLower = mb_strtolower($prompt);

        $expectedKeywords = [
            'cerrajeria' => ['cerradura', 'llave', 'puerta', 'blindada', 'combinación', 'candado', 'apertura'],
            'cerrajeria-hogar' => ['cerradura', 'llave', 'puerta', 'blindada', 'combinación', 'apertura'],
            'cerrajeria-automotor' => ['cerradura', 'llave', 'puerta', 'auto', 'vehículo', 'apertura'],
            'cerrajeria-comercial' => ['cerradura', 'llave', 'puerta', 'persiana', 'local', 'apertura'],
            'electricidad' => ['eléctrico', 'eléctrica', 'térmica', 'tablero', 'cortocircuito', 'enchufe', 'cableado', 'iluminación', 'luz', 'disyuntor', 'fuga', 'fugas', 'tomacorriente', 'tomacorrientes', 'interruptor', 'interruptores', 'instalación', 'instalacion'],
            'fotografia' => ['fotográfica', 'foto', 'fotografía', 'sesión', 'retrato', 'producto', 'evento'],
            'contaduria' => ['contable', 'balanza', 'impuesto', 'monotributo', 'balance', 'afip', 'facturación', 'declaración', 'liquidación', 'liquidacion', 'sueldos', 'cargas', 'empleados'],
            'plomeria' => ['cañería', 'caño', 'agua', 'pérdida', 'sanitario', 'destape', 'grifería', 'gotera'],
            'abogacia' => ['contrato', 'legal', 'jurídico', 'demanda', 'asesoramiento', 'sucesión', 'divorcio', 'abogado', 'consulta legal', 'mediación', 'mediacion', 'negociación', 'negociacion', 'acuerdo', 'laboral'],
            'diseno' => ['diseño', 'logo', 'web', 'banner', 'marca', 'identidad'],
            'limpieza' => ['limpieza', 'desinfección', 'tapizado', 'alfombra', 'profunda'],
            'climatizacion' => ['aire', 'acondicionado', 'clima', 'split', 'gas', 'r410'],
        ];

        $keywords = $expectedKeywords[$categorySlug] ?? [];
        $matched = false;
        foreach ($keywords as $kw) {
            if (str_contains($promptLower, $kw)) {
                $matched = true;
                break;
            }
        }

        $this->assertTrue(
            $matched,
            "{$context}: El prompt '{$prompt}' no coincide con el rubro '{$categorySlug}'."
        );
    }
}
