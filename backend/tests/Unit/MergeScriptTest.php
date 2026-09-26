<?php

namespace App\Tests\Unit;

use App\Fusion\MergeScript;
use PHPUnit\Framework\TestCase;

final class MergeScriptTest extends TestCase
{
    public function testTemplateAddressIsWrittenInTheScript(): void
    {
        $script = (new MergeScript())->generate('http://api/api/onlyoffice/documents/1/template?_expiration=1&_hash=a+b"c');

        // builder.* commands take literal arguments: the address is a JSON string on the first line.
        self::assertStringStartsWith('builder.OpenFile("http://api/api/onlyoffice/documents/1/template?_expiration=1&_hash=a+b\"c");'."\n", $script);
        self::assertStringContainsString('builder.SaveFile("docx", "document.docx");', $script);
        self::assertStringEndsWith("builder.CloseFile();\n", $script);
    }

    public function testArgumentKeepsTheTemplateVariablesOnly(): void
    {
        $argument = (new MergeScript())->argument(
            ['variables' => ['nom', 'date', 'vide'], 'sections' => [['name' => 'lignes', 'fields' => ['designation', 'prix']]]],
            ['nom' => 'Martin', 'date' => 20260926, 'inconnu' => 'x', 'lignes' => [['designation' => 'Audit', 'prix' => 750, 'autre' => 'y'], 'not an item']],
        );

        self::assertSame(['nom' => 'Martin', 'date' => '20260926', 'vide' => ''], $argument['values']);
        self::assertSame(['lignes' => [['designation' => 'Audit', 'prix' => '750'], ['designation' => '', 'prix' => '']]], $argument['sections']);
        self::assertSame(['nom', 'date', 'vide'], $argument['variables']);
    }
}
