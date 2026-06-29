<?php

namespace App\Services;

use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * Generates a professional environmental-consultancy report (DOCX).
 *
 * Structure and tables are 100% deterministic PHP. Narrative sections (executive
 * summary, results interpretation, conclusions) are written by Claude ONLY when AI
 * is enabled; otherwise deterministic templates fill them in from the same data.
 */
class ReportGeneratorService
{
    public function __construct(
        protected AnthropicClient $anthropic,
        protected AppSettingsService $appSettings,
        protected FileArtifactService $files,
    ) {
    }

    /**
     * @param  array<string, mixed>  $context  Optional processed data: ['title','client','rows','exceedances', ...]
     * @return array{
     *     steps: array<int, string>,
     *     artifacts: array<int, array<string, mixed>>,
     *     data: array<string, mixed>
     * }
     */
    public function generate(string $jobId, string $message, array $context = []): array
    {
        $settings = $this->appSettings->ensureDefaults();
        $title = $context['title'] ?? $this->deriveTitle($message);
        $client = $context['client'] ?? 'Cliente';
        $dataRows = is_array($context['rows'] ?? null) ? $context['rows'] : [];
        $exceedances = is_array($context['exceedances'] ?? null) ? $context['exceedances'] : [];

        $steps = ['Se compuso la estructura del informe (portada, indice, secciones).'];

        [$narrative, $narrativeMode] = $this->buildNarrative($jobId, $title, $client, $dataRows, $exceedances);
        $steps[] = $narrativeMode === 'ai'
            ? 'Las secciones narrativas se redactaron con IA sobre los datos ya procesados.'
            : 'Las secciones narrativas se generaron con plantillas deterministas (IA deshabilitada o no disponible).';

        $document = new PhpWord();
        $document->getSettings()->setUpdateFields(true);
        $this->addStyles($document);

        $section = $document->addSection();

        // Portada
        $section->addText($settings->company_name, ['bold' => true, 'size' => 28, 'color' => '7c1734'], ['alignment' => Jc::CENTER, 'spaceAfter' => 240]);
        $section->addText($title, ['bold' => true, 'size' => 22], ['alignment' => Jc::CENTER, 'spaceAfter' => 120]);
        $section->addText('Informe tecnico ambiental', ['size' => 14, 'color' => '555555'], ['alignment' => Jc::CENTER]);
        $section->addText('Cliente: '.$client, ['size' => 12], ['alignment' => Jc::CENTER]);
        $section->addText('Fecha: '.now()->format('d/m/Y'), ['size' => 12], ['alignment' => Jc::CENTER, 'spaceAfter' => 240]);
        $section->addPageBreak();

        // Indice
        $section->addTitle('Indice', 1);
        $section->addTOC(['size' => 11]);
        $section->addPageBreak();

        // Secciones
        $section->addTitle('1. Introduccion', 1);
        $section->addText($narrative['introduccion']);

        $section->addTitle('2. Resumen ejecutivo', 1);
        $section->addText($narrative['resumen_ejecutivo']);

        $section->addTitle('3. Metodologia', 1);
        $section->addText($narrative['metodologia']);

        $section->addTitle('4. Resultados', 1);
        $section->addText($narrative['resultados']);
        if ($dataRows !== []) {
            $this->addDataTable($section, $dataRows);
        }
        if ($exceedances !== []) {
            $section->addTextBreak(1);
            $section->addText('Excedencias detectadas:', ['bold' => true]);
            $this->addExceedanceTable($section, $exceedances);
        }

        $section->addTitle('5. Conclusiones', 1);
        $section->addText($narrative['conclusiones']);

        $section->addTitle('6. Recomendaciones', 1);
        $section->addText($narrative['recomendaciones']);

        $path = $this->files->prepareOutputPath($jobId, 'informe_ambiental.docx');
        WordIOFactory::createWriter($document, 'Word2007')->save($path);
        $artifact = $this->files->buildArtifact($path, 'informe_ambiental.docx');

        $steps[] = 'Se exporto el informe profesional en formato DOCX listo para el cliente.';

        return [
            'steps' => $steps,
            'artifacts' => [$artifact],
            'data' => [
                'operation' => 'report_generator',
                'status' => 'ok',
                'title' => $title,
                'narrative_mode' => $narrativeMode,
                'data_rows' => count($dataRows),
                'exceedances' => count($exceedances),
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $dataRows
     * @param  array<int, array<string, mixed>>  $exceedances
     * @return array{0: array<string, string>, 1: string}
     */
    protected function buildNarrative(string $jobId, string $title, string $client, array $dataRows, array $exceedances): array
    {
        $deterministic = $this->deterministicNarrative($title, $client, $dataRows, $exceedances);

        if (! $this->anthropic->isEnabled()) {
            return [$deterministic, 'deterministic'];
        }

        $facts = json_encode([
            'titulo' => $title,
            'cliente' => $client,
            'total_filas_datos' => count($dataRows),
            'total_excedencias' => count($exceedances),
            'muestra_excedencias' => array_slice($exceedances, 0, 10),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $prompt = "Eres un consultor ambiental senior en Peru. Redacta UNICAMENTE las secciones narrativas de un informe ".
            "tecnico, en espanol formal, basandote estrictamente en estos datos ya procesados (no inventes cifras):\n\n".
            $facts."\n\n".
            "Devuelve un JSON valido con exactamente estas claves de texto (parrafos, sin markdown): ".
            "introduccion, resumen_ejecutivo, metodologia, resultados, conclusiones, recomendaciones. ".
            "Si mencionas umbrales, aclara que son referenciales y deben validarse contra la norma vigente.";

        [$text, $error] = $this->anthropic->complete($prompt, 1800, $jobId);
        if ($text === null) {
            return [$deterministic, 'deterministic'];
        }

        $parsed = $this->parseNarrativeJson($text);
        if ($parsed === null) {
            return [$deterministic, 'deterministic'];
        }

        // Merge: AI text where present, deterministic fallback per missing key.
        $merged = $deterministic;
        foreach ($merged as $key => $value) {
            if (! empty($parsed[$key]) && is_string($parsed[$key])) {
                $merged[$key] = trim($parsed[$key]);
            }
        }

        return [$merged, 'ai'];
    }

    /**
     * @return array<string, string>|null
     */
    protected function parseNarrativeJson(string $text): ?array
    {
        if (preg_match('/\{.*\}/s', $text, $matches) !== 1) {
            return null;
        }
        $decoded = json_decode($matches[0], true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $dataRows
     * @param  array<int, array<string, mixed>>  $exceedances
     * @return array<string, string>
     */
    protected function deterministicNarrative(string $title, string $client, array $dataRows, array $exceedances): array
    {
        $exceedCount = count($exceedances);
        $estado = $exceedCount === 0
            ? 'No se identificaron excedencias respecto de los umbrales referenciales evaluados.'
            : 'Se identificaron '.$exceedCount.' excedencia(s) respecto de los umbrales referenciales evaluados.';

        return [
            'introduccion' => 'El presente informe corresponde a "'.$title.'", elaborado para '.$client.'. '.
                'Documenta los resultados del monitoreo ambiental y su comparacion contra estandares de referencia.',
            'resumen_ejecutivo' => 'Se procesaron '.count($dataRows).' registro(s) de monitoreo. '.$estado.' '.
                'Los hallazgos se detallan en la seccion de resultados.',
            'metodologia' => 'La consolidacion de datos y la comparacion contra umbrales se realizaron de forma '.
                'deterministica. Los valores de referencia (ECA/LMP) utilizados son REFERENCIALES y deben validarse '.
                'contra la norma vigente (Decreto Supremo aplicable) antes de cualquier uso oficial.',
            'resultados' => 'A continuacion se presentan los resultados consolidados del monitoreo. '.$estado,
            'conclusiones' => $exceedCount === 0
                ? 'Con base en los datos evaluados, los parametros analizados se mantienen dentro de los umbrales referenciales.'
                : 'Con base en los datos evaluados, se requieren acciones correctivas sobre los parametros que exceden los umbrales referenciales.',
            'recomendaciones' => 'Se recomienda validar los umbrales contra la normativa vigente, mantener el monitoreo '.
                'periodico y documentar las acciones de mejora. '.($exceedCount > 0 ? 'Atender de forma prioritaria las excedencias detectadas.' : ''),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function addDataTable($section, array $rows): void
    {
        $rows = array_slice($rows, 0, 100);
        $headers = array_keys((array) $rows[0]);
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'cccccc', 'cellMargin' => 60]);

        $table->addRow();
        foreach ($headers as $header) {
            $table->addCell(2500, ['bgColor' => '7c1734'])->addText((string) $header, ['bold' => true, 'color' => 'ffffff']);
        }
        foreach ($rows as $row) {
            $table->addRow();
            foreach ($headers as $header) {
                $table->addCell(2500)->addText((string) (((array) $row)[$header] ?? ''));
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $exceedances
     */
    protected function addExceedanceTable($section, array $exceedances): void
    {
        $exceedances = array_slice($exceedances, 0, 100);
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'cccccc', 'cellMargin' => 60]);
        $table->addRow();
        foreach (['Parametro', 'Valor', 'Umbral', 'Unidad'] as $header) {
            $table->addCell(2500, ['bgColor' => 'b03050'])->addText($header, ['bold' => true, 'color' => 'ffffff']);
        }
        foreach ($exceedances as $row) {
            $table->addRow();
            $table->addCell(2500)->addText((string) ($row['parametro'] ?? ''));
            $table->addCell(2500)->addText((string) ($row['valor_medido'] ?? ''));
            $table->addCell(2500)->addText((string) ($row['umbral'] ?? ''));
            $table->addCell(2500)->addText((string) ($row['unidad'] ?? ''));
        }
    }

    protected function addStyles(PhpWord $document): void
    {
        $document->addTitleStyle(1, ['bold' => true, 'size' => 16, 'color' => '7c1734'], ['spaceBefore' => 240, 'spaceAfter' => 120]);
        $document->addTitleStyle(2, ['bold' => true, 'size' => 13, 'color' => '333333'], ['spaceBefore' => 120, 'spaceAfter' => 80]);
    }

    protected function deriveTitle(string $message): string
    {
        $cleaned = trim(str_replace(':', ' ', $message));
        $tokens = preg_split('/\s+/', $cleaned) ?: [];
        if (count($tokens) <= 2) {
            return 'Informe ambiental Tema Litoclean';
        }

        return \Illuminate\Support\Str::title(implode(' ', array_slice($tokens, 0, 10)));
    }
}
