<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Deterministic comparison of monitoring measurements against referential
 * environmental thresholds (ECA / LMP). No AI involved.
 *
 * IMPORTANT: thresholds shipped in environmental_thresholds.json are REFERENTIAL
 * and must be validated against the norm in force (specific DS). The output always
 * carries that legal notice.
 */
class EnvironmentalThresholdService
{
    public const STATUS_OK = 'CUMPLE';

    public const STATUS_EXCEEDS = 'EXCEDE';

    public const STATUS_NO_THRESHOLD = 'SIN_UMBRAL';

    public function __construct(
        protected ExcelConsolidationService $consolidation,
        protected FileArtifactService $files,
    ) {
    }

    /**
     * Loads the referential thresholds catalog.
     *
     * @return array<string, mixed>
     */
    public function loadThresholds(): array
    {
        $path = base_path('database/seed-data/environmental_thresholds.json');
        if (! is_file($path)) {
            return ['nota_legal' => '', 'categorias' => []];
        }

        $decoded = json_decode((string) File::get($path), true);

        return is_array($decoded) ? $decoded : ['nota_legal' => '', 'categorias' => []];
    }

    /**
     * Flattens all parameters across categories into a lookup keyed by normalized name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function flattenParameters(): array
    {
        $catalog = $this->loadThresholds();
        $lookup = [];
        foreach (($catalog['categorias'] ?? []) as $categoryKey => $category) {
            foreach (($category['parametros'] ?? []) as $paramKey => $definition) {
                $definition['categoria'] = $categoryKey;
                $definition['fuente'] = $category['fuente'] ?? '';
                $lookup[$this->normalizeKey($paramKey)] = $definition;
            }
        }

        return $lookup;
    }

    /**
     * Compares a tabular monitoring file (parameter + measured value) against thresholds.
     *
     * Expected layout: a column whose header matches a parameter name (e.g. "pH", "Plomo")
     * with measured values, OR a long format with "parametro"/"valor" columns.
     *
     * @return array{
     *     steps: array<int, string>,
     *     artifacts: array<int, array<string, mixed>>,
     *     data: array<string, mixed>
     * }
     */
    public function compareFile(string $jobId, string $path, string $displayName): array
    {
        $catalog = $this->loadThresholds();
        $notaLegal = (string) ($catalog['nota_legal'] ?? '');
        [$headers, $rows] = $this->consolidation->readTabular($path);

        if ($headers === []) {
            return [
                'steps' => ['El archivo "'.$displayName.'" no tiene encabezados detectables; no se pudo comparar.'],
                'artifacts' => [],
                'data' => ['operation' => 'env_monitoring_compare', 'status' => 'sin_encabezados'],
            ];
        }

        $lookup = $this->flattenParameters();
        $evaluations = $this->extractEvaluations($headers, $rows, $lookup);

        $exceedances = array_values(array_filter($evaluations, fn ($e) => $e['estado'] === self::STATUS_EXCEEDS));

        $artifact = $this->writeReport($jobId, $evaluations, $exceedances, $notaLegal, $displayName);

        $steps = [
            'Se leyo "'.$displayName.'" con '.count($headers).' columna(s) y '.count($rows).' fila(s).',
            'Se cruzaron las mediciones contra '.count($lookup).' parametro(s) referenciales (agua/aire/ruido).',
            'Se evaluaron '.count($evaluations).' medicion(es): '.count($exceedances).' excedencia(s) detectada(s).',
            'Se genero un reporte Excel con estado CUMPLE/EXCEDE/SIN_UMBRAL por medicion.',
            'AVISO: los umbrales son REFERENCIALES; valide contra la norma vigente (DS aplicable) antes de uso oficial.',
        ];

        return [
            'steps' => $steps,
            'artifacts' => [$artifact],
            'data' => [
                'operation' => 'env_monitoring_compare',
                'status' => 'ok',
                'measurements' => count($evaluations),
                'exceedances' => count($exceedances),
                'nota_legal' => $notaLegal,
                'evaluations' => array_slice($evaluations, 0, 100),
            ],
        ];
    }

    /**
     * Builds a normalized list of evaluations from either wide or long layouts.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<string, array<string, mixed>>  $lookup
     * @return array<int, array<string, mixed>>
     */
    protected function extractEvaluations(array $headers, array $rows, array $lookup): array
    {
        $normalizedHeaders = array_map(fn ($h) => $this->normalizeKey($h), $headers);

        // Long format detection: parametro + valor columns.
        $paramCol = $this->findColumn($normalizedHeaders, ['parametro', 'parametros', 'analito', 'variable']);
        $valueCol = $this->findColumn($normalizedHeaders, ['valor', 'valor_medido', 'resultado', 'medicion', 'concentracion']);

        $evaluations = [];

        if ($paramCol !== null && $valueCol !== null) {
            foreach ($rows as $row) {
                $paramName = (string) ($row[$paramCol] ?? '');
                $value = $row[$valueCol] ?? null;
                if (trim($paramName) === '' || ! is_numeric($value)) {
                    continue;
                }
                $evaluations[] = $this->evaluate($paramName, (float) $value, $lookup);
            }

            return $evaluations;
        }

        // Wide format: each header that matches a known parameter is a measured column.
        foreach ($normalizedHeaders as $index => $normHeader) {
            if (! isset($lookup[$normHeader])) {
                continue;
            }
            foreach ($rows as $row) {
                $value = $row[$index] ?? null;
                if (! is_numeric($value)) {
                    continue;
                }
                $evaluations[] = $this->evaluate($headers[$index], (float) $value, $lookup);
            }
        }

        return $evaluations;
    }

