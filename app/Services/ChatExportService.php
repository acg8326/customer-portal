<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use RuntimeException;
use ZipArchive;

/**
 * Turns an assistant answer (Markdown) into a downloadable file:
 *  - PDF   — Markdown → sanitized HTML (CommonMark) → dompdf.
 *  - DOCX  — Markdown → a minimal OOXML Word document via ZipArchive
 *            (headings, lists, tables, code, inline bold/italic/code).
 *  - CSV   — the GFM tables in the answer, native (no dependency).
 *  - XLSX  — the same tables, written as a minimal OOXML workbook via
 *            ZipArchive (no PhpSpreadsheet, so no ext-gd requirement).
 */
class ChatExportService
{
    /**
     * Render Markdown to a PDF document (returns the raw bytes).
     */
    public function pdf(string $markdown, string $title): string
    {
        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',       // never render raw HTML from the model
            'allow_unsafe_links' => false,
        ]);

        $body = (string) $converter->convert($markdown);
        $safeTitle = htmlspecialchars($title, ENT_QUOTES);

        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="en"><head><meta charset="utf-8"><style>
                body { font-family: DejaVu Sans, sans-serif; font-size: 12px; line-height: 1.5; color: #1a1a1a; }
                h1 { font-size: 18px; } h2 { font-size: 16px; } h3 { font-size: 14px; }
                table { border-collapse: collapse; width: 100%; margin: 8px 0; }
                th, td { border: 1px solid #999; padding: 4px 7px; text-align: left; vertical-align: top; }
                th { background: #f0f0f0; }
                pre { background: #f4f4f4; border: 1px solid #ddd; padding: 8px; border-radius: 4px; white-space: pre-wrap; }
                code { font-family: DejaVu Sans Mono, monospace; font-size: 11px; }
                blockquote { border-left: 3px solid #ccc; margin: 8px 0; padding-left: 10px; color: #555; }
                .doc-title { font-size: 16px; font-weight: bold; margin-bottom: 4px; }
                .doc-meta { color: #888; font-size: 10px; margin-bottom: 14px; }
            </style></head><body>
                <div class="doc-title">{$safeTitle}</div>
                <div class="doc-meta">Exported from AiMe BOT</div>
                {$body}
            </body></html>
            HTML;

        $options = new Options;
        $options->set('isRemoteEnabled', false); // don't fetch remote images
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * Render Markdown to a Word .docx document (returns the raw bytes). A .docx
     * is an OOXML zip, so — like the XLSX writer — we build it by hand with
     * ZipArchive and no PhpWord dependency. Supports headings, bullet/ordered
     * lists, blockquotes, fenced code, GFM tables, and inline bold/italic/code.
     */
    public function docx(string $markdown, string $title): string
    {
        $body = $this->docxTitle($title).$this->docxBlocks($markdown);

        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body>'.$body
            .'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
            .'<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="708" w:footer="708" w:gutter="0"/>'
            .'</w:sectPr></w:body></w:document>';

        $tmp = tempnam(sys_get_temp_dir(), 'docx');

        if ($tmp === false) {
            throw new RuntimeException('Could not create a temp file for DOCX.');
        }

        $zip = new ZipArchive;

        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open the DOCX archive.');
        }

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'</Relationships>');
        $zip->addFromString('word/document.xml', $document);

        $zip->close();

        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    /**
     * Extract every GFM pipe-table from the Markdown as a list of tables, each a
     * list of rows, each row a list of string cells.
     *
     * @return list<list<list<string>>>
     */
    public function extractTables(string $markdown): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $tables = [];
        $count = count($lines);
        $i = 0;

        while ($i < $count) {
            $line = trim($lines[$i]);
            $next = isset($lines[$i + 1]) ? trim($lines[$i + 1]) : '';

            // A table starts with a header row (has a pipe) followed by a
            // separator row of dashes.
            if (str_contains($line, '|') && $this->isSeparatorRow($next)) {
                $rows = [$this->splitRow($line)];
                $i += 2;

                while ($i < $count && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) {
                    $rows[] = $this->splitRow(trim($lines[$i]));
                    $i++;
                }

                $tables[] = $rows;

                continue;
            }

            $i++;
        }

        return $tables;
    }

    /**
     * CSV of the given tables (multiple tables separated by a blank line).
     *
     * @param  list<list<list<string>>>  $tables
     */
    public function csv(array $tables): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException('Could not open a buffer for CSV.');
        }

        foreach ($tables as $t => $rows) {
            if ($t > 0) {
                fwrite($handle, "\n");
            }

            foreach ($rows as $row) {
                // Explicit args (esp. an empty escape char) — the legacy escape
                // default is deprecated and produces non-standard CSV.
                fputcsv($handle, $row, ',', '"', '');
            }
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * A minimal .xlsx (one sheet per table) built by hand with ZipArchive — no
     * PhpSpreadsheet, so no ext-gd. Returns the raw bytes.
     *
     * @param  list<list<list<string>>>  $tables
     */
    public function xlsx(array $tables): string
    {
        if ($tables === []) {
            throw new RuntimeException('No tables to export.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');

        if ($tmp === false) {
            throw new RuntimeException('Could not create a temp file for XLSX.');
        }

        $zip = new ZipArchive;

        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open the XLSX archive.');
        }

        $sheetCount = count($tables);

        $zip->addFromString('[Content_Types].xml', $this->xlsxContentTypes($sheetCount));
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', $this->xlsxWorkbook($sheetCount));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->xlsxWorkbookRels($sheetCount));

        foreach ($tables as $index => $rows) {
            $zip->addFromString('xl/worksheets/sheet'.($index + 1).'.xml', $this->xlsxSheet($rows));
        }

        $zip->close();

        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    // --- internals -------------------------------------------------------

    private function isSeparatorRow(string $line): bool
    {
        if (! str_contains($line, '-')) {
            return false;
        }

        $cells = array_filter(
            array_map('trim', explode('|', trim($line, "| \t"))),
            static fn (string $c): bool => $c !== '',
        );

        if ($cells === []) {
            return false;
        }

        foreach ($cells as $cell) {
            if (preg_match('/^:?-+:?$/', $cell) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function splitRow(string $line): array
    {
        $line = trim($line);
        $line = preg_replace('/^\||\|$/', '', $line) ?? $line;

        return array_map(static fn (string $c): string => trim($c), explode('|', $line));
    }

    /**
     * The document's leading title + "Exported from AiMe BOT" meta line.
     */
    private function docxTitle(string $title): string
    {
        $titlePara = '<w:p><w:pPr><w:spacing w:after="40"/></w:pPr>'
            .'<w:r><w:rPr><w:b/><w:sz w:val="36"/><w:szCs w:val="36"/></w:rPr>'
            .'<w:t xml:space="preserve">'.$this->xmlEscape($title).'</w:t></w:r></w:p>';

        $metaPara = '<w:p><w:pPr><w:spacing w:after="200"/></w:pPr>'
            .'<w:r><w:rPr><w:i/><w:color w:val="888888"/><w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr>'
            .'<w:t xml:space="preserve">Exported from AiMe BOT</w:t></w:r></w:p>';

        return $titlePara.$metaPara;
    }

    /**
     * Parse the answer's Markdown into a stream of Word block elements
     * (paragraphs and tables). Mirrors the block types extractTables() cares
     * about, plus headings/lists/code/quotes.
     */
    private function docxBlocks(string $markdown): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $count = count($lines);
        $out = '';
        $i = 0;

        while ($i < $count) {
            $line = trim($lines[$i]);

            if ($line === '') {
                $i++;

                continue;
            }

            // Fenced code block — each inner line becomes a monospace paragraph.
            if (str_starts_with($line, '```')) {
                $i++;

                while ($i < $count && ! str_starts_with(trim($lines[$i]), '```')) {
                    $out .= '<w:p><w:pPr><w:ind w:left="360"/></w:pPr>'
                        .$this->docxRun($lines[$i], false, false, true).'</w:p>';
                    $i++;
                }

                $i++; // closing fence

                continue;
            }

            // GFM pipe-table (header row + dashes separator).
            $next = isset($lines[$i + 1]) ? trim($lines[$i + 1]) : '';

            if (str_contains($line, '|') && $this->isSeparatorRow($next)) {
                $rows = [$this->splitRow($line)];
                $i += 2;

                while ($i < $count && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) {
                    $rows[] = $this->splitRow(trim($lines[$i]));
                    $i++;
                }

                $out .= $this->docxTable($rows);

                continue;
            }

            // Heading (# … ######).
            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m) === 1) {
                $out .= $this->docxHeading($m[2], strlen($m[1]));
                $i++;

                continue;
            }

            // Horizontal rule — skip.
            if (preg_match('/^([-*_])(\s*\1){2,}$/', $line) === 1) {
                $i++;

                continue;
            }

            // Blockquote.
            if (preg_match('/^>\s?(.*)$/', $line, $m) === 1) {
                $out .= $this->docxParagraph($m[1], italic: true, indent: 360);
                $i++;

                continue;
            }

            // Unordered list item.
            if (preg_match('/^[-*+]\s+(.*)$/', $line, $m) === 1) {
                $out .= $this->docxListItem($m[1], '•');
                $i++;

                continue;
            }

            // Ordered list item.
            if (preg_match('/^(\d+)[.)]\s+(.*)$/', $line, $m) === 1) {
                $out .= $this->docxListItem($m[2], $m[1].'.');
                $i++;

                continue;
            }

            $out .= $this->docxParagraph($line);
            $i++;
        }

        return $out;
    }

    private function docxHeading(string $text, int $level): string
    {
        $sizes = [1 => 32, 2 => 28, 3 => 26, 4 => 24, 5 => 22, 6 => 21];
        $sz = $sizes[$level] ?? 22;

        return '<w:p><w:pPr><w:spacing w:before="240" w:after="80"/></w:pPr>'
            .'<w:r><w:rPr><w:b/><w:sz w:val="'.$sz.'"/><w:szCs w:val="'.$sz.'"/></w:rPr>'
            .'<w:t xml:space="preserve">'.$this->xmlEscape($this->stripInlineMarkers($text)).'</w:t></w:r></w:p>';
    }

    private function docxListItem(string $text, string $marker): string
    {
        return '<w:p><w:pPr><w:ind w:left="360" w:hanging="360"/></w:pPr>'
            .$this->docxRun($marker.' ', false, false, false)
            .$this->docxRuns($text).'</w:p>';
    }

    private function docxParagraph(string $text, bool $italic = false, int $indent = 0): string
    {
        $ppr = $indent > 0 ? '<w:pPr><w:ind w:left="'.$indent.'"/></w:pPr>' : '';

        return '<w:p>'.$ppr.$this->docxRuns($text, false, $italic).'</w:p>';
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function docxTable(array $rows): string
    {
        $borders = '<w:tblBorders>'
            .'<w:top w:val="single" w:sz="4" w:space="0" w:color="999999"/>'
            .'<w:left w:val="single" w:sz="4" w:space="0" w:color="999999"/>'
            .'<w:bottom w:val="single" w:sz="4" w:space="0" w:color="999999"/>'
            .'<w:right w:val="single" w:sz="4" w:space="0" w:color="999999"/>'
            .'<w:insideH w:val="single" w:sz="4" w:space="0" w:color="999999"/>'
            .'<w:insideV w:val="single" w:sz="4" w:space="0" w:color="999999"/>'
            .'</w:tblBorders>';

        $xml = '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/>'.$borders.'</w:tblPr>';

        foreach ($rows as $r => $cells) {
            $header = $r === 0;
            $xml .= '<w:tr>';

            foreach ($cells as $cell) {
                $shading = $header ? '<w:shd w:val="clear" w:color="auto" w:fill="F0F0F0"/>' : '';
                $xml .= '<w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"/>'.$shading.'</w:tcPr>'
                    .'<w:p>'.$this->docxRuns($cell, $header).'</w:p></w:tc>';
            }

            $xml .= '</w:tr>';
        }

        // A trailing paragraph is required after a table (Word rejects a table as
        // the final body element or two adjacent tables).
        return $xml.'</w:tbl><w:p/>';
    }

    /**
     * Split inline text into Word runs, honouring **bold**, *italic*, `code`.
     */
    private function docxRuns(string $text, bool $bold = false, bool $italic = false): string
    {
        $pattern = '/(\*\*.+?\*\*|__.+?__|\*[^*]+?\*|_[^_]+?_|`[^`]+?`)/s';
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $runs = '';

        foreach ($parts as $part) {
            if (preg_match('/^\*\*(.+)\*\*$/s', $part, $m) === 1 || preg_match('/^__(.+)__$/s', $part, $m) === 1) {
                $runs .= $this->docxRun($m[1], true, $italic, false);
            } elseif (preg_match('/^\*(.+)\*$/s', $part, $m) === 1 || preg_match('/^_(.+)_$/s', $part, $m) === 1) {
                $runs .= $this->docxRun($m[1], $bold, true, false);
            } elseif (preg_match('/^`(.+)`$/s', $part, $m) === 1) {
                $runs .= $this->docxRun($m[1], $bold, $italic, true);
            } else {
                $runs .= $this->docxRun($part, $bold, $italic, false);
            }
        }

        return $runs !== '' ? $runs : $this->docxRun('', $bold, $italic, false);
    }

    private function docxRun(string $text, bool $bold, bool $italic, bool $code): string
    {
        $rpr = '';

        if ($bold) {
            $rpr .= '<w:b/>';
        }

        if ($italic) {
            $rpr .= '<w:i/>';
        }

        if ($code) {
            $rpr .= '<w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/>';
        }

        $rprXml = $rpr !== '' ? '<w:rPr>'.$rpr.'</w:rPr>' : '';

        return '<w:r>'.$rprXml.'<w:t xml:space="preserve">'.$this->xmlEscape($text).'</w:t></w:r>';
    }

    /**
     * Strip inline emphasis/code markers from text used where runs can't carry
     * formatting (e.g. heading runs).
     */
    private function stripInlineMarkers(string $text): string
    {
        return (string) preg_replace('/\*\*|__|\*|_|`/', '', $text);
    }

    private function xmlEscape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1);
    }

    private function xlsxContentTypes(int $sheets): string
    {
        $overrides = '';
        for ($i = 1; $i <= $sheets; $i++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .$overrides
            .'</Types>';
    }

    private function xlsxWorkbook(int $sheets): string
    {
        $sheetTags = '';
        for ($i = 1; $i <= $sheets; $i++) {
            $sheetTags .= '<sheet name="Table'.$i.'" sheetId="'.$i.'" r:id="rId'.$i.'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheetTags.'</sheets></workbook>';
    }

    private function xlsxWorkbookRels(int $sheets): string
    {
        $rels = '';
        for ($i = 1; $i <= $sheets; $i++) {
            $rels .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$rels.'</Relationships>';
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function xlsxSheet(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        foreach ($rows as $r => $cells) {
            $rowNum = $r + 1;
            $xml .= '<row r="'.$rowNum.'">';

            foreach ($cells as $c => $value) {
                $ref = $this->columnLetter($c).$rowNum;

                if ($value !== '' && is_numeric($value)) {
                    $xml .= '<c r="'.$ref.'"><v>'.$value.'</v></c>';
                } else {
                    $xml .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'
                        .htmlspecialchars($value, ENT_QUOTES | ENT_XML1)
                        .'</t></is></c>';
                }
            }

            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    private function columnLetter(int $index): string
    {
        $letter = '';
        $index++;

        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)).$letter;
            $index = intdiv($index, 26);
        }

        return $letter;
    }
}
