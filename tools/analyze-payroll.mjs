import { FileBlob, SpreadsheetFile } from "@oai/artifact-tool";
import fs from "node:fs/promises";

const sourcePath = "C:/Users/asus/Downloads/bordrotemizlik.xlsx";
const input = await FileBlob.load(sourcePath);
const workbook = await SpreadsheetFile.importXlsx(input);

const summary = await workbook.inspect({
  kind: "workbook,sheet,table,region,formula",
  maxChars: 18000,
  tableMaxRows: 18,
  tableMaxCols: 30,
  tableMaxCellChars: 120,
  options: { maxResults: 200 },
});

console.log(summary.ndjson);

const sheet = workbook.worksheets.getItem("bordro");
const values = sheet.getRange("A1:Y403").values;
const people = [];
for (let index = 0; index < values.length; index += 1) {
  const row = values[index];
  const tc = String(row[3] ?? "").replace(/\D/g, "");
  if (tc.length === 11 && row[0]) {
    people.push({
      row: index + 1,
      name: String(row[0]).trim(),
      tc,
      sgk: String(row[4] ?? "").trim(),
      primDay: row[6],
      net: row[23],
    });
  }
}
console.log(JSON.stringify({ personCount: people.length, firstFive: people.slice(0, 5), lastFive: people.slice(-5) }, null, 2));

for (const person of people.slice(0, 2)) {
  const start = person.row;
  const next = people.find((item) => item.row > start)?.row ?? 343;
  console.log(JSON.stringify({ person: person.name, range: `A${start}:Y${next - 1}`, rows: values.slice(start - 1, next - 1) }, null, 2));
}

const previewDir = "C:/Users/asus/OneDrive/Belgeler/zirve/.codex-spreadsheet-work/previews";
await fs.mkdir(previewDir, { recursive: true });
const firstPage = await workbook.render({ sheetName: "bordro", range: "A1:Y45", scale: 1, format: "png" });
await fs.writeFile(`${previewDir}/bordro-first-page.png`, new Uint8Array(await firstPage.arrayBuffer()));
const summaryPage = await workbook.render({ sheetName: "bordro", range: "A343:J403", scale: 1, format: "png" });
await fs.writeFile(`${previewDir}/bordro-summary.png`, new Uint8Array(await summaryPage.arrayBuffer()));
console.log(JSON.stringify({ previews: [`${previewDir}/bordro-first-page.png`, `${previewDir}/bordro-summary.png`] }));
