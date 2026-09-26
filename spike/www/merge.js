// Spike: open the template, fill {{variables}} (even split across runs) and repeat the table row of {{#lignes}}.
var data = Argument;
builder.OpenFile("http://files/template.docx");
var doc = Api.GetDocument();

// Repeated rows: a table row whose text starts with {{#name}} and ends with {{/name}}.
var tables = doc.GetAllTables();
for (var t = 0; t < tables.length; t++) {
  var table = tables[t];
  for (var r = table.GetRowsCount() - 1; r >= 0; r--) {
    var row = table.GetRow(r);
    var first = row.GetCell(0).GetContent().GetElement(0).GetText();
    var m = /^\{\{#(\w+)\}\}/.exec(first);
    if (!m) continue;
    var items = data.values[m[1]] || [];
    var cells = row.GetCellsCount();
    // Insert one copy of the row per item after it, then fill each copy and remove the template row.
    for (var i = items.length - 1; i >= 0; i--) {
      table.AddRow(row.GetCell(0), false);
      var copy = table.GetRow(r + 1);
      for (var c = 0; c < cells; c++) {
        var text = row.GetCell(c).GetContent().GetElement(0).GetText()
          .replace('{{#' + m[1] + '}}', '').replace('{{/' + m[1] + '}}', '');
        text = text.replace(/\{\{(\w+)\}\}/g, function (_, k) { return items[i][k] != null ? String(items[i][k]) : ''; });
        var para = copy.GetCell(c).GetContent().GetElement(0);
        para.RemoveAllElements(); para.AddText(text);
      }
    }
    table.RemoveRow(row.GetCell(0));
  }
}

// Plain variables, through the document-wide search and replace (handles text split across runs).
for (var key in data.values) {
  if (typeof data.values[key] !== 'object') {
    doc.SearchAndReplace({ searchString: '{{' + key + '}}', replaceString: String(data.values[key]), matchCase: true });
  }
}

builder.SaveFile('docx', 'fusion.docx');
builder.SaveFile('pdf', 'fusion.pdf');
builder.CloseFile();
