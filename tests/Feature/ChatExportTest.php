<?php

use App\Models\User;
use App\Services\ChatExportService;

$sampleMarkdown = "# Report\n\nHello **world**.\n\n| Name | ID |\n| --- | --- |\n| Acme | 1 |\n| Globex | 2 |\n";

test('exporting requires authentication', function () use ($sampleMarkdown) {
    $this->postJson('/chat/export/pdf', ['content' => $sampleMarkdown])
        ->assertStatus(401);
});

test('a PDF is generated from an answer', function () use ($sampleMarkdown) {
    $user = User::factory()->create();

    $res = $this->actingAs($user)
        ->post('/chat/export/pdf', ['content' => $sampleMarkdown, 'title' => 'My report'])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect($res->getContent())->toStartWith('%PDF');
});

test('a DOCX is generated from an answer', function () use ($sampleMarkdown) {
    $user = User::factory()->create();

    $res = $this->actingAs($user)
        ->post('/chat/export/docx', ['content' => $sampleMarkdown, 'title' => 'My report'])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

    // .docx is a ZIP container — it must start with the PK signature.
    expect($res->getContent())->toStartWith('PK');
});

test('the DOCX contains the answer text and a well-formed document part', function () use ($sampleMarkdown) {
    $bytes = app(ChatExportService::class)->docx($sampleMarkdown, 'My report');

    $tmp = tempnam(sys_get_temp_dir(), 'docxtest');
    file_put_contents($tmp, $bytes);

    $zip = new ZipArchive;
    expect($zip->open($tmp))->toBeTrue();

    $doc = (string) $zip->getFromName('word/document.xml');
    $zip->close();
    @unlink($tmp);

    expect($doc)
        ->toContain('<w:document')
        ->toContain('My report')      // title
        ->toContain('Report')          // the # heading
        ->toContain('world')           // bold inline text
        ->toContain('<w:tbl>')         // the GFM table became a Word table
        ->toContain('Acme');
});

test('CSV export returns the answer tables', function () use ($sampleMarkdown) {
    $user = User::factory()->create();

    $res = $this->actingAs($user)
        ->post('/chat/export/sheet', ['content' => $sampleMarkdown, 'format' => 'csv'])
        ->assertOk();

    expect($res->headers->get('Content-Type'))->toContain('text/csv');
    expect($res->getContent())
        ->toContain('Name,ID')
        ->toContain('Acme,1')
        ->toContain('Globex,2');
});

test('XLSX export returns a valid (zip) workbook', function () use ($sampleMarkdown) {
    $user = User::factory()->create();

    $res = $this->actingAs($user)
        ->post('/chat/export/sheet', ['content' => $sampleMarkdown, 'format' => 'xlsx'])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    // .xlsx is a ZIP container — it must start with the PK signature.
    expect($res->getContent())->toStartWith('PK');
});

test('a sheet export with no tables returns a helpful error', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/chat/export/sheet', ['content' => 'Just prose, no tables here.', 'format' => 'csv'])
        ->assertStatus(422)
        ->assertJson(['message' => 'This answer has no tables to export.']);
});

test('the sheet format is validated', function () use ($sampleMarkdown) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/chat/export/sheet', ['content' => $sampleMarkdown, 'format' => 'docx'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['format']]);
});

test('the exporter parses GFM tables', function () {
    $svc = app(ChatExportService::class);
    $tables = $svc->extractTables("intro\n\n| A | B |\n|---|---|\n| 1 | 2 |\n| 3 | 4 |\n\nend");

    expect($tables)->toHaveCount(1)
        ->and($tables[0])->toBe([['A', 'B'], ['1', '2'], ['3', '4']]);
});
