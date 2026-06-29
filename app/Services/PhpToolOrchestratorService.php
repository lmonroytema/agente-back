<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpOffice\PhpPresentation\IOFactory as PresentationIOFactory;
use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\Slide;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\PhpWord;
use setasign\Fpdi\Fpdi;
use Smalot\PdfParser\Parser;
use Throwable;

class PhpToolOrchestratorService
{
    /**
     * Conservative character cap for the markdown sent to the model.
     * Keeps the request well inside the context window and bounds per-call cost;
     * content beyond this is truncated with a visible notice rather than silently sent.
     */
    protected const MAX_MARKDOWN_CHARS = 120000;

    /**
     * Max tokens requested from the model per call.
     */
    protected const ANTHROPIC_MAX_TOKENS = 1200;

    public function __construct(
        protected AppSettingsService $appSettings,
        protected FileArtifactService $files,
        protected ?ExcelConsolidationService $excelConsolidation = null,
        protected ?EnvironmentalThresholdService $environmentalThresholds = null,
        protected ?ReportGeneratorService $reportGenerator = null,
        protected ?AnthropicClient $anthropic = null,
    ) {
        $this->excelConsolidation ??= new ExcelConsolidationService($this->files);
        $this->environmentalThresholds ??= new EnvironmentalThresholdService($this->excelConsolidation, $this->files);
        $this->anthropic ??= new AnthropicClient($this->appSettings);
        $this->reportGenerator ??= new ReportGeneratorService($this->anthropic, $this->appSettings, $this->files);
    }

    public function capabilities(): array
    {
        return [
            [
                'tool' => 'pdf_batch',
                'title' => 'Edicion masiva de PDF',
                'description' => 'Combina PDFs, los parte por paginas o secciones, extrae texto o rangos, aplica marca de agua, prepara Markdown para IA y cubre folios o numeracion por zona.',
                'examples' => [
                    'Combina los PDFs cargados en un solo archivo',
                    'Parte el PDF cargado en paginas individuales',
                    'Divide el PDF cargado en secciones 1-5, 6-10 y 11-15',
                    'Lee el PDF cargado, extraelo a Markdown y responde: resume riesgos y hallazgos',
                ],
            ],
            [
                'tool' => 'corporate_data',
                'title' => 'Consulta de datos corporativos',
                'description' => 'Consulta servicios, clientes y proyectos de Tema Litoclean con salida JSON auditable.',
                'examples' => [
                    'Muestrame los servicios ambientales disponibles',
                    'Consulta los proyectos de remediacion activos',
                    'Busca clientes relacionados con mineria',
                ],
            ],
            [
                'tool' => 'forms',
                'title' => 'Formularios inteligentes',
                'description' => 'Construye formularios de captura y reglas de validacion para operaciones medioambientales.',
                'examples' => [
                    'Crea un formulario de inspeccion ambiental',
                    'Genera una ficha de captura para residuos',
                ],
            ],
            [
                'tool' => 'office',
                'title' => 'Ofimatica Microsoft',
                'description' => 'Genera documentos DOCX, PPTX y XLSX para reportes y presentaciones operativas.',
                'examples' => [
                    'Crea una presentacion comercial de Tema Litoclean',
                    'Prepara un Excel con indicadores de operacion',
                ],
            ],
            [
                'tool' => 'media_gen',
                'title' => 'Generacion de imagen y video',
                'description' => 'Crea piezas visuales iniciales y storyboards usando proveedores configurables o salidas mock seguras.',
                'examples' => [
                    'Genera un banner de Tema Litoclean',
                    'Crea un storyboard para video de servicios',
                ],
            ],
            [
                'tool' => 'excel_consolidation',
                'title' => 'Consolidacion de Excels (PREMIUM)',
                'description' => 'Une varios .xlsx/.csv (resultados de laboratorio, monitoreos) en un libro consolidado con hoja Consolidado (columna origen_archivo), hoja Resumen (conteos y promedios) y deduplicacion opcional. 100% PHP, sin IA.',
                'accepts_files' => true,
                'premium' => true,
                'examples' => [
                    'Consolida los Excel de monitoreo cargados',
                    'Consolida y deduplica los resultados de laboratorio cargados',
                ],
            ],
            [
                'tool' => 'env_monitoring_compare',
                'title' => 'Comparacion contra umbrales ECA/LMP (PREMIUM)',
                'description' => 'Compara mediciones de monitoreo (agua, aire, ruido) contra umbrales ambientales REFERENCIALES y marca cada valor como CUMPLE / EXCEDE / SIN_UMBRAL. Genera un Excel con el resumen de excedencias. Los umbrales son referenciales y deben validarse contra la norma vigente.',
                'accepts_files' => true,
                'premium' => true,
                'examples' => [
                    'Compara los resultados de agua cargados contra los umbrales ECA',
                    'Revisa excedencias de LMP en el monitoreo de aire cargado',
                ],
            ],
            [
                'tool' => 'report_generator',
                'title' => 'Generador de informes profesionales (PREMIUM)',
                'description' => 'Genera un informe Word (DOCX) de consultora ambiental con portada, indice, introduccion, metodologia, resultados, conclusiones y recomendaciones. La narrativa usa IA solo si esta habilitada; si no, usa plantillas deterministas. Las tablas salen de los datos consolidados/comparados.',
                'accepts_files' => true,
                'premium' => true,
                'examples' => [
                    'Genera un informe trimestral de monitoreo ambiental',
                    'Crea un informe profesional con los datos cargados para el cliente',
                ],
            ],
            [
                'tool' => 'solution_evolution',
                'title' => 'Arquitectura evolutiva por lenguaje natural',
                'description' => 'Evalua solicitudes, decide si se reutiliza una funcionalidad existente o si conviene proponer una ampliacion del App.',
                'examples' => [
                    'Evalua si ya existe un modulo para firma digital y si no proponlo',
                    'Analiza este requerimiento y reutiliza primero lo que ya existe',
                ],
            ],
        ];
    }

    public function chat(string $message, array $uploadedFileIds): array
    {
        $this->appSettings->ensureDefaults();
        $this->files->ensureDirectories();

        $jobId = 'job_'.Str::lower((string) Str::uuid());
        $tool = $this->routeMessage($message);
        $result = match ($tool) {
            'pdf_batch' => $this->handlePdfBatch($jobId, $message, $uploadedFileIds),
            'corporate_data' => $this->handleCorporateData($jobId, $message),
            'forms' => $this->handleForms($jobId, $message),
            'office' => $this->handleOffice($jobId, $message),
            'media_gen' => $this->handleMediaGen($jobId, $message),
            'excel_consolidation' => $this->handleExcelConsolidation($jobId, $message, $uploadedFileIds),
            'env_monitoring_compare' => $this->handleEnvMonitoringCompare($jobId, $message, $uploadedFileIds),
            'report_generator' => $this->handleReportGenerator($jobId, $message, $uploadedFileIds),
            default => $this->handleSolutionEvolution($jobId, $message),
        };

        $response = [
            'job_id' => $jobId,
            'tool_name' => $result['tool_name'],
            'reply' => $result['summary'],
            'steps' => $result['steps'],
            'artifacts' => $result['artifacts'],
            'data' => $result['data'],
            'requires_confirmation' => false,
        ];

        $this->writeAudit($jobId, $tool, $message, $uploadedFileIds, $response);

        return $response;
    }

