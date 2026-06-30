<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PlatformApiTest extends TestCase
{
    use RefreshDatabase;

    protected ?string $storageRoot = null;

    protected function setUp(): void
    {
        if (getenv('TEST_DB_AVAILABLE') !== '1') {
            $this->markTestSkipped('MySQL no esta accesible para PHP en este entorno; se omiten las pruebas API dependientes de base de datos.');
        }

        parent::setUp();

        $this->storageRoot = storage_path('app/tema_litoclean');
        File::deleteDirectory($this->storageRoot);
    }

    protected function tearDown(): void
    {
        if ($this->storageRoot !== null) {
            File::deleteDirectory($this->storageRoot);
        }

        parent::tearDown();
    }

    public function test_health_config_and_capabilities_endpoints_are_available(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'company' => 'Tema Litoclean',
            ]);

        $config = $this->getJson('/api/config')
            ->assertOk()
            ->json();

        $this->assertSame('Tema Litoclean', $config['company_name']);
        $this->assertNotEmpty($config['items']);

        $capabilities = $this->getJson('/api/capabilities')
            ->assertOk()
            ->json();

        $this->assertCount(9, $capabilities);
        $this->assertSame('pdf_batch', $capabilities[0]['tool']);
        $this->assertContains('excel_consolidation', array_column($capabilities, 'tool'));
    }

    public function test_login_and_two_factor_flow_uses_seeded_admin_users(): void
    {
        $login = $this->postJson('/api/auth/login', [
            'email' => 'admin@tema.com.pe',
            'password' => 'Tema1234',
        ])->assertOk();

        $login->assertJson([
            'success' => true,
            'is_admin' => true,
            'requires_two_factor' => true,
            'user_name' => 'Administrador Tema',
        ]);

        $challengeId = $login->json('challenge_id');
        $this->assertNotEmpty($challengeId);

        $this->postJson('/api/auth/verify-2fa', [
            'challenge_id' => $challengeId,
            'code' => '246810',
        ])->assertOk()->assertJson([
            'success' => true,
            'requires_two_factor' => false,
            'is_admin' => true,
            'user_name' => 'Administrador Tema',
        ]);
    }

    public function test_admin_users_and_settings_can_be_managed(): void
    {
        $users = $this->getJson('/api/admin/users')
            ->assertOk()
            ->json();

        $this->assertCount(3, $users);

        $created = $this->postJson('/api/admin/users', [
            'name' => 'Analista QA',
            'email' => 'qa@tema.com.pe',
            'role' => 'analista',
            'active' => true,
            'two_factor_enabled' => true,
        ])->assertOk();

        $created->assertJson([
            'email' => 'qa@tema.com.pe',
            'role' => 'analista',
            'active' => true,
            'two_factor_enabled' => true,
        ]);

        $userId = $created->json('id');

        $this->patchJson("/api/admin/users/{$userId}", [
            'name' => 'Auditor QA',
            'email' => 'auditor.qa@tema.com.pe',
            'active' => false,
            'two_factor_enabled' => false,
            'role' => 'auditoria',
        ])->assertOk()->assertJson([
            'id' => $userId,
            'name' => 'Auditor QA',
            'email' => 'auditor.qa@tema.com.pe',
            'active' => false,
            'two_factor_enabled' => false,
            'role' => 'auditoria',
        ]);

        $this->deleteJson("/api/admin/users/{$userId}")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->patchJson('/api/admin/users/1f2b31ec-a437-4f4c-aaaf-f7f8f40f7fff', [
            'name' => 'Sin efecto',
            'email' => 'sin-efecto@tema.com.pe',
            'role' => 'admin',
            'active' => false,
            'two_factor_enabled' => false,
        ])->assertNotFound();

        $admins = \App\Models\AdminUser::query()->where('role', 'admin')->get();
        $this->assertGreaterThanOrEqual(1, $admins->count());
        $primaryAdmin = $admins->first();
        $secondaryAdmin = $admins->skip(1)->first();

        if ($secondaryAdmin) {
            $this->patchJson("/api/admin/users/{$secondaryAdmin->id}", [
                'name' => $secondaryAdmin->name,
                'email' => $secondaryAdmin->email,
                'role' => 'auditoria',
                'active' => false,
                'two_factor_enabled' => false,
            ])->assertOk();
        }

        $this->patchJson("/api/admin/users/{$primaryAdmin->id}", [
            'name' => $primaryAdmin->name,
            'email' => $primaryAdmin->email,
            'role' => 'operaciones',
            'active' => false,
            'two_factor_enabled' => false,
        ])->assertStatus(422);

        $settings = $this->getJson('/api/admin/app-settings')
            ->assertOk()
            ->json();

        $this->assertSame('Tema Litoclean', $settings['company_name']);

        $updated = $this->patchJson('/api/admin/app-settings', [
            'company_name' => 'Tema Litoclean QA',
            'allowed_domains' => ['tema.com.pe', 'tema.es', 'temalitoclean.com'],
            'allowed_origins' => ['https://gm.temalitoclean.com'],
            'corporate_endpoints' => [
                [
                    'id' => 'erp-operaciones',
                    'name' => 'ERP Operaciones',
                    'base_url' => 'https://erp.tema.test',
                    'auth_method' => 'oauth2',
                    'owner' => 'Operaciones',
                    'pii_scope' => 'Medio',
                    'enabled' => true,
                ],
            ],
        ])->assertOk();

        $updated->assertJson([
            'company_name' => 'Tema Litoclean QA',
        ]);
        $this->assertSame(['tema.com.pe', 'tema.es', 'temalitoclean.com'], $updated->json('allowed_domains'));
        $this->assertSame(['https://gm.temalitoclean.com'], $updated->json('allowed_origins'));
        $this->assertCount(1, $updated->json('corporate_endpoints'));
    }

    public function test_files_can_be_uploaded_and_downloaded(): void
    {
        $file = UploadedFile::fake()->createWithContent('nota.txt', 'contenido de prueba');

        $upload = $this->post('/api/files/upload', [
            'files' => [$file],
        ])->assertOk();

        $artifacts = $upload->json();
        $this->assertCount(1, $artifacts);
        $artifactId = $artifacts[0]['id'];

        $this->get("/api/files/{$artifactId}")
            ->assertOk()
            ->assertHeader('content-disposition');
    }
}
