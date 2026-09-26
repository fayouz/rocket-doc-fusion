<?php

namespace App\Fusion;

/**
 * Document Builder script of a merge. The builder.* lines are commands read by ONLYOFFICE with literal arguments
 * (not JavaScript): the address of the template is written in the script. The values come in "Argument":
 * {values: {name: text}, sections: {name: [{field: text}]}, variables: [names], fields: {section: [names]}}.
 */
final class MergeScript
{
    public const OUTPUT = 'document.docx';

    private const BODY = <<<'JS'
        var data = Argument;
        var doc = Api.GetDocument();
        function text(value) { return value === null || value === undefined ? '' : String(value); }
        function fill(source, values, section) {
          var out = source.replace(/[\t\r\n]+$/, '');
          if (section) out = out.replace(new RegExp('\\{\\{\\s*[#/]\\s*' + section + '\\s*\\}\\}', 'g'), '');
          return out.replace(/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/g, function (all, name) {
            return Object.prototype.hasOwnProperty.call(values, name) ? text(values[name]) : all;
          });
        }
        function cellTexts(cell) {
          var content = cell.GetContent(), texts = [];
          for (var p = 0; p < content.GetElementsCount(); p++) {
            var element = content.GetElement(p);
            texts.push(element.GetText ? element.GetText() : '');
          }
          return texts;
        }
        function setCell(cell, texts) {
          var content = cell.GetContent();
          for (var p = 0; p < content.GetElementsCount() && p < texts.length; p++) {
            var paragraph = content.GetElement(p);
            if (!paragraph.RemoveAllElements) continue;
            paragraph.RemoveAllElements();
            if (texts[p] !== '') paragraph.AddText(texts[p]);
          }
        }

        // Repeated sections: a table row starting with {{#name}} and ending with {{/name}}, one row per item.
        var tables = doc.GetAllTables();
        for (var t = 0; t < tables.length; t++) {
          var table = tables[t];
          for (var r = table.GetRowsCount() - 1; r >= 0; r--) {
            var row = table.GetRow(r);
            var first = cellTexts(row.GetCell(0)).join('');
            var match = /^\s*\{\{\s*#\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/.exec(first);
            if (!match) continue;
            var name = match[1], items = data.sections[name] || [], cells = row.GetCellsCount(), source = [];
            for (var c = 0; c < cells; c++) source.push(cellTexts(row.GetCell(c)));
            for (var i = items.length - 1; i >= 0; i--) {
              table.AddRow(row.GetCell(0), false);
              var copy = table.GetRow(r + 1);
              for (var k = 0; k < cells; k++) {
                setCell(copy.GetCell(k), source[k].map(function (s) { return fill(s, items[i], name); }));
              }
            }
            table.RemoveRow(row.GetCell(0));
          }
        }

        // Variables: search and replace over the whole document (a variable split over several runs is found).
        for (var v = 0; v < data.variables.length; v++) {
          var key = data.variables[v];
          var value = text(data.values[key]);
          doc.SearchAndReplace({ searchString: '{{' + key + '}}', replaceString: value === '' ? ' ' : value, matchCase: true });
        }
        JS;

    public function generate(string $templateUrl): string
    {
        return 'builder.OpenFile('.json_encode($templateUrl, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES).');'."\n"
            .self::BODY."\n"
            .'builder.SaveFile("docx", "'.self::OUTPUT.'");'."\n"
            .'builder.CloseFile();'."\n";
    }

    /**
     * Argument of the script: the values of the template variables (missing ones become empty) and of its sections.
     *
     * @param array{variables: list<string>, sections: list<array{name: string, fields: list<string>}>} $template
     * @param array<string, mixed>                                                                      $values
     *
     * @return array{values: array<string, string>, sections: array<string, list<array<string, string>>>, variables: list<string>}
     */
    public function argument(array $template, array $values): array
    {
        $scalars = [];
        foreach ($template['variables'] as $name) {
            $scalars[$name] = self::scalar($values[$name] ?? null);
        }
        $sections = [];
        foreach ($template['sections'] as $section) {
            $items = \is_array($values[$section['name']] ?? null) ? array_values($values[$section['name']]) : [];
            $sections[$section['name']] = array_map(static function (mixed $item) use ($section): array {
                $row = [];
                foreach ($section['fields'] as $field) {
                    $row[$field] = self::scalar(\is_array($item) ? ($item[$field] ?? null) : null);
                }

                return $row;
            }, $items);
        }

        return ['values' => $scalars, 'sections' => $sections, 'variables' => $template['variables']];
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            null === $value => '',
            \is_bool($value) => $value ? 'oui' : 'non',
            \is_scalar($value) => (string) $value,
            $value instanceof \Stringable => (string) $value,
            default => '',
        };
    }
}