    protected function handlePdfBatch(string $jobId, string $message, array $uploadedFileIds): array
    {
        $pdfPaths = [];
        foreach ($uploadedFileIds as $artifactId) {
            $path = $this->files->getFilePath((string) $artifactId);
            if ($path && Str::lower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf') {
                $pdfPaths[] = $path;
            }
        }

        if ($pdfPaths === []) {
            return $this->toolResult(
                'pdf_batch',
                'Sube uno o mas archivos PDF para ejecutar el lote. El modulo PHP ya esta listo, pero necesita entradas validas.',
                [
                    'No se encontraron PDFs cargados en la solicitud.',
                    'Se devolvio una instruccion de siguiente paso sin ejecutar trabajo pesado.',
                ],
                [],
                [
                    'accepted_operations' => [
                        'merge',
                        'split_pages',
                        'split_sections',
                        'extract_pages',
                        'extract_text',
                        'extract_for_model',
                        'watermark_text',
                        'redact_region',
                    ],
                ],
            );
        }

        $text = $this->normalizeText($message);

        if ($this->isModelReadRequest($text)) {
            return $this->extractForModel($jobId, $pdfPaths, $message);
        }

        if ($this->isPageSplitRequest($text)) {
            return $this->splitPages($jobId, $pdfPaths);
        }

        if ($this->isSectionSplitRequest($text)) {
            return $this->splitSections($jobId, $pdfPaths, $text);
        }

        if ($this->isPageExtractRequest($text)) {
            return $this->extractPageRanges($jobId, $pdfPaths, $text);
        }

        if ($this->isRedactionRequest($text)) {
            return $this->redactRegion($jobId, $pdfPaths, $text);
        }

        if (str_contains($text, 'extrae') && str_contains($text, 'texto')) {
            return $this->extractText($jobId, $pdfPaths);
        }

        if (str_contains($text, 'marca de agua') || str_contains($text, 'watermark')) {
            return $this->watermark($jobId, $pdfPaths);
        }

        return $this->mergePdfs($jobId, $pdfPaths);
    }

