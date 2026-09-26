builder.CreateFile("docx");
var p = Api.CreateParagraph(); p.AddText("Hello " + (typeof Argument)); Api.GetDocument().Push(p);
builder.SaveFile("docx", "t1.docx");
builder.CloseFile();
