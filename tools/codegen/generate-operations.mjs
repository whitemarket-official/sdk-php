// Operation generator, SDK_SPEC 1.2. Shared byte-for-byte by every WhiteMarket SDK.
//
// Reads schema/partner.graphql and writes, for every non-deprecated root field of PartnerQuery and PartnerMutation:
//   graphql/operations/<query|mutation>/<field>.graphql   one operation per root field
//   graphql/fragments/<Type>.graphql                      <Type>Fields for object types selected in more than one place
//   graphql/operations/CHECKSUMS                          sha256 per generated file
// A hand-written graphql/overrides/<query|mutation>/<field>.graphql replaces the generated operation of the same name.
//
// Usage: node generate-operations.mjs [--out-dir DIR] [--depth N]
//   --out-dir  repository root to write into (default: this repository); codegen-check passes a temp dir

import { createHash } from 'node:crypto';
import { existsSync } from 'node:fs';
import { mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  buildSchema,
  getNamedType,
  isInterfaceType,
  isLeafType,
  isObjectType,
  isRequiredArgument,
  isUnionType,
  Kind,
  parse,
  print,
  validate,
} from 'graphql';

const REPO_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const DEFAULT_DEPTH = 3;

// Server limits [server]: depth 11, complexity 300.
// Depth calibration [observed 2026-10-30]: the server reported 13 for a document whose deepest field path has 15 fields
// (root field and leaf included) and accepted one with 13. Server depth = fields on the deepest path - 2.
const MAX_SERVER_DEPTH = 11;
const SERVER_DEPTH_OFFSET = 2;
const MAX_COMPLEXITY = 300;

const PAGE_INFO_FIELDS = ['endCursor', 'hasNextPage', 'hasPreviousPage', 'startCursor'];

function parseArgs(argv) {
  const args = { outDir: REPO_ROOT, depth: DEFAULT_DEPTH };
  for (let i = 0; i < argv.length; i += 1) {
    if (argv[i] === '--out-dir') args.outDir = path.resolve(argv[++i]);
    else if (argv[i] === '--depth') args.depth = Number.parseInt(argv[++i], 10);
    else throw new Error(`Unknown argument: ${argv[i]}`);
  }
  if (!Number.isInteger(args.depth) || args.depth < 1) throw new Error('--depth must be a positive integer');
  return args;
}

const isDeprecated = (fieldOrValue) => fieldOrValue.deprecationReason != null;
const pascalCase = (name) =>
  name
    .split('_')
    .filter((part) => part !== '')
    .map((part) => part[0].toUpperCase() + part.slice(1))
    .join('');

const isConnection = (type) => isObjectType(type) && type.name.endsWith('Connection') && 'edges' in type.getFields();
const isComposite = (type) => isObjectType(type) || isInterfaceType(type) || isUnionType(type);

// Fields that can be selected without inputs, in name order.
function selectableFields(type) {
  return Object.values(type.getFields())
    .filter((field) => !isDeprecated(field) && !field.args.some(isRequiredArgument))
    .sort((a, b) => (a.name < b.name ? -1 : a.name > b.name ? 1 : 0));
}

const leafFieldNames = (type) =>
  selectableFields(type)
    .filter((field) => isLeafType(getNamedType(field.type)))
    .map((field) => field.name);

// --- selection model -------------------------------------------------------------------------------------------------
// A selection is { spreads: [typeName], fields: [{name, selection?}], inline: [{typeName, selection}] }.

class Builder {
  constructor(schema, maxDepth) {
    this.schema = schema;
    this.maxDepth = maxDepth;
    this.usage = new Map(); // object type name -> number of places its leaf fields are selected
  }

  object(type, level) {
    if (isConnection(type)) return this.connection(type, level);
    const selection = { spreads: [], fields: [], inline: [] };
    const leaves = leafFieldNames(type);
    if (leaves.length > 0) {
      selection.spreads.push(type.name);
      this.usage.set(type.name, (this.usage.get(type.name) ?? 0) + 1);
    }
    for (const field of selectableFields(type)) {
      const named = getNamedType(field.type);
      if (isLeafType(named)) continue;
      const nested = this.nested(named, level);
      if (nested !== null) selection.fields.push({ name: field.name, selection: nested });
    }
    return selection;
  }

  // Object, interface or union field below the root: recurse while under the depth limit, otherwise `id` or skip.
  // An interface at the limit selects `__typename id`, so the response still names the concrete type.
  nested(type, level) {
    if (level < this.maxDepth) return this.composite(type, level + 1);
    if (isObjectType(type) || isInterfaceType(type)) {
      const id = type.getFields().id;
      if (id !== undefined && isLeafType(getNamedType(id.type)) && !isDeprecated(id)) {
        const fields = isInterfaceType(type) ? [{ name: '__typename' }, { name: 'id' }] : [{ name: 'id' }];
        return { spreads: [], fields, inline: [] };
      }
    }
    return null;
  }

  composite(type, level) {
    if (isObjectType(type)) return this.object(type, level);
    const selection = { spreads: [], fields: [{ name: '__typename' }], inline: [] };
    for (const possible of this.schema.getPossibleTypes(type)) {
      selection.inline.push({ typeName: possible.name, selection: this.object(possible, level) });
    }
    return selection;
  }

