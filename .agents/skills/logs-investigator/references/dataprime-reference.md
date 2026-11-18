# DataPrime Query Reference

Quick reference for building Coralogix DataPrime queries. For full documentation, call `read_dataprime_intro_docs`.

## Query Structure

Every query is a pipeline of commands separated by `|`, starting with `source logs`:

```
source logs | <command> | <command> | ...
```

## Keypath Prefixes

| Prefix | Scope | Examples |
|--------|-------|---------|
| `$d` | User data (application fields) | `$d.order_id`, `$d.message`, `$d.merchant_id` |
| `$l` | User labels | `$l.applicationname`, `$l.subsystemname` |
| `$m` | Metadata | `$m.severity`, `$m.timestamp` |

**Rule:** Never use `$d` fields without first confirming they exist via `get_schemas`.

## Core Operators

### Filtering

```
filter $d.field == 'value'
filter $d.field != 'value'
filter $d.field:contains('substring')
filter $m.severity == ERROR
filter $m.severity == ERROR || $m.severity == CRITICAL
filter $d.status == 'failed' && $d.merchant_id == 'MID123'
```

Severity values (no quotes): `DEBUG`, `VERBOSE`, `INFO`, `WARNING`, `ERROR`, `CRITICAL`

### Wildcard Search

```
wildfind 'search_term'
```

Searches across all fields. Use for entity IDs, request IDs, or any value that might appear in multiple fields.

### Aggregation

```
groupby $l.applicationname agg count() as hit_count
groupby $d.message agg count() as error_count
groupby $m.severity agg count() as log_count
```

### Ordering and Limiting

```
orderby error_count desc
orderby $m.timestamp desc
limit 100
```

### Text Matching

```
filter $d.message:string ~ 'pattern'
```

Single tilde `~` for text matching. Always cast union-type fields with `:string`.

### Count

```
source logs | filter ... | agg count() as total
```

## Common Query Templates

### Search entity across all services

```
source logs | wildfind '<ENTITY_ID>' | filter $m.severity == ERROR || $m.severity == CRITICAL
```

### Error count by service

```
source logs | filter $m.severity == ERROR || $m.severity == CRITICAL | groupby $l.applicationname agg count() as error_count | orderby error_count desc
```

### Error count by message for a service

```
source logs | filter $l.applicationname == '<SERVICE>' | filter $m.severity == ERROR || $m.severity == CRITICAL | groupby $d.message agg count() as error_count | orderby error_count desc
```

### Log volume by severity for a service

```
source logs | filter $l.applicationname == '<SERVICE>' | groupby $m.severity agg count() as log_count
```

### Search error pattern across all services

```
source logs | filter $m.severity == ERROR || $m.severity == CRITICAL | wildfind '<PATTERN>' | groupby $l.applicationname agg count() as hit_count | orderby hit_count desc
```

### Scope to a specific service

```
source logs | filter $l.applicationname == '<SERVICE>' | ...
```

### Scope to a k8s namespace

```
source logs | filter $d.k8s_namespace_name == '<NAMESPACE>' | ...
```

## MCP Tool Parameters

### get_logs

| Parameter | Required | Type | Description |
|-----------|----------|------|-------------|
| `query` | Yes | string | DataPrime query starting with `source logs` |
| `start_date` | Yes | string | ISO 8601 start time |
| `end_date` | Yes | string | ISO 8601 end time |
| `limit` | No | integer | Max results (default: 20) |

### get_schemas

| Parameter | Required | Type | Description |
|-----------|----------|------|-------------|
| `dataset_type` | Yes | string | `DATASET_TYPE_LOGS`, `DATASET_TYPE_SPANS`, or `DATASET_TYPE_METRICS` |
| `scopes` | Yes | array | `["$d", "$l", "$m"]` |
| `start_date` | Yes | string | ISO 8601 start time |
| `end_date` | Yes | string | ISO 8601 end time |
| `limit` | Yes | integer | Max keypaths to return (up to 1000) |
| `included_examples` | Yes | integer | Number of example values per field (0 for none) |
| `keypath_filter` | Yes | string | Fuzzy search keyword for filtering keypaths |

### get_traces

Same parameters as `get_logs`. Queries spans instead of logs. Use `source spans` in the query.

### get_datetime

No parameters. Returns current date and time. Call before any time-filtered queries.

## Common Pitfalls

1. **String literals must use single quotes** -- `'value'` not `"value"`
2. **Severity values are unquoted** -- `ERROR` not `'ERROR'`
3. **Default time range is 15 minutes** -- always set explicit start/end dates
4. **`$d` fields vary by service** -- always verify with `get_schemas` first
5. **`wildfind` is expensive** -- use field-specific filters when the field name is known
6. **Union-type fields need casting** -- use `$d.field:string` for text operations on union types
7. **Dates go back up to 3 months** from the current time
