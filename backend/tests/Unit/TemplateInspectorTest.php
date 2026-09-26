<?php

namespace App\Tests\Unit;

use App\Fusion\SampleTemplate;
use App\Fusion\TemplateInspector;
use PHPUnit\Framework\TestCase;

final class TemplateInspectorTest extends TestCase
{
    public function testSampleTemplate(): void
    {
        $result = (new TemplateInspector())->inspect((new SampleTemplate())->write());

        self::assertSame(['date', 'numero', 'client_nom', 'client_adresse', 'total', 'expediteur'], $result['variables']);
        self::assertSame([['name' => 'lignes', 'fields' => ['designation', 'quantite', 'prix']]], $result['sections']);
        self::assertSame([], $result['errors']);
    }

    public function testVariableSplitOverRunsAndSpaces(): void
    {
        $result = (new TemplateInspector())->inspect($this->docx(
            '<w:p><w:r><w:t>Bonjour {{ </w:t></w:r><w:r><w:t>prenom</w:t></w:r><w:r><w:t> }}, {{ville}}</w:t></w:r></w:p>',
        ));

        self::assertSame(['prenom', 'ville'], $result['variables']);
    }

    public function testSectionErrors(): void
    {
        $inspector = new TemplateInspector();
        $unclosed = $inspector->inspect($this->docx('<w:p><w:r><w:t>{{#lignes}}{{a}}</w:t></w:r></w:p>'));
        self::assertStringContainsString('n’est pas fermée', $unclosed['errors'][0]);

        $outsideRow = $inspector->inspect($this->docx('<w:p><w:r><w:t>{{#lignes}}{{a}}{{/lignes}}</w:t></w:r></w:p>'));
        self::assertStringContainsString('même ligne de tableau', $outsideRow['errors'][0]);

        $stray = $inspector->inspect($this->docx('<w:p><w:r><w:t>{{/lignes}}</w:t></w:r></w:p>'));
        self::assertStringContainsString('ne correspond à aucun début', $stray['errors'][0]);
    }

    public function testNotADocx(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($path, 'plain text');

        $this->expectException(\InvalidArgumentException::class);
        (new TemplateInspector())->inspect($path);
    }

    private function docx(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>');
        $zip->close();

        return $path;
    }
}