  // Connections are transparent for the depth count: the node is selected at the connection's level.
  connection(type, level) {
    const fields = type.getFields();
    const edgeType = getNamedType(fields.edges.type);
    const nodeType = getNamedType(edgeType.getFields().node.type);
    const edgeFields = [];
    if ('cursor' in edgeType.getFields()) edgeFields.push({ name: 'cursor' });
    edgeFields.push({ name: 'node', selection: this.composite(nodeType, level) });
    const selection = { spreads: [], fields: [{ name: 'edges', selection: { spreads: [], fields: edgeFields, inline: [] } }], inline: [] };
    if ('pageInfo' in fields) {
      const pageInfoType = getNamedType(fields.pageInfo.type);
      const names = PAGE_INFO_FIELDS.filter((name) => name in pageInfoType.getFields());
      selection.fields.push({ name: 'pageInfo', selection: { spreads: [], fields: names.map((name) => ({ name })), inline: [] } });
    }
    if ('totalCount' in fields) selection.fields.push({ name: 'totalCount' });
    return selection;
  }
}

// Types whose leaf fields are selected in more than one place become fragments; elsewhere the leaves are inlined.
function toAst(selection, schema, fragmentTypes) {
  const selections = [];
  for (const typeName of selection.spreads) {
    if (fragmentTypes.has(typeName)) {
      selections.push({ kind: Kind.FRAGMENT_SPREAD, name: { kind: Kind.NAME, value: `${typeName}Fields` } });
    } else {
      for (const name of leafFieldNames(schema.getType(typeName))) selections.push(fieldAst(name));
    }
  }
  const fields = [...selection.fields].sort((a, b) => (a.name < b.name ? -1 : a.name > b.name ? 1 : 0));
  for (const field of fields) {
    selections.push(fieldAst(field.name, field.selection === undefined ? undefined : toAst(field.selection, schema, fragmentTypes)));
  }
  for (const inline of selection.inline) {
    selections.push({
      kind: Kind.INLINE_FRAGMENT,
      typeCondition: { kind: Kind.NAMED_TYPE, name: { kind: Kind.NAME, value: inline.typeName } },
      selectionSet: toAst(inline.selection, schema, fragmentTypes),
    });
  }
  return { kind: Kind.SELECTION_SET, selections };
}

function fieldAst(name, selectionSet, args = []) {
  return { kind: Kind.FIELD, name: { kind: Kind.NAME, value: name }, arguments: args, selectionSet };
}

function operationAst(operation, field, selectionSet) {
  const variableDefinitions = field.args.map((arg) => ({
    kind: Kind.VARIABLE_DEFINITION,
    variable: { kind: Kind.VARIABLE, name: { kind: Kind.NAME, value: arg.name } },
    type: arg.astNode.type,
    defaultValue: arg.astNode.defaultValue,
  }));
  const args = field.args.map((arg) => ({
    kind: Kind.ARGUMENT,
    name: { kind: Kind.NAME, value: arg.name },
    value: { kind: Kind.VARIABLE, name: { kind: Kind.NAME, value: arg.name } },
  }));
  return {
    kind: Kind.DOCUMENT,
    definitions: [
      {
        kind: Kind.OPERATION_DEFINITION,
        operation,
        name: { kind: Kind.NAME, value: pascalCase(field.name) },
        variableDefinitions,
        selectionSet: { kind: Kind.SELECTION_SET, selections: [fieldAst(field.name, selectionSet, args)] },
      },
    ],
  };
}

// --- limits ----------------------------------------------------------------------------------------------------------

function usedFragments(document, fragments, seen = new Set()) {
  const visit = (selectionSet) => {
    for (const selection of selectionSet?.selections ?? []) {
      if (selection.kind === Kind.FRAGMENT_SPREAD && !seen.has(selection.name.value)) {
        seen.add(selection.name.value);
        visit(fragments.get(selection.name.value).selectionSet);
      } else {
        visit(selection.selectionSet);
      }
    }
  };
  for (const definition of document.definitions) visit(definition.selectionSet);
  return seen;
}

function measure(selectionSet, fragments, depth) {
  let maxDepth = depth;
  let fieldCount = 0;
  for (const selection of selectionSet?.selections ?? []) {
    let result;
    if (selection.kind === Kind.FIELD) {
      result = measure(selection.selectionSet, fragments, depth + 1);
      result.fieldCount += 1;
      result.maxDepth = Math.max(result.maxDepth, depth + 1);
    } else {
      const target = selection.kind === Kind.FRAGMENT_SPREAD ? fragments.get(selection.name.value) : selection;
      result = measure(target.selectionSet, fragments, depth);
    }
    maxDepth = Math.max(maxDepth, result.maxDepth);
    fieldCount += result.fieldCount;
  }
  return { maxDepth, fieldCount };
}

