# Coralogix Log Queries — DataPrime Syntax

## Table of Contents
1. [Discovery Workflow](#1-discovery-workflow)
2. [DataPrime Operator Reference](#2-dataprime-operator-reference)
3. [pg-router — Example Discovery Output](#3-pg-router--example-discovery-output)

---

## 1. Discovery Workflow (run for every repo and every log)

Never assume field paths from code alone. Always discover the actual field structure from a real log before writing any filter.

### Step 1: Find the applicationname

Always use `$l.applicationname` to scope logs. In Coralogix, `applicationname` maps to the K8s namespace name. Fetch it directly from kube-manifests via `gh`:

```bash
gh api repos/razorpay/kube-manifests/contents/helmfile/charts/<repo-name>/values.yaml \
  --jq '.content' | base64 -d | grep "^namespace:"
```

Use the namespace value as the applicationname in queries. Validate via Coralogix MCP:
```dataprime
source logs
| filter $l.applicationname == '<namespace>'
| limit 5
```

### Step 2: Find the message field name

```bash
grep -rn "MessageKey" <repo>/internal/ <repo>/pkg/ --include="*.go"
```

- `MessageKey: "message"` → likely `$d.message`
- Not set → zap default → likely `$d.msg`
- Confirm in Step 4.

### Step 3: Pull a real log via Coralogix MCP

For every trace constant you want to query, fetch a real sample log first:

```dataprime
source logs
| filter $l.applicationname == '<discovered-subsystem>'
| filter $d.<message-field> == '<trace-constant>'
| limit 3
```

**Read every field in the returned log.** This is the ground truth. Do not skip this step.

### Step 4: Map fields from the real log

From the sample log, answer:

- Where do explicit args land? Are they at `$d.<key>` top level, or nested inside another field as a JSON string?
- Which fields are auto-injected context? (`$d.context.*`)
- What is the exact field name for each value you want to filter on?

**Never assume a logged key maps to `$d.<key>`** — it may be inside a JSON string field, prefixed, or named differently than the Go map key.

### Step 5: Build queries using discovered field paths

Only use field paths confirmed in Step 4. If a field is nested inside a JSON string, use substring match (`~`) instead of equality (`==`):

```dataprime
# Field confirmed at top level
| filter $d.<field> == '<value>'

# Field nested inside a JSON string field
| filter $d.<wrapper_field> ~ '<value>'
```

---

## 2. DataPrime Operator Reference

### Core Operators

| Operator | Usage |
|---|---|
| `filter` | Filter logs by condition |
| `select` | Choose fields to return |
| `limit` | Limit number of results |
| `countby` | Count grouped by field |
| `orderby` | Sort results |
| `distinct` | Unique values |
| `create` | Add computed field |

### Filter Syntax

```dataprime
# Equality
| filter $d.message == 'SOME_CONSTANT'

# Not equal
| filter $d.message != 'IGNORE_THIS'

# Substring match (contains)
| filter $d.message ~ 'PAYMENT'

# Field exists / not null
| filter $d.error != null

# Logical OR
| filter $d.message == 'A' || $d.message == 'B'

# Dot-key fields (dot notation required)
| filter $d.context.merchant_id == 'M123'

# Subsystemname (Coralogix metadata)
| filter $l.applicationname == 'pg-router'
```

### Tips

- `$d.context.field` — use dot notation for all `context.*` fields
- Always filter `$l.applicationname` first — most selective filter
- Do NOT filter by `$d.level` to restrict to errors — monitor all levels for a flow
- For other repos: validate field structure via Coralogix MCP (Step 4) before assuming any pattern

---

## 3. pg-router — Example Discovery Output

Confirmed from actual production logs. Use as a reference for what the discovery workflow produces.

### Field Namespaces in DataPrime

| Namespace | Meaning |
|---|---|
| `$l.applicationname` | Coralogix application (= K8s namespace name) |
| `$l.severity` | Coralogix severity level |
| `$d.message` | Log message — the trace constant (first arg to `Errorw`/`Infow`) |
| `$d.level` | Log level string — uppercase: `ERROR`, `INFO`, `WARN` |
| `$d.caller` | Source file and line — e.g. `core/create.go:84` |
| `$d.context.request_id` | Request ID (auto-injected) |
| `$d.context.merchant_id` | Merchant ID (auto-injected) |
| `$d.context.uri` | Request URI (auto-injected) |
| `$d.context.route_name` | Route name (auto-injected) |
| `$d.error` | Error string — top level, no prefix |
| `$d.k8s_namespace_name` | K8s namespace |

**Key facts:**
- `$d.message` — not `$d.msg`
- `context.*` fields use dot notation: `$d.context.merchant_id`
- Explicit args from `Errorw(msg, map{"key": val})` → top level `$d.<key>`
- Some log sites wrap fields inside `$d.pg_sdk_str` as a JSON string — use `~` to filter on those
