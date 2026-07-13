<?php

use App\Services\OfficeTextExtractor;

function fixturePath(string $name): string
{
    $dir = sys_get_temp_dir().'/office-fixtures';

    if (! is_dir($dir)) {
        mkdir($dir, recursive: true);
    }

    return $dir.'/'.$name;
}

function makeDocx(string $path): void
{
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('word/document.xml', <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
          <w:body>
            <w:p><w:r><w:t>Quarterly summary</w:t></w:r></w:p>
            <w:p><w:r><w:t>Revenue</w:t><w:tab/><w:t>up 12%</w:t></w:r></w:p>
          </w:body>
        </w:document>
        XML);
    $zip->close();
}

function makeXlsx(string $path): void
{
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('xl/workbook.xml', <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
          <sheets><sheet name="Invoices" sheetId="1"/></sheets>
        </workbook>
        XML);
    $zip->addFromString('xl/sharedStrings.xml', <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
          <si><t>Client</t></si><si><t>Amount</t></si><si><t>Acme Corp</t></si>
        </sst>
        XML);
    $zip->addFromString('xl/worksheets/sheet1.xml', <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
          <sheetData>
            <row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>
            <row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2"><v>1250.5</v></c></row>
          </sheetData>
        </worksheet>
        XML);
    $zip->close();
}

const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

test('docx text is extracted with paragraphs and tabs preserved', function () {
    $path = fixturePath('report.docx');
    makeDocx($path);

    $text = (string) app(OfficeTextExtractor::class)->extract($path, DOCX_MIME);

    expect($text)->toContain('Quarterly summary')
        ->and($text)->toContain("Revenue\tup 12%");
});

test('xlsx sheets are extracted with names, shared strings, and raw values', function () {
    $path = fixturePath('invoices.xlsx');
    makeXlsx($path);

    $text = (string) app(OfficeTextExtractor::class)->extract($path, XLSX_MIME);

    expect($text)->toContain('### Sheet: Invoices')
        ->and($text)->toContain("Client\tAmount")
        ->and($text)->toContain("Acme Corp\t1250.5");
});

test('csv passes through and oversized content is truncated with a note', function () {
    $path = fixturePath('big.csv');
    file_put_contents($path, "col_a,col_b\n".str_repeat("value_1,value_2\n", 100));

    $extractor = app(OfficeTextExtractor::class);

    expect((string) $extractor->extract($path, 'text/csv'))->toContain('col_a,col_b');

    $truncated = (string) $extractor->extract($path, 'text/csv', 100);

    expect(mb_strlen($truncated))->toBeLessThan(300)
        ->and($truncated)->toContain('[File truncated at 100 characters');
});

test('unsupported and image mimes are left to the native path', function () {
    $extractor = app(OfficeTextExtractor::class);

    expect($extractor->supports('image/png'))->toBeFalse()
        ->and($extractor->supports('application/pdf'))->toBeFalse()
        ->and($extractor->supports(DOCX_MIME))->toBeTrue()
        ->and($extractor->supports(XLSX_MIME))->toBeTrue()
        ->and($extractor->supports('text/csv'))->toBeTrue();
});
