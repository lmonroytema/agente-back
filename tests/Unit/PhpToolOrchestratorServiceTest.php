<?php

namespace Tests\Unit;

use App\Models\AppSetting;
use App\Services\AppSettingsService;
use App\Services\FileArtifactService;
use App\Services\PhpToolOrchestratorService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PhpToolOrchestratorServiceTest extends TestCase
{
    protected string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tema_litoclean_tests_'.bin2hex(random_bytes(6));
        File::deleteDirectory($this->storageRoot);
        File::ensureDirectoryExists($this->storageRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storageRoot);

        parent::tearDown();
    }

    public function test_capabilities_catalog_covers_all_announced_modules(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $capabilities = $orchestrator->capabilities();

        $this->assertCount(9, $capabilities);
        $this->assertSame(
            ['pdf_batch', 'corporate_data', 'forms', 'office', 'media_gen', 'excel_consolidation', 'env_monitoring_compare', 'report_generator', 'solution_evolution'],
            array_column($capabilities, 'tool')
        );
    }

    public function test_recipes_catalog_lists_premium_predefined_programs(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $recipes = $orchestrator->recipes();

        $this->assertCount(3, $recipes);
        $this->assertSame(
            ['excel_consolidation', 'env_monitoring_compare', 'report_generator'],
            array_column($recipes, 'tool')
        );
    }

    public function test_pdf_operations_work_end_to_end_without_database(): void
    {
        $context = $this->makeRuntime();

        $pdfIds = array_map(
            fn (array $artifact) => $artifact['id'],
            $context['files']->saveUploads([
                $this->makePdfUpload('lote_a.pdf', ['Pagina 1 A', 'Pagina 2 A']),
                $this->makePdfUpload('lote_b.pdf', ['Pagina 1 B']),
            ])
        );

        $merge = $context['orchestrator']->chat('Combina los PDFs cargados en un solo archivo', $pdfIds);
        $this->assertSame('pdf_batch', $merge['tool_name']);
        $this->assertSame('merge', $merge['data']['operation']);
        $this->assertCount(1, $merge['artifacts']);

        $splitIds = array_map(
            fn (array $artifact) => $artifact['id'],
            $context['files']->saveUploads([
                $this->makePdfUpload('expediente.pdf', ['Uno', 'Dos', 'Tres', 'Cuatro']),
            ])
        );

        $split = $context['orchestrator']->chat('Parte el PDF cargado en paginas individuales', $splitIds);
        $this->assertSame('split_pages', $split['data']['operation']);
        $this->assertCount(4, $split['artifacts']);

        $sections = $context['orchestrator']->chat('Divide el PDF cargado en secciones 1-2, 3-4', $splitIds);
        $this->assertSame('split_sections', $sections['data']['operation']);
        $this->assertCount(2, $sections['artifacts']);

        $range = $context['orchestrator']->chat('Extrae las paginas 2 a 3 del PDF cargado', $splitIds);
        $this->assertSame('extract_pages', $range['data']['operation']);

        $text = $context['orchestrator']->chat('Extrae texto del PDF cargado', $splitIds);
        $this->assertSame('extract_text', $text['data']['operation']);
        $this->assertStringEndsWith('.txt', $text['artifacts'][0]['name']);

        $model = $context['orchestrator']->chat('Lee el PDF cargado, extraelo a Markdown y responde: resume hallazgos', $splitIds);
        $this->assertSame('extract_for_model', $model['data']['operation']);
        $this->assertSame('markdown_ready', $model['data']['model_mode']);
        $this->assertContains('pdf_para_modelo.md', array_column($model['artifacts'], 'name'));
        $this->assertContains('prompt_usuario_para_ia.md', array_column($model['artifacts'], 'name'));

        $watermark = $context['orchestrator']->chat('Aplica marca de agua a los PDFs cargados', $splitIds);
        $this->assertSame('watermark_text', $watermark['data']['operation']);

        $redaction = $context['orchestrator']->chat('Quita los folios del area inferior derecha desde la pagina 1 hasta la pagina 2', $splitIds);
        $this->assertSame('redact_region', $redaction['data']['operation']);
        $this->assertSame('inferior derecha', $redaction['data']['region']);
    }

    public function test_business_tools_work_end_to_end_without_database(): void
    {
        $context = $this->makeRuntime();
        $orchestrator = $context['orchestrator'];

        $corporate = $orchestrator->chat('Muestrame los servicios ambientales disponibles', []);
        $this->assertSame('corporate_data', $corporate['tool_name']);
        $this->assertSame('services', $corporate['data']['table']);
        $this->assertSame('mock', $corporate['data']['source']);
        $this->assertGreaterThan(0, $corporate['data']['record_count']);

        $forms = $orchestrator->chat('Genera un formulario de control de residuos', []);
        $this->assertSame('forms', $forms['tool_name']);
        $this->assertSame('Formulario de control de residuos', $forms['data']['title']);

        $docx = $orchestrator->chat('Genera un reporte Word de hallazgos para auditoria medioambiental', []);
        $this->assertSame('office', $docx['tool_name']);
        $this->assertStringEndsWith('.docx', $docx['artifacts'][0]['name']);

        $xlsx = $orchestrator->chat('Prepara un Excel con indicadores operativos y columnas de seguimiento', []);
        $this->assertSame('office', $xlsx['tool_name']);
        $this->assertStringEndsWith('.xlsx', $xlsx['artifacts'][0]['name']);

        $pptx = $orchestrator->chat('Crea una presentacion comercial de Tema Litoclean', []);
        $this->assertSame('office', $pptx['tool_name']);
        $this->assertStringEndsWith('.pptx', $pptx['artifacts'][0]['name']);
        // Multi-slide deck: cover + agenda + 3 content + closing = 6 slides.
        $this->assertSame(6, $pptx['data']['slide_count']);

        $image = $orchestrator->chat('Genera un banner corporativo de Tema Litoclean', []);
        $this->assertSame('media_gen', $image['tool_name']);
        $this->assertSame('image_mock', $image['data']['mode']);

        $video = $orchestrator->chat('Crea un storyboard para video de servicios', []);
        $this->assertSame('media_gen', $video['tool_name']);
        $this->assertSame('storyboard', $video['data']['mode']);

        $reuse = $orchestrator->chat('Evalua si ya existe un modulo para trazabilidad documental y si no proponlo para backend y frontend', []);
        $this->assertSame('solution_evolution', $reuse['tool_name']);
        $this->assertContains($reuse['data']['analysis_mode'], ['reuse_existing', 'propose_extension']);

        $proposal = $orchestrator->chat('Necesito una funcionalidad nueva para conciliacion de manifiestos con aprobacion multiempresa y alertas regulatorias', []);
        $this->assertSame('solution_evolution', $proposal['tool_name']);
        $this->assertSame('propose_extension', $proposal['data']['analysis_mode']);
        $this->assertCount(2, $proposal['artifacts']);
    }

    public function test_excel_consolidation_merges_sheets_and_deduplicates(): void
    {
        $context = $this->makeRuntime();

        $ids = array_map(
            fn (array $artifact) => $artifact['id'],
            $context['files']->saveUploads([
                $this->makeXlsxUpload('lab_a.xlsx', ['parametro', 'valor'], [['pH', 7.2], ['Plomo', 0.02]]),
                $this->makeXlsxUpload('lab_b.xlsx', ['parametro', 'valor'], [['pH', 7.2], ['Arsenico', 0.05]]),
            ])
        );

        $result = $context['orchestrator']->chat('Consolida y deduplica los Excel de monitoreo cargados', $ids);

        $this->assertSame('excel_consolidation', $result['tool_name']);
        $this->assertSame('ok', $result['data']['status']);
        $this->assertSame(2, $result['data']['consolidated_files']);
        // 4 rows total, one duplicate (pH 7.2) removed -> 3.
        $this->assertSame(3, $result['data']['total_rows']);
        $this->assertSame(1, $result['data']['duplicates_removed']);
        $this->assertContains('origen_archivo', array_merge($result['data']['headers'], ['origen_archivo']));
        $this->assertStringEndsWith('.xlsx', $result['artifacts'][0]['name']);
    }

    public function test_env_threshold_comparison_flags_exceedances(): void
    {
        $context = $this->makeRuntime();

        $ids = array_map(
            fn (array $artifact) => $artifact['id'],
            $context['files']->saveUploads([
                $this->makeXlsxUpload('agua.xlsx', ['parametro', 'valor'], [
                    ['pH', 7.0],        // within 6.5-8.5 -> CUMPLE
                    ['Plomo', 0.50],    // > 0.05 -> EXCEDE
                    ['Parametro_X', 1], // SIN_UMBRAL
                ]),
            ])
        );

        $result = $context['orchestrator']->chat('Compara los resultados de agua cargados contra los umbrales ECA', $ids);

        $this->assertSame('env_monitoring_compare', $result['tool_name']);
        $this->assertSame('ok', $result['data']['status']);
        $this->assertSame(3, $result['data']['measurements']);
        $this->assertSame(1, $result['data']['exceedances']);
        $this->assertNotEmpty($result['data']['nota_legal']);
        $this->assertStringEndsWith('.xlsx', $result['artifacts'][0]['name']);
    }

    public function test_report_generator_produces_docx_with_deterministic_narrative(): void
    {
        $context = $this->makeRuntime();

        $ids = array_map(
            fn (array $artifact) => $artifact['id'],
            $context['files']->saveUploads([
                $this->makeXlsxUpload('datos.xlsx', ['parametro', 'valor'], [['pH', 7.0], ['Plomo', 0.5]]),
            ])
        );

        $result = $context['orchestrator']->chat('Genera un informe trimestral de monitoreo ambiental para el cliente', $ids);

        $this->assertSame('report_generator', $result['tool_name']);
        $this->assertSame('ok', $result['data']['status']);
        // AI disabled in the stub -> deterministic narrative.
        $this->assertSame('deterministic', $result['data']['narrative_mode']);
        $this->assertStringEndsWith('.docx', $result['artifacts'][0]['name']);
    }

    protected function makeRuntime(): array
    {
        $appSettings = $this->makeSettingsStub();
        $files = new FileArtifactService($appSettings);

        return [
            'files' => $files,
            'orchestrator' => new PhpToolOrchestratorService($appSettings, $files),
        ];
    }

    protected function makeOrchestrator(): PhpToolOrchestratorService
    {
        $context = $this->makeRuntime();

        return $context['orchestrator'];
    }

    protected function makeSettingsStub(): AppSettingsService
    {
        $setting = new AppSetting([
            'app_name' => 'Tema Litoclean',
            'company_name' => 'Tema Litoclean',
            'company_industry' => 'Medioambiental',
            'preferred_surface' => 'Web',
            'deployment_target' => 'Cloud',
            'corporate_identity_provider' => 'Validación por dominio corporativo',
            'require_corporate_email' => true,
            'allowed_domains' => ['tema.com.pe', 'tema.es'],
            'require_two_factor' => true,
            'mock_corporate_data' => true,
            'corporate_api_base_url' => null,
            'enable_anthropic_routing' => false,
            'anthropic_api_key' => null,
            'gen_image_api_provider' => null,
            'gen_video_api_provider' => null,
            'storage_dir' => $this->storageRoot,
            'audit_dir' => $this->storageRoot.DIRECTORY_SEPARATOR.'audit',
        ]);

        $mock = $this->createMock(AppSettingsService::class);
        $mock->method('ensureDefaults')->willReturn($setting);
        $mock->method('storageRootPath')->willReturn($this->storageRoot);
        $mock->method('auditRootPath')->willReturn($this->storageRoot.DIRECTORY_SEPARATOR.'audit');

        return $mock;
    }

    protected function makePdfUpload(string $originalName, array $pages): UploadedFile
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'tema_pdf_');
        $pdf = new \FPDF();
        $pdf->SetTitle(pathinfo($originalName, PATHINFO_FILENAME));

        foreach ($pages as $content) {
            $pdf->AddPage();
            $pdf->SetFont('Arial', '', 12);
            $pdf->MultiCell(0, 10, $content);
        }

        $pdf->Output('F', $tempPath);

        return new UploadedFile($tempPath, $originalName, 'application/pdf', null, true);
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    protected function makeXlsxUpload(string $originalName, array $headers, array $rows): UploadedFile
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'tema_xlsx_').'.xlsx';
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray($rows, null, 'A2');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($tempPath);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($tempPath, $originalName, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
