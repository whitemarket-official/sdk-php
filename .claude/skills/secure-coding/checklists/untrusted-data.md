# Untrusted data checklist

Server responses, live messages (SSE/WS) and export rows are untrusted input (SDK_SPEC 8, CLAUDE.md rule 13).

### [review] UNTRUSTED-1: parsed and validated, never executed - blocker

No `eval`, `unserialize`, `include`, dynamic class instantiation from data (`new $type`), `preg_replace` with `/e`, or callable names taken from a message. Event type strings map through a fixed table.
```bash
git diff HEAD -- src | grep -nE '^\+.*\b(eval|unserialize|call_user_func|new \$|include|require)\b'
```

### [review] UNTRUSTED-2: no URLs to other hosts from data - blocker

Data never decides the host the SDK connects to. Export URLs, SSE/WS endpoints and GraphQL endpoint come from configuration; a value from a message or export row is never used to build a request URL (SSRF-like redirect of credentials).

### [review] UNKNOWN-1: unknown is not an error - blocker

New enum values, new event types, new fields deserialize into an unknown/raw variant and never throw (CLAUDE.md rule 5). A test feeds an unknown value.

### [review] LIMIT-1: size and decompression limits - blocker

GraphQL response body capped (default 32 MiB, SDK_SPEC 1.3). Non-streaming export calls cap the decompressed size (configurable); streaming parsers do not buffer the whole file. Live frames: a single message size is bounded; a malformed frame closes or skips, never grows memory unbounded.

### [review] LIMIT-2: no unbounded loops driven by the server - warning

Pagination stops on `hasNextPage = false` and also on a repeated or empty cursor; reconnect uses backoff with a cap; a server cannot make the client spin.

### [review] PATH-1: file paths - blocker

Any file written (cached exports, `.meta.json`) uses a path from configuration; names from data are never joined into paths.

### [review] LOG-DATA-1: no personal data logged - warning

Trade URLs, Steam IDs and deal data from responses are not logged at info level; debug logs follow the redaction rules.
