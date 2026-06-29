<?php

namespace App\Services;

use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Deterministic consolidation of multiple .xlsx/.csv files into a single workbook.
 *
 * No AI is used here: detection of headers, union of rows, dedup and numeric
 * aggregation are all plain PHP. Defensive caps keep memory bounded on shared hosting.
 */
class ExcelConsolidationService
{
    /** Maximum input files processed per request. */
    public const MAX_FILES = 30;

    /** Maximum data rows read per input file (excluding the header row). */
    public const MAX_ROWS_PER_FILE = 20000;

    /** Maximum columns read per file. */
    public const MAX_COLUMNS = 200;

    public function __construct(
        protected FileArtifactService $files,
    ) {
    }

    /**
     * @param  array<int, array{path: string, name: string}>  $inputs
     * @return array{
     *     steps: array<int, string>,
     *     artifacts: array<int, array<string, mixed>>,
     *     data: array<string, mixed>
     * }
     */
    public function consolidate(string $jobId, array $inputs, bool $deduplicate = false): array
    {
        $steps = [];
        $inputs = array_slice($inputs, 0, self::MAX_FILES);
        if (count($inputs) === 0) {
            return [
                'steps' => ['No se recibieron archivos .xlsx o .csv validos para consolidar.'],
                'artifacts' => [],
                'data' => ['operation' => 'excel_consolidation', 'status' => 'sin_entradas'],
            ];
        }

        $allHeaders = [];
        $fileSummaries = [];
        $perFileRows = [];
        $skipped = [];

        foreach ($inputs as $input) {
            try {
                [$headers, $rows] = $this->readTabular($input['path']);
            } catch (Throwable $exception) {
                $skipped[] = $input['name'];
                $steps[] = 'No se pudo leer "'.$input['name'].'": '.$exception->getMessage();
                continue;
            }

            if ($headers === []) {
                $skipped[] = $input['name'];
                $steps[] = 'El archivo "'.$input['name'].'" no tiene encabezados detectables; se omitio del consolidado.';
                continue;
            }

            foreach ($headers as $header) {
                if (! in_array($header, $allHeaders, true)) {
                    $allHeaders[] = $header;
                }
            }

            $perFileRows[] = ['name' => $input['name'], 'headers' => $headers, 'rows' => $rows];
            $fileSummaries[$input['name']] = count($rows);
            $steps[] = 'Se leyo "'.$input['name'].'": '.count($rows).' fila(s) y '.count($headers).' columna(s).';
        }

        if ($perFileRows === []) {
            return [
                'steps' => array_merge($steps, ['Ningun archivo aporto datos consolidables.']),
                'artifacts' => [],
                'data' => [
                    'operation' => 'excel_consolidation',
                    'status' => 'sin_datos',
                    'skipped_files' => $skipped,
                ],
            ];
        }

        // Build the consolidated rows aligned to the union of headers.
        $consolidatedHeaders = array_merge($allHeaders, ['origen_archivo']);
        $consolidatedRows = [];
        $seenHashes = [];
        $duplicatesRemoved = 0;

        foreach ($perFileRows as $file) {
            $headerIndex = array_flip($file['headers']);
            foreach ($file['rows'] as $row) {
                $aligned = [];
                foreach ($allHeaders as $header) {
                    $aligned[] = isset($headerIndex[$header]) ? ($row[$headerIndex[$header]] ?? null) : null;
                }

                if ($deduplicate) {
                    $hash = md5(json_encode($aligned, JSON_UNESCAPED_UNICODE) ?: '');
                    if (isset($seenHashes[$hash])) {
                        $duplicatesRemoved++;
                        continue;
                    }
                    $seenHashes[$hash] = true;
                }

                $aligned[] = $file['name'];
                $consolidatedRows[] = $aligned;
            }
        }

        $numericStats = $this->computeNumericStats($allHeaders, $consolidatedRows);

        $spreadsheet = new Spreadsheet();
        $this->writeConsolidatedSheet($spreadsheet, $consolidatedHeaders, $consolidatedRows);
        $this->writeSummarySheet($spreadsheet, $fileSummaries, $numericStats, $duplicatesRemoved, $skipped);

        $path = $this->files->prepareOutputPath($jobId, 'consolidado.xlsx');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        $artifact = $this->files->buildArtifact($path, 'consolidado.xlsx');

        $steps[] = 'Se unieron '.count($consolidatedRows).' fila(s) bajo '.count($allHeaders).' encabezado(s) comun(es).';
        if ($deduplicate) {
            $steps[] = 'Se eliminaron '.$duplicatesRemoved.' fila(s) duplicada(s) identica(s).';
        }
        $steps[] = 'Se genero el libro "consolidado.xlsx" con hojas Consolidado y Resumen.';

        return [
            'steps' => $steps,
            'artifacts' => [$artifact],
            'data' => [
                'operation' => 'excel_consolidation',
                'status' => 'ok',
                'input_files' => count($inputs),
                'consolidated_files' => count($perFileRows),
                'skipped_files' => $skipped,
                'total_rows' => count($consolidatedRows),
                'duplicates_removed' => $duplicatesRemoved,
                'headers' => $allHeaders,
                'numeric_columns' => array_keys($numericStats),
            ],
        ];
    }

