// Markdown summary of the changes between two SDL files, grouped by criticality (SDK_SPEC 6.1 step 4).
// Shared byte-for-byte by every WhiteMarket SDK.
//
// Usage: node schema-diff.mjs <old.graphql> <new.graphql> [--append schema/CHANGES.md] [--date YYYY-MM-DD]
// Prints the summary. With GITHUB_OUTPUT set, writes `breaking=true|false` and `changes=<count>` for the workflow.

import { appendFile, readFile, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { CriticalityLevel, diff } from '@graphql-inspector/core';
import { buildSchema } from 'graphql';

// Same scrubbing as fetch-schema.mjs: change messages quote descriptions, and the repository never contains tokens.
const JWT_PATTERN = /eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*/g;

const SECTIONS = [
  [CriticalityLevel.Breaking, 'Breaking'],
  [CriticalityLevel.Dangerous, 'Dangerous'],
  [CriticalityLevel.NonBreaking, 'Safe'],
];

function parseArgs(argv) {
  const args = { files: [], append: null, date: new Date().toISOString().slice(0, 10) };
  for (let i = 0; i < argv.length; i += 1) {
    if (argv[i] === '--append') args.append = argv[++i];
    else if (argv[i] === '--date') args.date = argv[++i];
    else args.files.push(argv[i]);
  }
  if (args.files.length !== 2) throw new Error('usage: schema-diff.mjs <old.graphql> <new.graphql> [--append FILE] [--date YYYY-MM-DD]');
  return args;
}

async function main() {
  const { files, append, date } = parseArgs(process.argv.slice(2));
  const [oldSchema, newSchema] = await Promise.all(files.map(async (file) => buildSchema(await readFile(file, 'utf8'))));
  const changes = await diff(oldSchema, newSchema);

  const lines = [`## ${date}`, ''];
  if (changes.length === 0) lines.push('No changes.', '');
  for (const [level, title] of SECTIONS) {
    const messages = changes
      .filter((change) => change.criticality.level === level)
      .map((change) => change.message.replace(JWT_PATTERN, '<JWT>'))
      .sort();
    if (messages.length === 0) continue;
    lines.push(`### ${title} (${messages.length})`, '', ...messages.map((message) => `- ${message}`), '');
  }
  const summary = lines.join('\n');
  console.log(summary);

  if (append !== null) {
    if (!existsSync(append)) await writeFile(append, '# Partner schema changes\n\nAppended by the schema-sync workflow (newest last).\n\n');
    await appendFile(append, `${summary}\n`);
  }
  if (process.env.GITHUB_OUTPUT) {
    const breaking = changes.some((change) => change.criticality.level === CriticalityLevel.Breaking);
    await appendFile(process.env.GITHUB_OUTPUT, `breaking=${breaking}\nchanges=${changes.length}\n`);
  }
}

main().catch((error) => {
  console.error(`schema-diff failed: ${error.message}`);
  process.exit(1);
});
