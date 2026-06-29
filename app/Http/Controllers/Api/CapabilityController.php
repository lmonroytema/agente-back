<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PhpToolOrchestratorService;
use Illuminate\Http\JsonResponse;
use Throwable;

class CapabilityController extends Controller
{
    public function __invoke(PhpToolOrchestratorService $orchestrator): JsonResponse
    {
        try {
            return response()->json($orchestrator->capabilities());
        } catch (Throwable) {
            return response()->json([
                [
                    'tool' => 'pdf_batch',
                    'title' => 'Edición masiva de PDF',
                    'description' => 'Combina PDFs, los parte por páginas o secciones, extrae texto o rangos, aplica marca de agua y quita folios o textos repetidos por zona.',
                    'examples' => [
                        'Combina los PDFs cargados en un solo archivo',
                        'Parte el PDF cargado en páginas individuales',
                        'Divide el PDF cargado en secciones 1-5, 6-10 y 11-15',
                        'Lee el PDF cargado, extraelo a Markdown y responde: resume riesgos y hallazgos',
                        'Quita los folios del área inferior derecha desde la página 2 hasta la 8',
                    ],
                ],
                [
                    'tool' => 'office',
                    'title' => 'Ofimática Microsoft',
                    'description' => 'Genera documentos DOCX, PPTX y XLSX para reportes y presentaciones operativas.',
                    'examples' => [
                        'Crea una presentación comercial de Tema Litoclean',
                    ],
                ],
            ]);
        }
    }
}