    protected function handleCorporateData(string $jobId, string $message): array
    {
        $settings = $this->appSettings->ensureDefaults();
        $dataset = $this->loadCorporateDataset();

        $table = $this->selectCorporateTable($message);
        $rows = (array) ($dataset[$table] ?? []);
        $keyword = $this->extractCorporateKeyword($message);
        $filtered = $keyword
            ? array_values(array_filter($rows, fn (array $row) => str_contains($this->normalizeText(json_encode($row, JSON_UNESCAPED_UNICODE)), $keyword)))
            : array_slice($rows, 0, 5);
        $integrationStatus = (! $settings->mock_corporate_data && filled($settings->corporate_api_base_url))
            ? 'api_configurada_pendiente_de_integracion'
            : 'mock_local';
        $artifact = $this->files->writeTextOutput(
            $jobId,
            $table.'_consulta.json',
            json_encode($filtered, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $this->toolResult(
            'corporate_data',
            'Se consulto la tabla '.$table.' y se devolvieron '.count($filtered).' registros usando el dataset mock local de Laravel.',
            [
                'Se identifico la tabla mas probable a partir del lenguaje natural.',
                'Se consulto el dataset corporativo mock embebido en Laravel para evitar dependencias externas durante la operacion local.',
                'Se aplico filtro simple por palabra clave cuando estuvo disponible.',
                'Se genero un archivo JSON descargable con el resultado.',
            ],
            [$artifact],
            [
                'table' => $table,
                'record_count' => count($filtered),
                'source' => 'mock',
                'integration_status' => $integrationStatus,
            ],
        );
    }

    protected function handleForms(string $jobId, string $message): array
    {
        $settings = $this->appSettings->ensureDefaults();
        $lower = $this->normalizeText($message);
        $title = 'Formulario de inspeccion ambiental';
        $fields = [
            ['name' => 'fecha_visita', 'type' => 'date', 'required' => true, 'label' => 'Fecha de visita'],
            ['name' => 'cliente', 'type' => 'text', 'required' => true, 'label' => 'Cliente'],
            ['name' => 'ubicacion', 'type' => 'text', 'required' => true, 'label' => 'Ubicacion'],
            ['name' => 'hallazgos', 'type' => 'textarea', 'required' => true, 'label' => 'Hallazgos'],
            ['name' => 'nivel_riesgo', 'type' => 'select', 'required' => true, 'label' => 'Nivel de riesgo', 'options' => ['Bajo', 'Medio', 'Alto']],
        ];

        if (str_contains($lower, 'residu')) {
            $title = 'Formulario de control de residuos';
            $fields[] = ['name' => 'volumen_tn', 'type' => 'number', 'required' => true, 'label' => 'Volumen en toneladas'];
        }

        if (str_contains($lower, 'agua')) {
            $title = 'Formulario de muestreo de agua';
            $fields[] = ['name' => 'ph', 'type' => 'number', 'required' => false, 'label' => 'pH'];
        }

        $payload = [
            'title' => $title,
            'company' => $settings->company_name,
            'industry' => $settings->company_industry,
            'fields' => $fields,
            'validation_notes' => [
                'Validar campos obligatorios antes de guardar.',
                'Solicitar confirmacion humana si la captura actualiza una tabla corporativa.',
                'Permitir adjuntar evidencia fotografica y PDF en fases posteriores.',
            ],
        ];

        $artifact = $this->files->writeTextOutput(
            $jobId,
            'formulario_inteligente.json',
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $this->toolResult(
            'forms',
            'Se genero '.Str::lower($title).' con '.count($fields).' campos parametrizables.',
            [
                'Se detecto el contexto operativo del formulario.',
                'Se definieron campos, tipos y obligatoriedad.',
                'Se preparo un artefacto JSON listo para integrarlo con frontend o Power Apps.',
            ],
            [$artifact],
            [
                'title' => $title,
                'field_count' => count($fields),
            ],
        );
    }

    protected function handleOffice(string $jobId, string $message): array
    {
        $settings = $this->appSettings->ensureDefaults();
        $text = $this->normalizeText($message);
        $title = $this->deriveOfficeTitle($message);
        $slideCount = null;

        if (str_contains($text, 'ppt') || str_contains($text, 'presentacion') || str_contains($text, 'powerpoint')) {
            [$artifact, $slideCount] = $this->buildPresentation($jobId, $title, $settings);
            $summary = 'Se genero una presentacion PPTX estructurada de '.$slideCount.' diapositivas para '.$title.'.';
        } elseif (str_contains($text, 'excel') || str_contains($text, 'xlsx') || str_contains($text, 'indicador') || str_contains($text, 'kpi')) {
            $path = $this->files->prepareOutputPath($jobId, 'indicadores_tema_litoclean.xlsx');
            $sheetBook = new Spreadsheet();
            $sheet = $sheetBook->getActiveSheet();
            $sheet->setTitle('KPIs');
            $sheet->fromArray([
                ['Indicador', 'Objetivo', 'Valor actual'],
                ['Toneladas tratadas', 120, 96],
                ['Visitas tecnicas', 30, 24],
                ['Incidentes reportados', 0, 1],
                ['Cumplimiento SLA', '95%', '93%'],
            ]);
            $meta = $sheetBook->createSheet();
            $meta->setTitle('Contexto');
            $meta->fromArray([
                ['Titulo', $title],
                ['Empresa', $settings->company_name],
                ['Superficie preferida', $settings->preferred_surface],
            ]);
            (new Xlsx($sheetBook))->save($path);
            $artifact = $this->files->buildArtifact($path, 'indicadores_tema_litoclean.xlsx');
            $summary = 'Se genero un libro Excel con indicadores base para '.$title.'.';
        } else {
            $path = $this->files->prepareOutputPath($jobId, 'reporte_tema_litoclean.docx');
            $document = new PhpWord();
            $section = $document->addSection();
            $section->addTitle($title, 1);
            $section->addText('Empresa: '.$settings->company_name);
            $section->addText('Rubro: '.$settings->company_industry);
            $section->addTitle('Resumen ejecutivo', 2);
            $section->addText('Esta plantilla resume el estado de la operacion, los riesgos y las acciones recomendadas para clientes industriales y mineros.');
            $section->addTitle('Siguientes pasos', 2);
            $section->addText('Validar datos corporativos y confirmar cualquier escritura sobre sistemas base.');
            $writer = WordIOFactory::createWriter($document, 'Word2007');
            $writer->save($path);
            $artifact = $this->files->buildArtifact($path, 'reporte_tema_litoclean.docx');
            $summary = 'Se genero un documento Word para '.$title.'.';
        }

        return $this->toolResult(
            'office',
            $summary,
            [
                'Se clasifico el tipo de salida ofimatica requerida.',
                'Se compuso contenido inicial con foco en Tema Litoclean.',
                'Se exporto el archivo listo para descarga.',
            ],
            [$artifact],
            array_filter([
                'title' => $title,
                'graph_ready' => (bool) $settings->office_graph_client_id,
                'slide_count' => $slideCount,
            ], fn ($value) => $value !== null),
        );
    }

    /**
     * Builds a structured, multi-slide PPTX (cover, agenda, content, closing/contact)
     * using PhpPresentation. Returns [artifact, slideCount].
     *
     * @return array{0: array<string, mixed>, 1: int}
     */
    protected function buildPresentation(string $jobId, string $title, \App\Models\AppSetting $settings): array
    {
        $path = $this->files->prepareOutputPath($jobId, 'presentacion_tema_litoclean.pptx');
        $company = $settings->company_name;
        $brand = 'B91D47';

        $presentation = new PhpPresentation();
        $presentation->getDocumentProperties()
            ->setCreator($company)
            ->setTitle($title)
            ->setCompany($company);

        // 1. Portada (reutiliza la slide activa inicial).
        $cover = $presentation->getActiveSlide();
        $this->slideBackground($cover, $brand);
        $this->slideTextBox($cover, $company, 40, 130, 880, 90, 40, true, 'FFFFFF');
        $this->slideTextBox($cover, $title, 40, 240, 880, 80, 26, false, 'F3D6DC');
        $this->slideTextBox($cover, $settings->company_industry.' | Consultoria ambiental y seguridad industrial', 40, 340, 880, 60, 16, false, 'F3D6DC');
        $this->slideTextBox($cover, 'Fecha: '.now()->format('d/m/Y'), 40, 420, 880, 40, 14, false, 'F3D6DC');

        // 2. Agenda.
        $this->contentSlide($presentation, 'Agenda', [
            'Presentacion de Tema Litoclean',
            'Servicios y capacidades clave',
            'Metodologia y flujo operativo',
            'Beneficios y propuesta de valor',
            'Siguientes pasos y contacto',
        ], $brand);

        // 3-5. Slides de contenido con bullets.
        $this->contentSlide($presentation, 'Servicios y capacidades clave', [
            'Monitoreo ambiental de agua, aire, suelo y ruido',
            'Consolidacion de resultados de laboratorio y monitoreo',
            'Comparacion contra estandares de referencia (ECA/LMP)',
            'Generacion de informes tecnicos profesionales',
        ], $brand);

        $this->contentSlide($presentation, 'Metodologia y flujo operativo', [
            'Captura y carga segura de datos en planta y campo',
            'Procesamiento determinista y trazable de la informacion',
            'Validacion tecnica y control de calidad',
            'Entrega de entregables auditables al cliente',
        ], $brand);

        $this->contentSlide($presentation, 'Beneficios para el cliente', [
            'Ahorro de tiempo en consolidacion y reporteria',
            'Trazabilidad y soporte para auditorias regulatorias',
            'Deteccion temprana de excedencias y riesgos',
            'Informes claros y listos para presentar',
        ], $brand);

        // 6. Cierre / contacto.
        $closing = $presentation->createSlide();
        $this->slideBackground($closing, $brand);
        $this->slideTextBox($closing, 'Gracias', 40, 150, 880, 90, 40, true, 'FFFFFF');
        $this->slideTextBox($closing, $company, 40, 270, 880, 50, 22, false, 'F3D6DC');
        $this->slideTextBox($closing, 'Contacto: contacto@tema.com.pe | www.tema.com.pe', 40, 340, 880, 50, 16, false, 'F3D6DC');

        PresentationIOFactory::createWriter($presentation, 'PowerPoint2007')->save($path);
        $slideCount = $presentation->getSlideCount();

        return [$this->files->buildArtifact($path, 'presentacion_tema_litoclean.pptx'), $slideCount];
    }

    /**
     * Adds a content slide with a title and bullet list.
     *
     * @param  array<int, string>  $bullets
     */
    protected function contentSlide(PhpPresentation $presentation, string $title, array $bullets, string $brand): void
    {
        $slide = $presentation->createSlide();

        $titleShape = $slide->createRichTextShape()->setHeight(70)->setWidth(880)->setOffsetX(40)->setOffsetY(40);
        $titleRun = $titleShape->createTextRun($title);
        $titleRun->getFont()->setBold(true)->setSize(28)->getColor()->setRGB($brand);

        $bodyShape = $slide->createRichTextShape()->setHeight(380)->setWidth(880)->setOffsetX(60)->setOffsetY(140);
        $first = true;
        foreach ($bullets as $bullet) {
            $paragraph = $first ? $bodyShape->getActiveParagraph() : $bodyShape->createParagraph();
            $first = false;
            $paragraph->getBulletStyle()->setBulletChar('-');
            $paragraph->getAlignment()->setMarginLeft(40)->setIndent(-20);
            $run = $paragraph->createTextRun($bullet);
            $run->getFont()->setSize(18)->getColor()->setRGB('333333');
        }
    }

    /**
     * Paints a full-slide solid background rectangle in the given hex color.
     */
    protected function slideBackground(Slide $slide, string $hex): void
    {
        $shape = $slide->createRichTextShape()->setHeight(540)->setWidth(960)->setOffsetX(0)->setOffsetY(0);
        $shape->getFill()->setFillType(\PhpOffice\PhpPresentation\Style\Fill::FILL_SOLID)->setStartColor(new \PhpOffice\PhpPresentation\Style\Color('FF'.$hex));
        $shape->getActiveParagraph()->createTextRun(' ');
    }

    /**
     * Adds a positioned text box with basic styling.
     */
    protected function slideTextBox(Slide $slide, string $text, int $x, int $y, int $w, int $h, int $size, bool $bold, string $hex): void
    {
        $shape = $slide->createRichTextShape()->setHeight($h)->setWidth($w)->setOffsetX($x)->setOffsetY($y);
        $run = $shape->createTextRun($text);
        $run->getFont()->setSize($size)->setBold($bold)->getColor()->setRGB($hex);
    }

    protected function handleMediaGen(string $jobId, string $message): array
    {
        $settings = $this->appSettings->ensureDefaults();
        $text = $this->normalizeText($message);

        if (str_contains($text, 'video')) {
            $artifact = $this->files->writeTextOutput(
                $jobId,
                'storyboard_video.md',
                "# Storyboard Tema Litoclean\n\n1. Apertura con operacion de campo.\n2. Problema ambiental del cliente.\n3. Solucion de Tema Litoclean.\n4. Resultados medibles y llamada a la accion.\n"
            );
            $summary = 'Se genero un storyboard de video parametrizable. Cuando se configure un proveedor real, este modulo puede invocar la API externa.';
            $data = ['mode' => 'storyboard', 'provider' => $settings->gen_video_api_provider ?: 'pendiente'];
        } else {
            $safePrompt = str_replace(['&', '<', '>'], ['y', '', ''], $message);
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1280" height="720"><rect width="100%" height="100%" fill="#7c1734" /><text x="80" y="180" fill="#ffffff" font-size="56" font-family="Arial">Tema Litoclean</text><text x="80" y="270" fill="#f3d6dc" font-size="34" font-family="Arial">Soluciones medioambientales trazables</text><text x="80" y="340" fill="#ffffff" font-size="26" font-family="Arial">Prompt: '.$safePrompt.'</text></svg>';
            $artifact = $this->files->writeTextOutput($jobId, 'pieza_visual.svg', $svg);
            $summary = 'Se genero una pieza visual de referencia en SVG. Puede sustituirse por una API de imagen cuando se configure el proveedor.';
            $data = ['mode' => 'image_mock', 'provider' => $settings->gen_image_api_provider ?: 'pendiente'];
        }

        return $this->toolResult(
            'media_gen',
            $summary,
            [
                'Se determino el tipo de activo solicitado.',
                'Se uso una salida segura en modo local para evitar depender de credenciales externas.',
                'Se dejo el punto de extension listo para integrar un proveedor generativo real.',
            ],
            [$artifact],
            $data,
        );
    }

    protected function handleExcelConsolidation(string $jobId, string $message, array $uploadedFileIds): array
    {
        $inputs = $this->resolveSpreadsheetInputs($uploadedFileIds);
        if ($inputs === []) {
            return $this->toolResult(
                'excel_consolidation',
                'Sube uno o mas archivos .xlsx, .xls o .csv para consolidarlos. El modulo PHP esta listo pero necesita entradas validas.',
                ['No se encontraron hojas de calculo cargadas en la solicitud.'],
                [],
                ['operation' => 'excel_consolidation', 'status' => 'sin_entradas'],
            );
        }

        $deduplicate = $this->containsAny($this->normalizeText($message), ['deduplica', 'duplicad', 'sin duplicados', 'elimina duplicados']);
        $result = $this->excelConsolidation->consolidate($jobId, $inputs, $deduplicate);

        $summary = ($result['data']['status'] ?? '') === 'ok'
            ? 'Se consolidaron '.($result['data']['consolidated_files'] ?? 0).' archivo(s) en "consolidado.xlsx" con '.($result['data']['total_rows'] ?? 0).' fila(s).'
            : 'No se pudo consolidar: revisa que los archivos tengan encabezados legibles.';

        return $this->toolResult('excel_consolidation', $summary, $result['steps'], $result['artifacts'], $result['data']);
    }

    protected function handleEnvMonitoringCompare(string $jobId, string $message, array $uploadedFileIds): array
    {
        $inputs = $this->resolveSpreadsheetInputs($uploadedFileIds);
        if ($inputs === []) {
            return $this->toolResult(
                'env_monitoring_compare',
                'Sube un Excel/CSV de monitoreo (o el consolidado) para comparar contra los umbrales ECA/LMP referenciales.',
                ['No se encontraron datos de monitoreo cargados.'],
                [],
                ['operation' => 'env_monitoring_compare', 'status' => 'sin_entradas'],
            );
        }

        $first = $inputs[0];
        $result = $this->environmentalThresholds->compareFile($jobId, $first['path'], $first['name']);

        $summary = ($result['data']['status'] ?? '') === 'ok'
            ? 'Se evaluaron '.($result['data']['measurements'] ?? 0).' medicion(es) contra umbrales REFERENCIALES: '.($result['data']['exceedances'] ?? 0).' excedencia(s). Valida los umbrales contra la norma vigente.'
            : 'No se pudo comparar: el archivo no tiene encabezados/datos reconocibles.';

        return $this->toolResult('env_monitoring_compare', $summary, $result['steps'], $result['artifacts'], $result['data']);
    }

    protected function handleReportGenerator(string $jobId, string $message, array $uploadedFileIds): array
    {
        $context = $this->buildReportContext($jobId, $uploadedFileIds);
        $result = $this->reportGenerator->generate($jobId, $message, $context);

        $summary = 'Se genero un informe ambiental profesional (DOCX) con narrativa '.
            (($result['data']['narrative_mode'] ?? 'deterministic') === 'ai' ? 'asistida por IA' : 'deterministica').
            ' y '.($result['data']['data_rows'] ?? 0).' fila(s) de datos.';

        return $this->toolResult('report_generator', $summary, $result['steps'], $result['artifacts'], $result['data']);
    }

    /**
     * Reads uploaded spreadsheets into the report context: data rows + detected exceedances.
     *
     * @return array<string, mixed>
     */
    protected function buildReportContext(string $jobId, array $uploadedFileIds): array
    {
        $inputs = $this->resolveSpreadsheetInputs($uploadedFileIds);
        if ($inputs === []) {
            return [];
        }

        try {
            [$headers, $rows] = $this->excelConsolidation->readTabular($inputs[0]['path']);
        } catch (Throwable) {
            return [];
        }

        $assocRows = [];
        foreach (array_slice($rows, 0, 100) as $row) {
            $assoc = [];
            foreach ($headers as $i => $header) {
                $assoc[$header] = $row[$i] ?? null;
            }
            $assocRows[] = $assoc;
        }

        $context = ['rows' => $assocRows];

        // Reuse the deterministic comparison to surface exceedances if any thresholds match.
        $comparison = $this->environmentalThresholds->compareFile($jobId, $inputs[0]['path'], $inputs[0]['name']);
        $evaluations = $comparison['data']['evaluations'] ?? [];
        $context['exceedances'] = array_values(array_filter(
            is_array($evaluations) ? $evaluations : [],
            fn ($e) => ($e['estado'] ?? '') === EnvironmentalThresholdService::STATUS_EXCEEDS
        ));

        return $context;
    }

    /**
     * @return array<int, array{path: string, name: string}>
     */
    protected function resolveSpreadsheetInputs(array $uploadedFileIds): array
    {
        $inputs = [];
        foreach ($uploadedFileIds as $artifactId) {
            $path = $this->files->getFilePath((string) $artifactId);
            if ($path && ExcelConsolidationService::isSupported($path)) {
                $inputs[] = ['path' => $path, 'name' => (string) $artifactId];
            }
        }

        return $inputs;
    }

    protected function handleSolutionEvolution(string $jobId, string $message): array
    {
        $text = $this->normalizeText($message);
        if (count(array_filter(explode(' ', $text))) < 3) {
            return $this->toolResult(
                'solution_evolution',
                'La solicitud es muy breve. Describe el requerimiento funcional y el modulo analizara si ya existe cobertura o si debe proponerse una extension.',
                [
                    'Se recibio una solicitud demasiado corta para detectar cobertura funcional.',
                    'Se devolvio una guia de siguiente paso sin generar artefactos.',
                ],
                [],
                ['analysis_mode' => 'needs_more_context'],
            );
        }

        $catalog = [
            [
                'tool' => 'pdf_batch',
                'title' => 'Edicion de PDF',
                'keywords' => ['pdf', 'folio', 'folios', 'combinar', 'unir', 'texto', 'marca de agua', 'extraer', 'paginas'],
                'backend_files' => ['backend-laravel/app/Services/PhpToolOrchestratorService.php', 'backend-laravel/app/Http/Controllers/Api/ChatController.php'],
                'frontend_files' => ['frontend/src/App.tsx', 'frontend/src/styles.css'],
                'reuse_notes' => 'Ya existe procesamiento PHP de PDFs con combinacion, extraccion, marca de agua, lectura para IA y cobertura por zona.',
            ],
            [
                'tool' => 'corporate_data',
                'title' => 'Datos corporativos',
                'keywords' => ['cliente', 'clientes', 'servicio', 'servicios', 'proyecto', 'proyectos', 'dato', 'datos', 'api', 'consulta'],
                'backend_files' => ['backend-laravel/app/Services/PhpToolOrchestratorService.php'],
                'frontend_files' => ['frontend/src/App.tsx'],
                'reuse_notes' => 'Ya existe consulta base con salida JSON auditable.',
            ],
            [
                'tool' => 'office',
                'title' => 'Ofimatica',
                'keywords' => ['presentacion', 'powerpoint', 'ppt', 'word', 'docx', 'excel', 'xlsx', 'reporte', 'informe'],
                'backend_files' => ['backend-laravel/app/Services/PhpToolOrchestratorService.php'],
                'frontend_files' => ['frontend/src/App.tsx'],
                'reuse_notes' => 'Ya existe generacion PHP de entregables DOCX, PPTX y XLSX.',
            ],
            [
                'tool' => 'forms',
                'title' => 'Formularios',
                'keywords' => ['formulario', 'captura', 'registro', 'inspeccion', 'validar', 'validacion', 'ficha'],
                'backend_files' => ['backend-laravel/app/Services/PhpToolOrchestratorService.php'],
                'frontend_files' => ['frontend/src/App.tsx'],
                'reuse_notes' => 'Ya existe un generador de formularios y reglas de validacion.',
            ],
        ];

        $ranked = [];
        foreach ($catalog as $entry) {
            $score = 0;
            foreach ($entry['keywords'] as $keyword) {
                if (str_contains($text, $keyword)) {
                    $score++;
                }
            }
            if ($score > 0) {
                $entry['score'] = $score;
                $ranked[] = $entry;
            }
        }

        usort($ranked, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        if ($ranked !== [] && ($ranked[0]['score'] ?? 0) >= 2) {
            $best = $ranked[0];
            $markdown = "# Reuso recomendado\n\n";
            $markdown .= "Solicitud: ".$message."\n\n";
            $markdown .= "Modulo recomendado: ".$best['tool']."\n\n";
            $markdown .= "Motivo: ".$best['reuse_notes']."\n\n";
            $markdown .= "Backend:\n- ".implode("\n- ", $best['backend_files'])."\n\n";
            $markdown .= "Frontend:\n- ".implode("\n- ", $best['frontend_files'])."\n";
            $artifact = $this->files->writeTextOutput($jobId, 'reuso_'.$best['tool'].'.md', $markdown);

            return $this->toolResult(
                'solution_evolution',
                "La solicitud puede reutilizar principalmente el modulo '".$best['tool']."' sin crear una funcionalidad nueva desde cero.",
                [
                    'Se analizo el requerimiento contra el catalogo actual de herramientas PHP.',
                    'Se detecto cobertura suficiente en un modulo ya implementado.',
                    'Se documento como reutilizarlo y donde extender backend o frontend si fuera necesario.',
                ],
                [$artifact],
                [
                    'analysis_mode' => 'reuse_existing',
                    'recommended_tool' => $best['tool'],
                    'matched_tools' => array_map(fn (array $entry) => $entry['tool'], array_slice($ranked, 0, 3)),
                    'score' => $best['score'],
                ],
            );
        }

        $featureId = 'feature_'.Str::lower(Str::random(8));
        $blueprint = [
            'feature_id' => $featureId,
            'feature_name' => Str::title(implode(' ', array_slice(preg_split('/\s+/', trim($message)) ?: [], 0, 6))),
            'summary' => 'Nueva propuesta funcional derivada del lenguaje natural para ampliar la app sin depender de Python.',
            'backend_scope' => [
                'Crear endpoint en Laravel para la nueva operacion.',
                'Agregar servicio dedicado y artefactos auditables en storage.',
                'Proteger integraciones externas con configuracion administrativa.',
            ],
            'frontend_scope' => [
                'Incorporar nueva tarjeta o seccion en Operacion o Modulos.',
                'Mostrar pasos, artefactos y estado de proceso.',
                'Permitir prompts o parametros especializados.',
            ],
        ];
        $markdown = "# Propuesta evolutiva\n\nSolicitud: ".$message."\n\n";
        $markdown .= "Feature ID: ".$featureId."\n\n";
        $markdown .= "Backend:\n- ".implode("\n- ", $blueprint['backend_scope'])."\n\n";
        $markdown .= "Frontend:\n- ".implode("\n- ", $blueprint['frontend_scope'])."\n";
        $artifacts = [
            $this->files->writeTextOutput($jobId, 'propuesta_'.$featureId.'.md', $markdown),
            $this->files->writeTextOutput($jobId, 'propuesta_'.$featureId.'.json', json_encode($blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];

        return $this->toolResult(
            'solution_evolution',
            'Se genero una propuesta de ampliacion reutilizable para backend y frontend, con foco en despliegue PHP/Laravel.',
            [
                'Se analizo la solicitud en lenguaje natural.',
                'No se encontro una cobertura dominante suficiente para reutilizar un modulo actual.',
                'Se produjo un blueprint reutilizable para evolucionar la app.',
            ],
            $artifacts,
            [
                'analysis_mode' => 'propose_extension',
                'feature_id' => $featureId,
            ],
        );
    }

    protected function mergePdfs(string $jobId, array $pdfPaths): array
    {
        $pdf = new Fpdi();
        foreach ($pdfPaths as $path) {
            $pageCount = $pdf->setSourceFile($path);
            for ($page = 1; $page <= $pageCount; $page++) {
                $template = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($template);
                $pdf->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
                $pdf->useTemplate($template);
            }
        }

        $artifact = $this->files->writeBinaryOutput($jobId, 'pdf_combinado.pdf', $pdf->Output('S'));

        return $this->toolResult(
            'pdf_batch',
            'Se combinaron '.count($pdfPaths).' PDFs en un unico archivo.',
            [
                'Se cargo el lote de PDFs desde almacenamiento controlado.',
                'Se compuso un unico documento de salida sin iterar por LLM.',
                'Se genero el artefacto final para descarga.',
            ],
            [$artifact],
            ['operation' => 'merge', 'input_count' => count($pdfPaths)],
        );
    }

    protected function splitPages(string $jobId, array $pdfPaths): array
    {
        $artifacts = [];
        $outputCount = 0;

        foreach ($pdfPaths as $path) {
            $probe = new Fpdi();
            $pageCount = $probe->setSourceFile($path);
            for ($page = 1; $page <= $pageCount; $page++) {
                $pdf = new Fpdi();
                $pdf->setSourceFile($path);
                $template = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($template);
                $pdf->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
                $pdf->useTemplate($template);
                $artifacts[] = $this->files->writeBinaryOutput(
                    $jobId,
                    pathinfo($path, PATHINFO_FILENAME).'_pagina_'.$page.'.pdf',
                    $pdf->Output('S')
                );
                $outputCount++;
            }
        }

        return $this->toolResult(
            'pdf_batch',
            'Se separaron '.count($pdfPaths).' PDF(s) en '.$outputCount.' archivo(s) por pagina.',
            [
                'Se detecto una solicitud de particion pagina por pagina.',
                'Se genero un PDF independiente por cada pagina del lote.',
                'Se publicaron los artefactos listos para descarga y trazabilidad.',
            ],
            $artifacts,
            ['operation' => 'split_pages', 'input_count' => count($pdfPaths), 'output_count' => $outputCount],
        );
    }

    protected function extractPageRanges(string $jobId, array $pdfPaths, string $message): array
    {
        [$requestedStart, $requestedEnd] = $this->parsePageRange($message);
        $artifacts = [];

        foreach ($pdfPaths as $path) {
            $probe = new Fpdi();
            $totalPages = $probe->setSourceFile($path);
            [$startPage, $endPage] = $this->clampRange($requestedStart, $requestedEnd, $totalPages);
            $pdf = new Fpdi();
            $pdf->setSourceFile($path);
            for ($page = $startPage; $page <= $endPage; $page++) {
                $template = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($template);
                $pdf->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
                $pdf->useTemplate($template);
            }
            $artifacts[] = $this->files->writeBinaryOutput(
                $jobId,
                pathinfo($path, PATHINFO_FILENAME).'_paginas_'.$startPage.'_'.$endPage.'.pdf',
                $pdf->Output('S')
            );
        }

        return $this->toolResult(
            'pdf_batch',
            'Se extrajo el rango de paginas '.$requestedStart.' a '.$requestedEnd.' desde '.count($pdfPaths).' PDF(s).',
            [
                'Se detecto una solicitud de extraccion de paginas.',
                'Se copio el rango indicado en un documento independiente por cada PDF.',
                'Se guardaron los recortes listos para revision o envio.',
            ],
            $artifacts,
            [
                'operation' => 'extract_pages',
                'input_count' => count($pdfPaths),
                'page_start' => $requestedStart,
                'page_end' => $requestedEnd,
            ],
        );
    }

    protected function splitSections(string $jobId, array $pdfPaths, string $message): array
    {
        $artifacts = [];
        $resolvedRanges = [];

        foreach ($pdfPaths as $path) {
            $probe = new Fpdi();
            $totalPages = $probe->setSourceFile($path);
            $ranges = $this->resolveSectionRanges($message, $totalPages);
            if ($ranges === []) {
                return $this->toolResult(
                    'pdf_batch',
                    "Puedo dividir el PDF por secciones, pero necesito rangos claros como 1-5, 6-10 o una instruccion como 'en 3 secciones'.",
                    [
                        'Se detecto una solicitud de particion por secciones.',
                        'No se encontraron rangos ni cantidad de secciones suficientes para ejecutar el lote.',
                        'Se devolvio una guia de lenguaje natural para completar la solicitud.',
                    ],
                    [],
                    [
                        'operation' => 'split_sections_guidance',
                        'examples' => [
                            'Divide el PDF cargado en secciones 1-5, 6-10 y 11-15',
                            'Parte el PDF cargado en 3 secciones',
                        ],
                    ],
                );
            }

            $resolvedRanges = $ranges;
            foreach ($ranges as $index => [$startPage, $endPage]) {
                $pdf = new Fpdi();
                $pdf->setSourceFile($path);
                for ($page = $startPage; $page <= $endPage; $page++) {
                    $template = $pdf->importPage($page);
                    $size = $pdf->getTemplateSize($template);
                    $pdf->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
                    $pdf->useTemplate($template);
                }
                $artifacts[] = $this->files->writeBinaryOutput(
                    $jobId,
                    pathinfo($path, PATHINFO_FILENAME).'_seccion_'.($index + 1).'_'.$startPage.'_'.$endPage.'.pdf',
                    $pdf->Output('S')
                );
            }
        }

        return $this->toolResult(
            'pdf_batch',
            'Se dividieron '.count($pdfPaths).' PDF(s) en '.count($resolvedRanges).' secciones utilizando rangos o bloques operativos.',
            [
                'Se detecto una solicitud de particion por secciones.',
                'Se resolvieron los rangos de paginas a partir del lenguaje natural.',
                'Se generaron documentos independientes por seccion para su uso empresarial.',
            ],
            $artifacts,
            [
                'operation' => 'split_sections',
                'input_count' => count($pdfPaths),
                'sections' => array_map(fn (array $range) => ['start' => $range[0], 'end' => $range[1]], $resolvedRanges),
            ],
        );
    }

    protected function extractText(string $jobId, array $pdfPaths): array
    {
        $parser = new Parser();
        $chunks = [];

        foreach ($pdfPaths as $path) {
            $document = $parser->parseFile($path);
            $pages = $document->getPages();
            foreach ($pages as $index => $page) {
                $chunks[] = '## '.basename($path).' - pagina '.($index + 1)."\n".trim($page->getText());
            }
        }

        $artifact = $this->files->writeTextOutput($jobId, 'texto_extraido.txt', implode("\n\n", $chunks));

        return $this->toolResult(
            'pdf_batch',
            'Se extrajo texto de '.count($pdfPaths).' PDFs y se consolido en un TXT.',
            [
                'Se abrieron los documentos PDF del lote.',
                'Se extrajo el texto pagina por pagina.',
                'Se consolido el resultado en un solo archivo de texto.',
            ],
            [$artifact],
            ['operation' => 'extract_text', 'input_count' => count($pdfPaths)],
        );
    }

    protected function extractForModel(string $jobId, array $pdfPaths, string $message): array
    {
        $prompt = $this->extractModelInstruction($message);
        $markdown = $this->buildMarkdownContent($pdfPaths);
        $wasTruncated = mb_strlen($markdown) > self::MAX_MARKDOWN_CHARS;
        $markdownForModel = $wasTruncated
            ? mb_substr($markdown, 0, self::MAX_MARKDOWN_CHARS)."\n\n[... contenido truncado por limite de tamano de prompt ...]"
            : $markdown;

        $markdownArtifact = $this->files->writeTextOutput($jobId, 'pdf_para_modelo.md', $markdown);
        $promptArtifact = $this->files->writeTextOutput($jobId, 'prompt_usuario_para_ia.md', "# Prompt del usuario\n\n".$prompt."\n");
        $artifacts = [$markdownArtifact, $promptArtifact];
        $responseText = null;
        $responseMode = 'markdown_ready';
        $modelError = null;

        if ($this->isModelEnabled()) {
            [$responseText, $modelError] = $this->runModelOverMarkdown($jobId, $markdownForModel, $prompt);
            if ($responseText) {
                $responseMode = $wasTruncated ? 'ai_completed_truncated_input' : 'ai_completed';
                $artifacts[] = $this->files->writeTextOutput($jobId, 'respuesta_modelo.md', $responseText);
            } else {
                $responseMode = 'ai_failed_fallback';
            }
        }

        $summary = $responseText
            ?: 'Se extrajo el PDF a Markdown estructurado y se dejo listo para analisis por IA con el prompt del usuario. '
                .($this->isModelEnabled()
                    ? 'La respuesta del modelo no se pudo obtener: '.($modelError ?: 'error desconocido al llamar a Anthropic.')
                    : 'La respuesta del modelo no se ejecuto porque la IA no esta habilitada o no tiene credenciales configuradas.');

        return $this->toolResult(
            'pdf_batch',
            $summary,
            [
                'Se abrieron los PDFs cargados y se extrajo el texto pagina por pagina con PHP.',
                'Se convirtio el contenido a Markdown estructurado para facilitar el consumo por un modelo.',
                'Se preparo la instruccion del usuario para analisis asistido por IA.',
                $wasTruncated
                    ? 'El contenido supero el limite de tamano de prompt y se trunco antes de enviarlo al modelo.'
                    : 'Si la IA corporativa esta habilitada, se ejecuta el prompt sobre el Markdown; si no, se dejan los artefactos listos para el siguiente paso.',
            ],
            $artifacts,
            [
                'operation' => 'extract_for_model',
                'input_count' => count($pdfPaths),
                'prompt' => $prompt,
                'model_mode' => $responseMode,
                'markdown_artifact_id' => $markdownArtifact['id'],
                'markdown_truncated' => $wasTruncated,
                'markdown_char_count' => mb_strlen($markdown),
                'model_error' => $modelError,
            ],
        );
    }

    protected function watermark(string $jobId, array $pdfPaths): array
    {
        $artifacts = [];
        foreach ($pdfPaths as $path) {
            $probe = new Fpdi();
            $pageCount = $probe->setSourceFile($path);
            $pdf = new Fpdi();
            $pdf->setSourceFile($path);
            $pdf->setTextColor(120, 23, 52);
            $pdf->SetFont('Arial', 'B', 18);
            for ($page = 1; $page <= $pageCount; $page++) {
                $template = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($template);
                $pdf->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
                $pdf->useTemplate($template);
                $pdf->SetXY(20, max(20, $size['height'] - 22));
                $pdf->Cell(0, 10, 'Tema Litoclean - uso interno');
            }
            $artifacts[] = $this->files->writeBinaryOutput($jobId, pathinfo($path, PATHINFO_FILENAME).'_marca_agua.pdf', $pdf->Output('S'));
        }

        return $this->toolResult(
            'pdf_batch',
            'Se aplico marca de agua a '.count($pdfPaths).' PDFs.',
            [
                'Se detecto una solicitud de marca de agua.',
                'Se superpuso un sello textual sobre cada pagina del lote.',
                'Se publicaron los PDFs con watermark listos para descarga.',
            ],
            $artifacts,
            ['operation' => 'watermark_text', 'input_count' => count($pdfPaths)],
        );
    }

    protected function redactRegion(string $jobId, array $pdfPaths, string $message): array
    {
        [$rangeStart, $rangeEnd] = $this->parsePageRange($message);
        $region = $this->resolveRegion($message);
        $artifacts = [];

        foreach ($pdfPaths as $path) {
            $probe = new Fpdi();
            $pageCount = $probe->setSourceFile($path);
            [$startPage, $endPage] = $this->clampRange($rangeStart, $rangeEnd, $pageCount);
            $pdf = new Fpdi();
            $pdf->setSourceFile($path);
            for ($page = 1; $page <= $pageCount; $page++) {
                $template = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($template);
                $pdf->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
                $pdf->useTemplate($template);
                if ($page >= $startPage && $page <= $endPage) {
                    [$x, $y, $w, $h] = $this->regionDimensions($region, (float) $size['width'], (float) $size['height']);
                    $pdf->SetFillColor(255, 255, 255);
                    $pdf->Rect($x, $y, $w, $h, 'F');
                }
            }
            $artifacts[] = $this->files->writeBinaryOutput($jobId, pathinfo($path, PATHINFO_FILENAME).'_sin_folios.pdf', $pdf->Output('S'));
        }

        return $this->toolResult(
            'pdf_batch',
            "Se genero ".count($artifacts)." PDF limpio cubriendo la zona '".$region."' entre las paginas ".$rangeStart.' y '.$rangeEnd.'.',
            [
                'Se detecto una solicitud para cubrir folios o numeracion repetida.',
                'Se determino la zona visual mas probable a partir del lenguaje natural.',
                'Se aplico un cubrimiento en las paginas indicadas y se guardo un PDF limpio.',
            ],
            $artifacts,
            [
                'operation' => 'redact_region',
                'page_start' => $rangeStart,
                'page_end' => $rangeEnd,
                'region' => $region,
            ],
        );
    }

    protected function buildMarkdownContent(array $pdfPaths): string
    {
        $parser = new Parser();
        $blocks = [];
        foreach ($pdfPaths as $path) {
            $document = $parser->parseFile($path);
            $pages = $document->getPages();
            $blocks[] = '# '.basename($path);
            foreach ($pages as $index => $page) {
                $blocks[] = '## Pagina '.($index + 1)."\n\n".trim($page->getText());
            }
        }

        return implode("\n\n", $blocks);
    }

    /**
     * Calls the Anthropic Messages API with bounded retries for transient failures.
     *
     * @return array{0: ?string, 1: ?string} [responseText, errorMessage]
     */
    protected function runModelOverMarkdown(string $jobId, string $markdown, string $prompt): array
    {
        $content = "Documento en Markdown:\n\n".$markdown."\n\nSolicitud del usuario:\n".$prompt;

        return $this->anthropic->complete($content, self::ANTHROPIC_MAX_TOKENS, $jobId);
    }

    protected function writeAudit(string $jobId, string $tool, string $message, array $uploadedFileIds, array $response): void
    {
        $path = $this->appSettings->auditRootPath();
        File::ensureDirectoryExists($path);
        File::put(
            $path.DIRECTORY_SEPARATOR.$jobId.'.json',
            json_encode([
                'job_id' => $jobId,
                'tool' => $tool,
                'message' => $message,
                'uploaded_file_ids' => array_values($uploadedFileIds),
                'response' => $response,
                'generated_at' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    protected function routeMessage(string $message): string
    {
        $text = $this->normalizeText($message);
        if ($this->containsAny($text, ['modulo', 'modulos', 'funcionalidad', 'funcionalidades', 'implementar', 'backend', 'frontend', 'evolucion', 'requerimiento', 'si no existe', 'usa lo ya creado'])) {
            return 'solution_evolution';
        }
        // Premium environmental pillars take priority over generic office/data routing.
        if ($this->containsAny($text, ['eca', 'lmp', 'umbral', 'umbrales', 'excedencia', 'excedencias', 'cumple norma', 'limite maximo permisible', 'estandar de calidad'])) {
            return 'env_monitoring_compare';
        }
        if ($this->containsAny($text, ['consolida', 'consolidar', 'consolidacion', 'consolidado', 'unir excels', 'unir excel', 'juntar excels', 'combinar excels'])) {
            return 'excel_consolidation';
        }
        if ($this->containsAny($text, ['informe', 'reporte profesional', 'reporte ambiental'])
            && $this->containsAny($text, ['ambiental', 'monitoreo', 'trimestral', 'oefa', 'eca', 'lmp', 'cliente', 'consultora'])) {
            return 'report_generator';
        }
        if ($this->containsAny($text, ['pdf', 'lee el pdf', 'leer el pdf', 'analiza el pdf', 'resume el pdf', 'interpreta el pdf', 'combina', 'combinar', 'unir', 'parte', 'partir', 'divide', 'dividir', 'separa', 'secciones', 'pagina por pagina', 'paginas', 'extrae texto', 'extrae paginas', 'markdown', 'modelo', 'prompt del usuario', 'marca de agua', 'folio', 'folios', 'numeracion', 'borrar texto', 'eliminar texto'])) {
            return 'pdf_batch';
        }
        if ($this->containsAny($text, ['formulario', 'captura', 'registro', 'valida', 'validar'])) {
            return 'forms';
        }
        if ($this->containsAny($text, ['ppt', 'presentacion', 'powerpoint', 'word', 'docx', 'excel', 'xlsx', 'reporte', 'informe'])) {
            return 'office';
        }
        if ($this->containsAny($text, ['imagen', 'afiche', 'banner', 'video', 'campana'])) {
            return 'media_gen';
        }
        if ($this->containsAny($text, ['tabla', 'api', 'dato', 'datos', 'cliente', 'servicio', 'proyecto'])) {
            return 'corporate_data';
        }

        return 'solution_evolution';
    }

    protected function toolResult(string $toolName, string $summary, array $steps, array $artifacts = [], array $data = []): array
    {
        return [
            'tool_name' => $toolName,
            'summary' => $summary,
            'steps' => $steps,
            'artifacts' => $artifacts,
            'data' => $data,
        ];
    }

    protected function normalizeText(?string $value): string
    {
        $text = Str::lower((string) $value);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        return $ascii !== false ? $ascii : $text;
    }

    protected function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected function isModelReadRequest(string $text): bool
    {
        return $this->containsAny($text, ['lee el pdf', 'leer el pdf', 'analiza el pdf', 'resume el pdf', 'markdown', 'modelo', 'prompt', 'responde:']);
    }

    protected function isPageSplitRequest(string $text): bool
    {
        return $this->containsAny($text, ['pagina por pagina', 'paginas individuales', 'partir en paginas', 'separar paginas']);
    }

    protected function isSectionSplitRequest(string $text): bool
    {
        return str_contains($text, 'seccion') || preg_match('/en\s+\d+\s+secciones?/', $text) === 1;
    }

    protected function isPageExtractRequest(string $text): bool
    {
        return str_contains($text, 'extrae') && str_contains($text, 'pagina');
    }

    protected function isRedactionRequest(string $text): bool
    {
        return $this->containsAny($text, ['quita', 'quitar', 'elimina', 'eliminar', 'borra', 'borrar'])
            && $this->containsAny($text, ['folio', 'folios', 'texto', 'numeracion']);
    }

    protected function parsePageRange(string $message): array
    {
        if (preg_match('/(\d+)\s*(?:-|a)\s*(\d+)/', $message, $matches) === 1) {
            $start = (int) $matches[1];
            $end = (int) $matches[2];

            return [$start, max($start, $end)];
        }

        if (preg_match('/pagina\s+(\d+)/', $message, $matches) === 1) {
            $page = (int) $matches[1];

            return [$page, $page];
        }

        return [1, 1];
    }

    protected function clampRange(int $start, int $end, int $totalPages): array
    {
        $start = max(1, min($start, $totalPages));
        $end = max($start, min($end, $totalPages));

        return [$start, $end];
    }

    protected function resolveSectionRanges(string $message, int $totalPages): array
    {
        preg_match_all('/(\d+)\s*-\s*(\d+)/', $message, $matches, PREG_SET_ORDER);
        if ($matches !== []) {
            return array_map(fn (array $match) => $this->clampRange((int) $match[1], (int) $match[2], $totalPages), $matches);
        }

        if (preg_match('/en\s+(\d+)\s+secciones?/', $message, $match) === 1) {
            $sectionCount = max(1, (int) $match[1]);
            $ranges = [];
            $size = (int) ceil($totalPages / $sectionCount);
            $start = 1;
            while ($start <= $totalPages) {
                $end = min($totalPages, $start + $size - 1);
                $ranges[] = [$start, $end];
                $start = $end + 1;
            }

            return $ranges;
        }

        return [];
    }

    protected function resolveRegion(string $message): string
    {
        if (str_contains($message, 'superior izquierda')) {
            return 'superior izquierda';
        }
        if (str_contains($message, 'superior derecha')) {
            return 'superior derecha';
        }
        if (str_contains($message, 'inferior izquierda')) {
            return 'inferior izquierda';
        }

        return 'inferior derecha';
    }

    protected function regionDimensions(string $region, float $width, float $height): array
    {
        $boxWidth = $width * 0.22;
        $boxHeight = $height * 0.08;

        return match ($region) {
            'superior izquierda' => [0.0, 0.0, $boxWidth, $boxHeight],
            'superior derecha' => [$width - $boxWidth, 0.0, $boxWidth, $boxHeight],
            'inferior izquierda' => [0.0, $height - $boxHeight, $boxWidth, $boxHeight],
            default => [$width - $boxWidth, $height - $boxHeight, $boxWidth, $boxHeight],
        };
    }

    protected function extractModelInstruction(string $message): string
    {
        if (preg_match('/responde:\s*(.+)$/i', $message, $matches) === 1) {
            return trim($matches[1]);
        }

        return trim($message);
    }

    protected function isModelEnabled(): bool
    {
        return $this->anthropic->isEnabled();
    }

    /**
     * Predefined deterministic operations ("recetas") the user can invoke by intent.
     * Surfaced to the frontend as premium cards.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recipes(): array
    {
        return [
            [
                'id' => 'consolidar_monitoreo',
                'title' => 'Consolidar Excels de monitoreo',
                'tool' => 'excel_consolidation',
                'description' => 'Une multiples .xlsx/.csv de monitoreo o laboratorio en un solo libro con resumen y deduplicacion opcional.',
                'accepts_files' => true,
                'prompt' => 'Consolida y deduplica los Excel de monitoreo cargados',
            ],
            [
                'id' => 'comparar_eca_agua',
                'title' => 'Comparar resultados contra ECA agua',
                'tool' => 'env_monitoring_compare',
                'description' => 'Cruza las mediciones cargadas contra umbrales ECA/LMP referenciales y marca CUMPLE/EXCEDE/SIN_UMBRAL.',
                'accepts_files' => true,
                'prompt' => 'Compara los resultados de agua cargados contra los umbrales ECA',
            ],
            [
                'id' => 'informe_trimestral',
                'title' => 'Generar informe trimestral OEFA',
                'tool' => 'report_generator',
                'description' => 'Produce un informe ambiental DOCX profesional con datos consolidados y narrativa (IA solo si esta habilitada).',
                'accepts_files' => true,
                'prompt' => 'Genera un informe trimestral de monitoreo ambiental para el cliente con los datos cargados',
            ],
        ];
    }

    protected function selectCorporateTable(string $message): string
    {
        $text = $this->normalizeText($message);
        if (str_contains($text, 'cliente')) {
            return 'clients';
        }
        if (str_contains($text, 'proyecto') || str_contains($text, 'remediacion')) {
            return 'projects';
        }

        return 'services';
    }

    protected function extractCorporateKeyword(string $message): ?string
    {
        $text = $this->normalizeText($message);
        if (preg_match('/"([^"]+)"/', $text, $matches) === 1) {
            return Str::lower($matches[1]);
        }

        foreach (['mineria', 'residuos', 'agua', 'soil', 'industrial'] as $keyword) {
            if (str_contains($text, $keyword)) {
                return $keyword;
            }
        }

        return null;
    }

    protected function deriveOfficeTitle(string $message): string
    {
        $cleaned = trim(str_replace(':', ' ', $message));
        $tokens = preg_split('/\s+/', $cleaned) ?: [];
        if (count($tokens) <= 2) {
            return 'Entregable Tema Litoclean';
        }

        return Str::title(implode(' ', array_slice($tokens, 0, 8)));
    }

    protected function loadCorporateDataset(): array
    {
        $datasetPath = base_path('database/seed-data/mock_corporate_data.json');
        if (! is_file($datasetPath)) {
            return [
                'clients' => [],
                'services' => [],
                'projects' => [],
            ];
        }

        $decoded = json_decode((string) File::get($datasetPath), true);

        return is_array($decoded)
            ? $decoded
            : [
                'clients' => [],
                'services' => [],
                'projects' => [],
            ];
    }
}