    /**
     * @param  array<string, array<string, mixed>>  $lookup
     * @return array<string, mixed>
     */
    protected function evaluate(string $rawName, float $value, array $lookup): array
    {
        $key = $this->normalizeKey($rawName);
        $definition = $lookup[$key] ?? null;

        if ($definition === null) {
            return [
                'parametro' => $rawName,
                'valor_medido' => $value,
                'unidad' => '',
                'umbral' => null,
                'estado' => self::STATUS_NO_THRESHOLD,
                'categoria' => '',
                'fuente' => '',
            ];
        }

        $tipo = $definition['tipo'] ?? 'maximo';
        $exceeds = false;
        $thresholdLabel = '';

        if ($tipo === 'rango') {
            $min = (float) ($definition['min'] ?? -INF);
            $max = (float) ($definition['max'] ?? INF);
            $exceeds = $value < $min || $value > $max;
            $thresholdLabel = $min.' - '.$max;
        } elseif ($tipo === 'minimo') {
            $min = (float) ($definition['min'] ?? 0);
            $exceeds = $value < $min;
            $thresholdLabel = '>= '.$min;
        } else { // maximo
            $max = (float) ($definition['max'] ?? INF);
            $exceeds = $value > $max;
            $thresholdLabel = '<= '.$max;
        }

        return [
            'parametro' => $rawName,
            'valor_medido' => $value,
            'unidad' => (string) ($definition['unidad'] ?? ''),
            'umbral' => $thresholdLabel,
            'estado' => $exceeds ? self::STATUS_EXCEEDS : self::STATUS_OK,
            'categoria' => (string) ($definition['categoria'] ?? ''),
            'fuente' => (string) ($definition['fuente'] ?? ''),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $evaluations
     * @param  array<int, array<string, mixed>>  $exceedances
     * @return array<string, mixed>
     */
    protected function writeReport(string $jobId, array $evaluations, array $exceedances, string $notaLegal, string $displayName): array
    {
        $spreadsheet = new Spreadsheet();

        $detail = $spreadsheet->getActiveSheet();
        $detail->setTitle('Comparacion');
        $detail->fromArray(
            ['Parametro', 'Valor medido', 'Unidad', 'Umbral referencial', 'Estado', 'Categoria', 'Fuente'],
            null,
            'A1'
        );
        $detailRows = array_map(fn ($e) => [
            $e['parametro'],
            $e['valor_medido'],
            $e['unidad'],
            $e['umbral'] ?? 'N/D',
            $e['estado'],
            $e['categoria'],
            $e['fuente'],
        ], $evaluations);
        if ($detailRows !== []) {
            $detail->fromArray($detailRows, null, 'A2');
        }

        $summary = $spreadsheet->createSheet();
        $summary->setTitle('Resumen');
        $summary->fromArray([
            ['Archivo evaluado', $displayName],
            ['Mediciones evaluadas', count($evaluations)],
            ['Excedencias (EXCEDE)', count($exceedances)],
            ['Cumple (CUMPLE)', count(array_filter($evaluations, fn ($e) => $e['estado'] === self::STATUS_OK))],
            ['Sin umbral (SIN_UMBRAL)', count(array_filter($evaluations, fn ($e) => $e['estado'] === self::STATUS_NO_THRESHOLD))],
            ['', ''],
            ['NOTA LEGAL', $notaLegal],
            ['', ''],
            ['Detalle de excedencias', ''],
            ['Parametro', 'Valor medido', 'Umbral', 'Unidad'],
        ], null, 'A1');
        $exceedRows = array_map(fn ($e) => [$e['parametro'], $e['valor_medido'], $e['umbral'] ?? 'N/D', $e['unidad']], $exceedances);
        if ($exceedRows !== []) {
            $summary->fromArray($exceedRows, null, 'A11');
        }

        $path = $this->files->prepareOutputPath($jobId, 'comparacion_umbrales.xlsx');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $this->files->buildArtifact($path, 'comparacion_umbrales.xlsx');
    }

    /**
     * @param  array<int, string>  $normalizedHeaders
     * @param  array<int, string>  $candidates
     */
    protected function findColumn(array $normalizedHeaders, array $candidates): ?int
    {
        foreach ($normalizedHeaders as $index => $header) {
            if (in_array($header, $candidates, true)) {
                return $index;
            }
        }

        return null;
    }

    protected function normalizeKey(string $value): string
    {
        $lower = Str::lower(trim($value));
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
        $ascii = $ascii !== false ? $ascii : $lower;
        // Collapse separators to underscores; keep dots (pm2.5).
        $ascii = preg_replace('/[\s\-]+/', '_', $ascii) ?? $ascii;
        $ascii = preg_replace('/[^a-z0-9._]/', '', $ascii) ?? $ascii;

        return trim($ascii, '_');
    }
}
