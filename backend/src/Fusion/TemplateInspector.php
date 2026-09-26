<?php

namespace App\Fusion;

/**
 * Variables of a DOCX template: {{name}}, and repeated sections {{#items}}…{{/items}} whose fields are the variables
 * between the two markers. Word often splits a variable over several runs (spelling, formatting): the text of each
 * paragraph is read as a whole. A section repeats a table row: its markers must be in the same row.
 */
final class TemplateInspector
{
    public const NAME = '[A-Za-z_][A-Za-z0-9_]{0,63}';
    private const TOKEN = '/\{\{\s*([#\/]?)\s*('.self::NAME.')\s*\}\}/';
    private const WORD = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * @return array{variables: list<string>, sections: list<array{name: string, fields: list<string>}>, errors: list<string>}
     *
     * @throws \InvalidArgumentException not a DOCX file
     */
    public function inspect(string $path): array
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::RDONLY)) {
            throw new \InvalidArgumentException('Not a DOCX file.');
        }
        try {
            $body = $zip->getFromName('word/document.xml');
            if (false === $body) {
                throw new \InvalidArgumentException('Not a DOCX file: word/document.xml is missing.');
            }
            $parts = [$body];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = (string) $zip->getNameIndex($i);
                if (preg_match('#^word/(header|footer)\d*\.xml$#', $name)) {
                    $parts[] = (string) $zip->getFromName($name);
                }
            }
        } finally {
            $zip->close();
        }

        $variables = $sections = $errors = [];
        $open = null;
        foreach ($parts as $xml) {
            foreach ($this->paragraphs($xml) as [$text, $row]) {
                preg_match_all(self::TOKEN, $text, $matches, \PREG_SET_ORDER);
                foreach ($matches as [, $marker, $name]) {
                    if ('#' === $marker) {
                        if (null !== $open) {
                            $errors[] = \sprintf('La section « %s » commence avant la fin de « %s ».', $name, $open['name']);
                        }
                        $open = ['name' => $name, 'row' => $row];
                        $sections[$name] ??= [];
                    } elseif ('/' === $marker) {
                        if (null === $open || $open['name'] !== $name) {
                            $errors[] = \sprintf('La fin de section « %s » ne correspond à aucun début.', $name);
                        } elseif (null === $row || $row !== $open['row']) {
                            $errors[] = \sprintf('La section « %s » doit commencer et finir dans la même ligne de tableau.', $name);
                        }
                        $open = null;
                    } elseif (null !== $open) {
                        $sections[$open['name']][$name] = true;
                    } else {
                        $variables[$name] = true;
                    }
                }
            }
        }
        if (null !== $open) {
            $errors[] = \sprintf('La section « %s » n’est pas fermée ({{/%s}}).', $open['name'], $open['name']);
        }

        return [
            'variables' => array_keys($variables),
            'sections' => array_map(static fn (string $name, array $fields) => ['name' => $name, 'fields' => array_keys($fields)], array_keys($sections), $sections),
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /** @return iterable<array{0: string, 1: ?int}> text of each paragraph, and the table row it is in */
    private function paragraphs(string $xml): iterable
    {
        $dom = new \DOMDocument();
        if ('' === $xml || !@$dom->loadXML($xml, \LIBXML_NONET)) {
            return;
        }
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', self::WORD);
        $rows = new \SplObjectStorage();
        foreach ($xpath->query('//w:p') ?: [] as $paragraph) {
            $text = '';
            foreach ($xpath->query('.//w:t', $paragraph) ?: [] as $node) {
                $text .= $node->textContent;
            }
            $row = $xpath->query('ancestor::w:tr[1]', $paragraph)?->item(0);
            if (null !== $row && !$rows->offsetExists($row)) {
                $rows[$row] = \count($rows);
            }
            yield [$text, null === $row ? null : $rows[$row]];
        }
    }
}
