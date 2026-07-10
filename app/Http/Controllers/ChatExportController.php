<?php

namespace App\Http\Controllers;

use App\Services\ChatExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ChatExportController extends Controller
{
    public function __construct(private readonly ChatExportService $exporter) {}

    /**
     * Export an assistant answer (Markdown) as a downloadable PDF.
     */
    public function pdf(Request $request): Response
    {
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:200000'],
            'title' => ['nullable', 'string', 'max:200'],
        ]);

        $title = trim((string) ($validated['title'] ?? '')) ?: 'AiMe answer';
        $pdf = $this->exporter->pdf($validated['content'], $title);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->filename('pdf').'"',
        ]);
    }

    /**
     * Export an assistant answer (Markdown) as a downloadable Word .docx.
     */
    public function docx(Request $request): Response
    {
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:200000'],
            'title' => ['nullable', 'string', 'max:200'],
        ]);

        $title = trim((string) ($validated['title'] ?? '')) ?: 'AiMe answer';
        $docx = $this->exporter->docx($validated['content'], $title);

        return response($docx, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="'.$this->filename('docx').'"',
        ]);
    }

    /**
     * Export the tables in an assistant answer as CSV or XLSX. Returns a JSON
     * error if the answer has no tables to export.
     */
    public function sheet(Request $request): Response|JsonResponse
    {
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:200000'],
            'format' => ['required', Rule::in(['csv', 'xlsx'])],
        ]);

        $tables = $this->exporter->extractTables($validated['content']);

        if ($tables === []) {
            return response()->json([
                'message' => 'This answer has no tables to export.',
            ], 422);
        }

        if ($validated['format'] === 'csv') {
            return response($this->exporter->csv($tables), 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="'.$this->filename('csv').'"',
            ]);
        }

        return response($this->exporter->xlsx($tables), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$this->filename('xlsx').'"',
        ]);
    }

    private function filename(string $ext): string
    {
        return 'aime-'.Str::of(now()->format('Ymd-His'))->toString().'.'.$ext;
    }
}
