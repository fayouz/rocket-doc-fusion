<?php

namespace App\Fusion;

/**
 * The sample template (a quote): variables, one of them split over two runs as Word often does, and a table row
 * repeated for each line. Offered on the merge page, used by the demo data and the tests.
 */
final class SampleTemplate
{
    public const NAME = 'devis-exemple.docx';

    /** Values that fill it, for the demo. */
    public const VALUES = [
        'numero' => 'D-2026-042',
        'client_nom' => 'Société Martin',
        'client_adresse' => '12 rue des Lilas, 69003 Lyon',
        'date' => '26/09/2026',
        'lignes' => [
            ['designation' => 'Audit de l’existant', 'quantite' => '1', 'prix' => '750,00 €'],
            ['designation' => 'Formation des équipes (jour)', 'quantite' => '2', 'prix' => '500,00 €'],
        ],
        'total' => '1 250,00 €',
        'expediteur' => 'Marie Martin',
    ];

    private const W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';

    /** @return string path of a new DOCX file (temporary) */
    public function write(?string $path = null): string
    {
        $path ??= tempnam(sys_get_temp_dir(), 'sample').'.docx';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'</Relationships>');
        $zip->addFromString('word/document.xml', $this->document());
        $zip->close();

        return $path;
    }

    private function document(): string
    {
        $cell = static fn (string $text, bool $bold = false) => '<w:tc><w:tcPr><w:tcW w:w="3000" w:type="dxa"/></w:tcPr>'.self::p($text, $bold).'</w:tc>';
        $border = '<w:top w:val="single" w:sz="4"/><w:left w:val="single" w:sz="4"/><w:bottom w:val="single" w:sz="4"/><w:right w:val="single" w:sz="4"/><w:insideH w:val="single" w:sz="4"/><w:insideV w:val="single" w:sz="4"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document '.self::W.'><w:body>'
            .'<w:p><w:pPr><w:jc w:val="right"/></w:pPr><w:r><w:t xml:space="preserve">Lyon, le {{date}}</w:t></w:r></w:p>'
            .self::p('Devis {{numero}}', true, 32)
            // "{{client_" and "nom}}" in two runs, the second one bold: the variable is still found.
            .'<w:p><w:r><w:t xml:space="preserve">À l’attention de {{client_</w:t></w:r><w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">nom}}</w:t></w:r></w:p>'
            .self::p('{{client_adresse}}')
            .self::p('Madame, Monsieur, voici notre proposition :')
            .'<w:tbl><w:tblPr><w:tblW w:w="9000" w:type="dxa"/><w:tblBorders>'.$border.'</w:tblBorders></w:tblPr>'
            .'<w:tblGrid><w:gridCol w:w="3000"/><w:gridCol w:w="3000"/><w:gridCol w:w="3000"/></w:tblGrid>'
            .'<w:tr>'.$cell('Désignation', true).$cell('Quantité', true).$cell('Prix', true).'</w:tr>'
            .'<w:tr>'.$cell('{{#lignes}}{{designation}}').$cell('{{quantite}}').$cell('{{prix}}{{/lignes}}').'</w:tr>'
            .'</w:tbl>'
            .self::p('Total : {{total}}', true)
            .self::p('Cordialement,')
            .self::p('{{expediteur}}')
            .'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr>'
            .'</w:body></w:document>';
    }

    private static function p(string $text, bool $bold = false, ?int $size = null): string
    {
        $properties = ($bold ? '<w:b/>' : '').(null !== $size ? '<w:sz w:val="'.$size.'"/>' : '');

        return '<w:p><w:r>'.('' !== $properties ? '<w:rPr>'.$properties.'</w:rPr>' : '').'<w:t xml:space="preserve">'.htmlspecialchars($text, \ENT_XML1).'</w:t></w:r></w:p>';
    }
}
