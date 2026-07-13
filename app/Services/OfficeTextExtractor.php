<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use ZipArchive;

/**
 * Extracts plain text from Office documents (DOCX/XLSX) and plain-text
 * formats (CSV/TXT/MD) so Claude can read them — the API only understands
 * images and PDFs natively. OOXML is just zipped XML, so this needs no
 * heavy parsing libraries (mirroring the app's dependency-free OOXML
 * *writers* used for exports).
 *
 * Content fidelity: text, table cells, and sheet values survive; layout,
 * charts, and formatting do not — which is what a language model needs.
 */
class OfficeTextExtractor
{
    private const WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const SHEET_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /**
     * Extract text from a stored upload, or null when the type isn't one we
     * extract (images/PDFs go to Claude natively). Output is capped at
     * $maxChars with a truncation note the model can act on.
     */
    public function extract(string $absolutePath, string $mime, int $maxChars = 50000): ?string
    {
        $text = match (true) {
            str_contains($mime, 'wordprocessingml') => $this->fromDocx($absolutePath),
            str_contains($mime, 'spreadsheetml') => $this->fromXlsx($absolutePath),
            in_array($mime, ['text/csv', 'application/csv', 'text/plain', 'text/markdown'], true) => $this->fromPlainText($absolutePath),
            default => null,
        };

        if ($text === null) {
            return null;
        }

        $text = trim($text);

        if ($text === '') {
            return null;
        }

        if ($maxChars > 0 && mb_strlen($text) > $maxChars) {
            $text = mb_substr($text, 0, $maxChars)
                ."\n\n[File truncated at {$maxChars} characters — ask the user for a smaller extract if you need the rest.]";
        }

        return $text;
    }

    /**
     * Whether extract() knows how to handle this mime type.
     */
    public function supports(string $mime): bool
    {
        return str_contains($mime, 'wordprocessingml')
            || str_contains($mime, 'spreadsheetml')
            || in_array($mime, ['text/csv', 'application/csv', 'text/plain', 'text/markdown'], true);
    }

    /**
     * DOCX: word/document.xml — paragraphs become lines, tabs and line
     * breaks are preserved, everything else (styling) is dropped.
     */
    private function fromDocx(string $path): ?string
    {
        $xml = $this->zipEntry($path, 'word/document.xml');

        if ($xml === null) {
            return null;
        }

        $doc = $this->loadXml($xml);

        if ($doc === null) {
            return null;
        }

        $lines = [];

        foreach ($doc->getElementsByTagNameNS(self::WORD_NS, 'p') as $paragraph) {
            $line = '';

            foreach ($paragraph->getElementsByTagName('*') as $node) {
                $line .= match ($node->localName) {
                    't' => $node->textContent,
                    'tab' => "\t",
                    'br', 'cr' => "\n",
                    default => '',
                };
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * XLSX: every sheet becomes a "### Sheet: name" section with
     * tab-separated rows — shared strings, inline strings, and raw values.
     */
    private function fromXlsx(string $path): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return null;
        }

        try {
            $shared = $this->sharedStrings($zip);
            $names = $this->sheetNames($zip);
            $sections = [];

            for ($i = 1; ; $i++) {
                $xml = $zip->getFromName("xl/worksheets/sheet{$i}.xml");

                if ($xml === false) {
                    break;
                }

                $rows = $this->sheetRows($xml, $shared);

                if ($rows === '') {
                    continue;
                }

                $label = $names[$i - 1] ?? "Sheet {$i}";
                $sections[] = "### Sheet: {$label}\n".$rows;
            }

            return $sections !== [] ? implode("\n\n", $sections) : null;
        } finally {
            $zip->close();
        }
    }

    private function fromPlainText(string $path): ?string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        // Guarantee valid UTF-8 (Claude rejects malformed byte sequences).
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        return $contents;
    }

    /**
     * @param  list<string>  $shared
     */
    private function sheetRows(string $xml, array $shared): string
    {
        $doc = $this->loadXml($xml);

        if ($doc === null) {
            return '';
        }

        $lines = [];

        foreach ($doc->getElementsByTagNameNS(self::SHEET_NS, 'row') as $row) {
            $cells = [];

            foreach ($row->getElementsByTagNameNS(self::SHEET_NS, 'c') as $cell) {
                $cells[] = $this->cellValue($cell, $shared);
            }

            $line = rtrim(implode("\t", $cells));

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $shared
     */
    private function cellValue(DOMElement $cell, array $shared): string
    {
        $type = $cell->getAttribute('t');

        if ($type === 'inlineStr') {
            return $cell->textContent;
        }

        $value = '';

        foreach ($cell->getElementsByTagNameNS(self::SHEET_NS, 'v') as $v) {
            $value = $v->textContent;
        }

        if ($type === 's') {
            return $shared[(int) $value] ?? '';
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $doc = $this->loadXml($xml);

        if ($doc === null) {
            return [];
        }

        $strings = [];

        foreach ($doc->getElementsByTagNameNS(self::SHEET_NS, 'si') as $si) {
            $strings[] = $si->textContent;
        }

        return $strings;
    }

    /**
     * Sheet display names from xl/workbook.xml, in declaration order (which
     * matches the sheetN.xml numbering for files written by Excel and every
     * mainstream generator).
     *
     * @return list<string>
     */
    private function sheetNames(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/workbook.xml');

        if ($xml === false) {
            return [];
        }

        $doc = $this->loadXml($xml);

        if ($doc === null) {
            return [];
        }

        $names = [];

        foreach ($doc->getElementsByTagNameNS(self::SHEET_NS, 'sheet') as $sheet) {
            $names[] = $sheet->getAttribute('name');
        }

        return $names;
    }

    private function zipEntry(string $path, string $entry): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return null;
        }

        try {
            $contents = $zip->getFromName($entry);

            return $contents === false ? null : $contents;
        } finally {
            $zip->close();
        }
    }

    private function loadXml(string $xml): ?DOMDocument
    {
        $doc = new DOMDocument;

        // Entity loading stays off (XXE defense); suppress warnings from
        // malformed files and just skip them.
        if (! @$doc->loadXML($xml, LIBXML_NONET)) {
            return null;
        }

        return $doc;
    }
}