    /**
     * Reads a single .xlsx/.csv into [headers, rows] using defensive caps.
     *
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>}
     */
    public function readTabular(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $highestColIndex = min(
            \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn()),
            self::MAX_COLUMNS
        );
        $highestRow = min($sheet->getHighestDataRow(), self::MAX_ROWS_PER_FILE + 1);

        $headers = [];
        $seen = [];
        for ($col = 1; $col <= $highestColIndex; $col++) {
            $value = $sheet->getCell([$col, 1])->getValue();
            $label = trim((string) ($value ?? ''));
            if ($label === '') {
                $label = 'columna_'.$col;
            }
            // Guarantee unique header labels so alignment is stable.
            $base = $label;
            $n = 2;
            while (in_array($label, $seen, true)) {
                $label = $base.'_'.$n;
                $n++;
            }
            $seen[] = $label;
            $headers[] = $label;
        }

        // Bail out if the header row is entirely empty/placeholder.
        $meaningful = array_filter($headers, fn ($h) => ! str_starts_with($h, 'columna_'));
        if ($meaningful === []) {
            $spreadsheet->disconnectWorksheets();

            return [[], []];
        }

        $rows = [];
        for ($r = 2; $r <= $highestRow; $r++) {
            $row = [];
            $hasValue = false;
            for ($col = 1; $col <= $highestColIndex; $col++) {
                $cell = $sheet->getCell([$col, $r])->getValue();
                if ($cell !== null && $cell !== '') {
                    $hasValue = true;
                }
                $row[] = $cell;
            }
            if ($hasValue) {
                $rows[] = $row;
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return [$headers, $rows];
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<string, array{count: int, sum: float, avg: float, min: float, max: float}>
     */
    protected function computeNumericStats(array $headers, array $rows): array
    {
        $stats = [];
        foreach ($headers as $index => $header) {
            $values = [];
            foreach ($rows as $row) {
                $value = $row[$index] ?? null;
                if (is_numeric($value)) {
                    $values[] = (float) $value;
                }
            }
            // Treat a column as numeric only when most non-empty cells are numbers.
            if ($values === [] || count($values) < max(1, (int) floor(count($rows) * 0.5))) {
                continue;
            }
            $stats[$header] = [
                'count' => count($values),
                'sum' => array_sum($values),
                'avg' => array_sum($values) / count($values),
                'min' => min($values),
                'max' => max($values),
            ];
        }

        return $stats;
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    protected function writeConsolidatedSheet(Spreadsheet $spreadsheet, array $headers, array $rows): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Consolidado');
        $sheet->fromArray($headers, null, 'A1');
        if ($rows !== []) {
            $sheet->fromArray($rows, null, 'A2');
        }
    }

    /**
     * @param  array<string, int>  $fileSummaries
     * @param  array<string, array<string, float|int>>  $numericStats
     * @param  array<int, string>  $skipped
     */
    protected function writeSummarySheet(
        Spreadsheet $spreadsheet,
        array $fileSummaries,
        array $numericStats,
        int $duplicatesRemoved,
        array $skipped
    ): void {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Resumen');

        $rows = [['Filas por archivo de origen', '']];
        $rows[] = ['Archivo', 'Filas'];
        $total = 0;
        foreach ($fileSummaries as $name => $count) {
            $rows[] = [$name, $count];
            $total += $count;
        }
        $rows[] = ['TOTAL', $total];
        $rows[] = ['Duplicados eliminados', $duplicatesRemoved];
        $rows[] = ['', ''];

        $rows[] = ['Columnas numericas detectadas', ''];
        $rows[] = ['Columna', 'Conteo', 'Suma', 'Promedio', 'Minimo', 'Maximo'];
        foreach ($numericStats as $column => $stat) {
            $rows[] = [
                $column,
                $stat['count'],
                round($stat['sum'], 4),
                round($stat['avg'], 4),
                round($stat['min'], 4),
                round($stat['max'], 4),
            ];
        }

        if ($skipped !== []) {
            $rows[] = ['', ''];
            $rows[] = ['Archivos omitidos (sin encabezados o ilegibles)', ''];
            foreach ($skipped as $name) {
                $rows[] = [$name, ''];
            }
        }

        $sheet->fromArray($rows, null, 'A1');
    }

    /**
     * Returns true when the artifact path looks like a spreadsheet/csv this service can read.
     */
    public static function isSupported(string $path): bool
    {
        return in_array(Str::lower(pathinfo($path, PATHINFO_EXTENSION)), ['xlsx', 'xls', 'csv'], true);
    }
}