function checkDocument(name, document, schema, fragments) {
  const used = usedFragments(document, fragments);
  const full = { kind: Kind.DOCUMENT, definitions: [...document.definitions, ...[...used].sort().map((n) => fragments.get(n))] };
  const errors = validate(schema, full).map((error) => error.message);
  const operation = document.definitions.find((definition) => definition.kind === Kind.OPERATION_DEFINITION);
  // Complexity estimate: 1 per field; `first` defaults to 1 (no multiplier) because generated operations pass it as a variable.
  const { maxDepth, fieldCount } = measure(operation.selectionSet, fragments, 0);
  const serverDepth = maxDepth - SERVER_DEPTH_OFFSET;
  if (serverDepth > MAX_SERVER_DEPTH) errors.push(`depth ${serverDepth} > ${MAX_SERVER_DEPTH}`);
  if (fieldCount > MAX_COMPLEXITY) errors.push(`estimated complexity ${fieldCount} > ${MAX_COMPLEXITY}`);
  if (errors.length > 0) {
    throw new Error(`${name}: ${errors.join('; ')}. Add a hand-written override in graphql/overrides/ (SDK_SPEC 1.2).`);
  }
}

// --- output ----------------------------------------------------------------------------------------------------------

const HEADER = '# DO NOT EDIT, generated by tools/codegen/generate-operations.mjs from schema/partner.graphql\n';

async function readOverride(kind, fieldName) {
  const file = path.join(REPO_ROOT, 'graphql', 'overrides', kind, `${fieldName}.graphql`);
  return existsSync(file) ? readFile(file, 'utf8') : null;
}

async function main() {
  const { outDir, depth } = parseArgs(process.argv.slice(2));
  const schema = buildSchema(await readFile(path.join(REPO_ROOT, 'schema', 'partner.graphql'), 'utf8'));
  const builder = new Builder(schema, depth);

  const roots = [
    ['query', schema.getQueryType()],
    ['mutation', schema.getMutationType()],
  ];
  const planned = [];
  for (const [kind, rootType] of roots) {
    for (const field of selectableFieldsOfRoot(rootType)) {
      const named = getNamedType(field.type);
      planned.push({ kind, field, selection: isComposite(named) ? builder.composite(named, 1) : null });
    }
  }

  const fragmentTypes = new Set([...builder.usage].filter(([, count]) => count > 1).map(([name]) => name));
  const fragments = new Map(
    [...fragmentTypes].sort().map((typeName) => [
      `${typeName}Fields`,
      {
        kind: Kind.FRAGMENT_DEFINITION,
        name: { kind: Kind.NAME, value: `${typeName}Fields` },
        typeCondition: { kind: Kind.NAMED_TYPE, name: { kind: Kind.NAME, value: typeName } },
        selectionSet: { kind: Kind.SELECTION_SET, selections: leafFieldNames(schema.getType(typeName)).map((name) => fieldAst(name)) },
      },
    ]),
  );

  const files = new Map(); // relative path -> content
  for (const [name, fragment] of fragments) {
    const typeName = name.slice(0, -'Fields'.length);
    files.set(`graphql/fragments/${typeName}.graphql`, `${HEADER}${print(fragment)}\n`);
  }

  const failures = [];
  for (const { kind, field, selection } of planned) {
    const relative = `graphql/operations/${kind}/${field.name}.graphql`;
    const override = await readOverride(kind, field.name);
    let document;
    let content;
    if (override !== null) {
      document = parse(override);
      content = override.endsWith('\n') ? override : `${override}\n`;
    } else {
      document = operationAst(kind, field, selection === null ? undefined : toAst(selection, schema, fragmentTypes));
      content = `${HEADER}${print(document)}\n`;
    }
    try {
      checkDocument(`${kind} ${field.name}`, document, schema, fragments);
    } catch (error) {
      failures.push(error.message);
    }
    files.set(relative, content);
  }
  const checksums = [...files.keys()]
    .sort()
    .map((relative) => `${createHash('sha256').update(files.get(relative)).digest('hex')}  ${relative}`)
    .join('\n');
  files.set('graphql/operations/CHECKSUMS', `${checksums}\n`);

  for (const dir of ['graphql/operations', 'graphql/fragments']) {
    await rm(path.join(outDir, dir), { recursive: true, force: true });
  }
  for (const [relative, content] of files) {
    const target = path.join(outDir, relative);
    await mkdir(path.dirname(target), { recursive: true });
    await writeFile(target, content);
  }
  // Files are written even on failure so the invalid documents can be inspected; the exit code still fails.
  if (failures.length > 0) {
    throw new Error(`invalid operations:\n  ${failures.join('\n  ')}`);
  }
  const operations = planned.length;
  console.log(`${operations} operations, ${fragments.size} fragments written to ${outDir}`);
}

function selectableFieldsOfRoot(rootType) {
  return Object.values(rootType.getFields())
    .filter((field) => !isDeprecated(field))
    .sort((a, b) => (a.name < b.name ? -1 : a.name > b.name ? 1 : 0));
}

// Exported for tests; the script runs main() only when executed directly.
export { pascalCase };

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  main().catch((error) => {
    console.error(`generate-operations failed: ${error.message}`);
    process.exit(1);
  });
}
