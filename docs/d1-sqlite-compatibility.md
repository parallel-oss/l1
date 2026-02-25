# D1 vs SQLite Compatibility Matrix

This package aims to provide a transparent Laravel experience on top of Cloudflare D1, while explicitly handling D1 constraints that differ from local/native SQLite usage.

## Mental model

- D1 uses SQLite SQL semantics, but execution is remote (HTTP API / Worker binding), not a local file/socket PDO driver.
- Some SQLite assumptions (large bind counts, transaction behavior across requests, unrestricted pragmas) do not hold in D1 and must be handled at the integration layer.

## Runtime compatibility matrix

| Area | D1 behavior / limit | Package handling | Notes |
| --- | --- | --- | --- |
| Bound parameters per query | Max **100** bind parameters | `auto-handled` for large multi-row `INSERT`; `guarded/fail-fast` for over-limit non-INSERT | `D1Connection` chunks safe INSERTs and rejects unsafe over-limit statement shapes early with guidance. |
| SQL statement length | Max **100 KB** per statement | `user-responsibility` | SQL generators should avoid oversized statements; package surfaces D1 errors consistently. |
| String/BLOB/row size | Max **2 MB** | `user-responsibility` | Payload/data design concern; package does not rewrite large payloads. |
| Columns per table | Max **100** | `user-responsibility` | Schema design concern. |
| SQL function arguments | Max **32** | `user-responsibility` | Query design concern. |
| LIKE/GLOB pattern size | Max **50 bytes** | `user-responsibility` | Query design concern. |
| Query duration | Max **30s** for API resolution | `user-responsibility` with `auto-handled` retry for safe transient failures | Long-running writes/migrations should be batched. |
| Concurrency model | Single-threaded per DB; overload/reset errors can occur | `auto-handled` on safe retry paths; otherwise `user-responsibility` | Package retries safe paths and normalizes transient transport/error responses. |
| Read query retries (platform) | D1 can auto-retry read-only queries up to 2 times | `auto-handled` + compatible behavior | Package remains conservative for write retries and avoids unsafe automatic write replays by default. |
| PRAGMA support | Subset of SQLite PRAGMA; behavior is transaction-scoped | `user-responsibility` + `guarded/fail-fast` where relevant | Unsupported or incompatible pragmas should be treated as D1-specific behavior, not SQLite parity. |
| DDL/schema introspection tables | `sqlite_schema` is canonical; `sqlite_master` references vary by tooling | `auto-handled` | `D1SchemaGrammar` rewrites where needed for Laravel schema compatibility. |
| Transactions across HTTP requests | Request-scoped execution model differs from local SQLite PDO assumptions | `guarded/fail-fast` / documented behavior | Nested rollback semantics from local SQLite are not guaranteed across remote request boundaries. |
| Numeric precision in JS-bound paths | Large `int64` can lose precision in JS serialization paths | `user-responsibility` | Prefer textual storage/serialization for very large integers when precision is critical. |
| Import/export operations | Export has operational caveats (for example virtual table limitations) | `user-responsibility` | Operational workflow concern outside core query runtime. |

## Package behavior contract

- The package **should transparently handle** safe D1 constraints in query execution paths.
- The package **should fail fast with clear errors** when safe transparent handling is not possible.
- The package **should not silently rewrite** potentially unsafe write semantics.

## References

- D1 limits: https://developers.cloudflare.com/d1/platform/limits/
- D1 SQL statements and PRAGMA compatibility: https://developers.cloudflare.com/d1/sql-api/sql-statements/
- D1 retry guidance: https://developers.cloudflare.com/d1/best-practices/retry-queries/
- D1 result metadata (`total_attempts`, `rows_read`, etc.): https://developers.cloudflare.com/d1/worker-api/return-object/
- D1 database and batch/session APIs: https://developers.cloudflare.com/d1/worker-api/d1-database/
