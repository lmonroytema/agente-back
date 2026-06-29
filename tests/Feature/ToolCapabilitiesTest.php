<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ToolCapabilitiesTest extends TestCase
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

    public function test_pdf_merge_split_and_range_operations_work(): void
    {
        $pdfIds = $this->uploadArtifacts([
            $this->makePdfUpload('lote_a.pdf', ['Pagina 1 A', 'Pagina 2 A']),
            $this->makePdfUpload('lote_b.pdf', ['Pagina 1 B']),
        ]);

        $merge = $this->runChat('Combina los PDFs cargados en un solo archivo', $pdfIds);
        $merge->assertJsonPath('tool_name', 'pdf_batch');
        $merge->assertJsonPath('data.operation', 'merge');
        $this->assertCount(1, $merge->json('artifacts'));
        $this->assertSame('pdf', $merge->json('artifacts.0.kind'));

        $splitIds = $this->uploadArtifacts([
            $this->makePdfUpload('manual.pdf', ['Pagina 1', 'Pagina 2', 'Pagina 3']),
        ]);

        $split = $this->runChat('Parte el PDF cargado en paginas individuales', $splitIds);
        $split->assertJsonPath('data.operation', 'split_pages');
        $this->assertCount(3, $split->json('artifacts'));

        $range = $this->runChat('Extrae las paginas 2 a 3 del PDF cargado', $splitIds);
        $range->assertJsonPath('data.operation', 'extract_pages');
        $this->assertCount(1, $range->json('artifacts'));
        $this->assertSame('pdf', $range->json('artifacts.0.kind'));
    }

    public function test_pdf_sections_text_model_watermark_and_redaction_work(): void
    {
        $pdfIds = $this->uploadArtifacts([
            $this->makePdfUpload('expediente.pdf', ['Uno', 'Dos', 'Tres', 'Cuatro']),
        ]);

        $sections = $this->runChat('Divide el PDF cargado en secciones 1-2, 3-4', $pdfIds);
        $sections->assertJsonPath('data.operation', 'split_sections');
        $this->assertCount(2, $sections->json('artifacts'));

        $text = $this->runChat('Extrae texto del PDF cargado', $pdfIds);
        $text->assertJsonPath('data.operation', 'extract_text');
        $this->assertSame('text', $text->json('artifacts.0.kind'));

        $model = $this->runChat('Lee el PDF cargado, extraelo a Markdown y responde: resume hallazgos y riesgos', $pdfIds);
        $model->assertJsonPath('data.operation', 'extract_for_model');
        $model->assertJsonPath('data.model_mode', 'markdown_ready');
        $artifactNames = array_column($model->json('artifacts'), 'name');
        $this->assertContains('pdf_para_modelo.md', $artifactNames);
        $this->assertContains('prompt_usuario_para_ia.md', $artifactNames);

        $watermark = $this->runChat('Aplica marca de agua a los PDFs cargados', $pdfIds);
        $watermark->assertJsonPath('data.operation', 'watermark_text');
        $this->assertSame('pdf', $watermark->json('artifacts.0.kind'));

        $redaction = $this->runChat('Quita los folios del area inferior derecha desde la pagina 1 hasta la pagina 2', $pdfIds);
        $redaction->assertJsonPath('data.operation', 'redact_region');
        $redaction->assertJsonPath('data.region', 'inferior derecha');
        $this->assertSame('pdf', $redaction->json('artifacts.0.kind'));
    }

    public function test_corporate_forms_office_media_and_solution_tools_work(): void
    {
        $corporate = $this->runChat('Muestrame los servicios ambientales disponibles');
        $corporate->assertJsonPath('tool_name', 'corporate_data');
        $corporate->assertJsonPath('data.table', 'services');
        $corporate->assertJsonPath('data.source', 'mock');
        $this->assertGreaterThan(0, $corporate->json('data.record_count'));

        $forms = $this->runChat('Genera un formulario de control de residuos');
        $forms->assertJsonPath('tool_name', 'forms');
        $forms->assertJsonPath('data.title', 'Formulario de control de residuos');
        $this->assertGreaterThanOrEqual(6, $forms->json('data.field_count'));

        $docx = $this->runChat('Genera un reporte Word de hallazgos para auditoria medioambiental');
        $docx->assertJsonPath('tool_name', 'office');
        $this->assertStringEndsWith('.docx', $docx->json('artifacts.0.name'));

        $xlsx = $this->runChat('Prepara un Excel con indicadores operativos y columnas de seguimiento');
        $xlsx->assertJsonPath('tool_name', 'office');
        $this->assertStringEndsWith('.xlsx', $xlsx->json('artifacts.0.name'));

        $pptx = $this->runChat('Crea una presentacion comercial de Tema Litoclean');
        $pptx->assertJsonPath('tool_name', 'office');
        $this->assertStringEndsWith('.pptx', $pptx->json('artifacts.0.name'));

        $image = $this->runChat('Genera un banner corporativo de Tema Litoclean');
        $image->assertJsonPath('tool_name', 'media_gen');
        $image->assertJsonPath('data.mode', 'image_mock');
        $this->assertStringEndsWith('.svg', $image->json('artifacts.0.name'));

        $video = $this->runChat('Crea un storyboard para video de servicios');
        $video->assertJsonPath('tool_name', 'media_gen');
        $video->assertJsonPath('data.mode', 'storyboard');
        $this->assertStringEndsWith('.md', $video->json('artifacts.0.name'));

        $reuse = $this->runChat('Evalua si ya existe un modulo para trazabilidad documental y si no proponlo para backend y frontend');
        $reuse->assertJsonPath('tool_name', 'solution_evolution');
        $this->assertContains($reuse->json('data.analysis_mode'), ['reuse_existing', 'propose_extension']);

        $proposal = $this->runChat('Necesito una funcionalidad nueva para conciliacion de manifiestos con aprobacion multiempresa y alertas regulatorias');
        $proposal->assertJsonPath('tool_name', 'solution_evolution');
        $proposal->assertJsonPath('data.analysis_mode', 'propose_extension');
        $this->assertCount(2, $proposal->json('artifacts'));
    }

    protected function uploadArtifacts(array $files): array
    {
        $response = $this->post('/api/files/upload', [
            'files' => $files,
        ])->assertOk();

        return array_column($response->json(), 'id');
    }

    protected function runChat(string $message, array $uploadedFileIds = [])
    {
        return $this->postJson('/api/chat', [
            'message' => $message,
            'uploaded_file_ids' => $uploadedFileIds,
        ])->assertOk();
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
}
