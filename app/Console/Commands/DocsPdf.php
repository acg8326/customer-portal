<?php

namespace App\Console\Commands;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class DocsPdf extends Command
{
    protected $signature = 'docs:pdf {source=docs/features.md : Markdown file to render} {--output= : Output PDF path (defaults to the source with a .pdf extension)}';

    protected $description = 'Render a Markdown doc to a styled PDF.';

    public function handle(): int
    {
        $source = base_path($this->argument('source'));

        if (! File::exists($source)) {
            $this->error("Not found: {$source}");

            return self::FAILURE;
        }

        $output = $this->option('output')
            ? base_path($this->option('output'))
            : preg_replace('/\.md$/i', '.pdf', $source);

        $body = Str::markdown(File::get($source), ['html_input' => 'strip']);
        $title = Str::of(File::get($source))->after('# ')->before("\n")->trim()->value() ?: 'Document';

        $dompdf = new Dompdf(tap(new Options, fn (Options $o) => $o->set('isRemoteEnabled', false)));
        $dompdf->loadHtml($this->wrap($title, $body));
        $dompdf->setPaper('A4');
        $dompdf->render();

        File::put($output, (string) $dompdf->output());

        $this->info('Wrote '.$output.' ('.number_format(strlen((string) $dompdf->output()) / 1024, 1).' KB)');

        return self::SUCCESS;
    }

    private function wrap(string $title, string $body): string
    {
        $generated = now()->toDayDateTimeString();

        return <<<HTML
        <!doctype html>
        <html><head><meta charset="utf-8"><style>
            @page { margin: 22mm 18mm; }
            * { font-family: DejaVu Sans, sans-serif; }
            body { color: #1f2733; font-size: 11px; line-height: 1.5; }
            h1 { color: #16305b; font-size: 22px; border-bottom: 3px solid #d9a441; padding-bottom: 6px; }
            h2 { color: #16305b; font-size: 15px; margin-top: 20px; border-bottom: 1px solid #e3e7ee; padding-bottom: 3px; }
            h3 { color: #23324a; font-size: 12.5px; margin-top: 14px; }
            a { color: #16305b; text-decoration: none; }
            code { font-family: DejaVu Sans Mono, monospace; background: #f2f4f7; padding: 1px 4px; border-radius: 3px; font-size: 10px; }
            pre { background: #f2f4f7; padding: 8px; border-radius: 4px; }
            table { width: 100%; border-collapse: collapse; margin: 10px 0; }
            th, td { border: 1px solid #dce1e8; padding: 5px 7px; text-align: left; vertical-align: top; }
            th { background: #16305b; color: #fff; }
            tr:nth-child(even) td { background: #f7f9fc; }
            blockquote { border-left: 3px solid #d9a441; margin: 8px 0; padding: 2px 10px; color: #4a5568; }
            .doc-header { color: #8a94a6; font-size: 9px; margin-bottom: 14px; }
        </style></head><body>
            <div class="doc-header">CWGP-AIMe · {$title} · generated {$generated}</div>
            {$body}
        </body></html>
        HTML;
    }
}
