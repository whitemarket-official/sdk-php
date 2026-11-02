// Fetches the partner GraphQL schema by introspection (no auth) and writes
//   schema/partner.introspection.json  (stable key order, 2-space indent)
//   schema/partner.graphql             (printSchema(lexicographicSortSchema(...)))
// Shared byte-for-byte by every WhiteMarket SDK (SDK_SPEC 1.1, 6.1).
//
// Usage: node fetch-schema.mjs [--endpoint URL] [--out-dir DIR]

import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  buildClientSchema,
  getIntrospectionQuery,
  introspectionFromSchema,
  lexicographicSortSchema,
  printSchema,
} from 'graphql';

const DEFAULT_ENDPOINT = 'https://api.white.market/graphql/partner';
const ATTEMPTS = 3;
const BASE_DELAY_MS = 1000;
const TIMEOUT_MS = 30000;

// JWT-shaped example values in descriptions are replaced: the repository never contains tokens (CLAUDE.md data policy).
const JWT_PATTERN = /eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*/g;
const JWT_PLACEHOLDER = '<JWT>';

// The server rejects queries deeper than 11 [server] and does not know `__Type.specifiedByURL` [observed 2026-10-30]:
// typeDepth 7 keeps the query at depth 11 and still unwraps the deepest wrapper in the schema (`[X!]!` needs 3).
const INTROSPECTION_OPTIONS = {
  descriptions: true,
  specifiedByUrl: false,
  directiveIsRepeatable: true,
  schemaDescription: true,
  inputValueDeprecation: true,
  typeDepth: 7,
};

function parseArgs(argv) {
  const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
  const args = { endpoint: DEFAULT_ENDPOINT, outDir: path.join(repoRoot, 'schema') };
  for (let i = 0; i < argv.length; i += 1) {
    if (argv[i] === '--endpoint') args.endpoint = argv[++i];
    else if (argv[i] === '--out-dir') args.outDir = path.resolve(argv[++i]);
    else throw new Error(`Unknown argument: ${argv[i]}`);
  }
  return args;
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function introspect(endpoint) {
  const body = JSON.stringify({ query: getIntrospectionQuery(INTROSPECTION_OPTIONS), operationName: 'IntrospectionQuery' });
  let lastError;
  for (let attempt = 1; attempt <= ATTEMPTS; attempt += 1) {
    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body,
        signal: AbortSignal.timeout(TIMEOUT_MS),
      });
      if (response.status !== 200) {
        throw new Error(`HTTP ${response.status} from ${endpoint}`);
      }
      const payload = await response.json();
      if (payload.errors !== undefined) {
        // Not retried: the server answered and rejected the query.
        const error = new Error(`GraphQL errors: ${JSON.stringify(payload.errors)}`);
        error.final = true;
        throw error;
      }
      if (payload.data?.__schema === undefined) {
        throw new Error('Response has no data.__schema');
      }
      return payload.data;
    } catch (error) {
      lastError = error;
      if (error.final === true || attempt === ATTEMPTS) break;
      const delay = BASE_DELAY_MS * 2 ** (attempt - 1);
      console.error(`attempt ${attempt} failed (${error.message}), retrying in ${delay} ms`);
      await sleep(delay);
    }
  }
  throw lastError;
}

function scrubDescriptions(node) {
  if (Array.isArray(node)) {
    node.forEach(scrubDescriptions);
  } else if (node !== null && typeof node === 'object') {
    for (const [key, value] of Object.entries(node)) {
      if (key === 'description' && typeof value === 'string') {
        node[key] = value.replace(JWT_PATTERN, JWT_PLACEHOLDER);
      } else {
        scrubDescriptions(value);
      }
    }
  }
}

function sortKeys(node) {
  if (Array.isArray(node)) return node.map(sortKeys);
  if (node !== null && typeof node === 'object') {
    return Object.fromEntries(
      Object.keys(node)
        .sort()
        .map((key) => [key, sortKeys(node[key])]),
    );
  }
  return node;
}

async function main() {
  const { endpoint, outDir } = parseArgs(process.argv.slice(2));
  const data = await introspect(endpoint);
  scrubDescriptions(data);

  const schema = lexicographicSortSchema(buildClientSchema(data));
  const introspection = sortKeys(introspectionFromSchema(schema, INTROSPECTION_OPTIONS));

  await mkdir(outDir, { recursive: true });
  await writeFile(path.join(outDir, 'partner.introspection.json'), `${JSON.stringify(introspection, null, 2)}\n`);
  await writeFile(path.join(outDir, 'partner.graphql'), `${printSchema(schema)}\n`);
  console.log(`schema written to ${outDir}`);
}

main().catch((error) => {
  console.error(`fetch-schema failed: ${error.message}`);
  process.exit(1);
});
