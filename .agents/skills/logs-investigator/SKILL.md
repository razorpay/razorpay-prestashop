---
name: logs-investigator
description: Investigates production issues by querying Coralogix logs across all Razorpay services. Supports entity lookup (orders, payments, refunds, merchants), error spike analysis, service health checks, error pattern search across services, and cross-service request tracing. Use when debugging production issues, looking up an order or payment ID, investigating error spikes, checking service health, searching for specific error patterns, or tracing a request across services.
---

# Logs Investigator

General-purpose production investigation skill using Coralogix logs. Supports five investigation modes that cover the most common debugging workflows at Razorpay.

## Prerequisites

The Coralogix MCP server must be connected. If tools like `get_logs` are unavailable, instruct the user to check their MCP server configuration.

## Usage

Invoke this skill when:
- Debugging a specific order, payment, or refund by ID
- Investigating why a service is throwing errors
- Checking if a service is healthy after a deployment
- Searching for a specific error pattern (e.g., "redis timeout") across services
- Tracing a request through multiple services using a request ID

## Examples

- "What happened to order_Lxxxxxxxxxx?"
- "Why are errors spiking on checkout-service?"
- "Is magic-checkout-service healthy?"
- "Are we seeing circuit breaker errors anywhere?"
- "Trace request_id abc123def across services"

## Before Any Investigation

1. Call `get_datetime` to get the current time. All queries require explicit `start_date` and `end_date` in ISO 8601 format.
2. Default time ranges by mode:
   - Entity lookup: last 24 hours
   - Error spike: last 1 hour
   - Service health: last 1 hour (with 1-hour baseline from yesterday)
   - Pattern search: last 1 hour
   - Request tracing: last 24 hours
3. If unfamiliar with the service's field names, run schema discovery first (see below).

### Schema Discovery

Run when unsure about available fields for a service:

```json
get_schemas({
  "dataset_type": "DATASET_TYPE_LOGS",
  "scopes": ["$d", "$l", "$m"],
  "start_date": "<7 days ago>",
  "end_date": "<now>",
  "limit": 50,
  "included_examples": 3,
  "keypath_filter": "<keyword>"
})
```

Use `keypath_filter` values like `order`, `payment`, `merchant`, `error`, `status`, or `application` to find relevant fields. Store discovered field names for use in subsequent queries.

### DataPrime Syntax

For query syntax, operators, and common patterns, see [references/dataprime-reference.md](references/dataprime-reference.md).

For first-time use or when encountering syntax errors, call `read_dataprime_intro_docs` to load full DataPrime documentation.

---

## Mode 1: Entity Lookup

**Trigger:** User mentions an order ID, payment ID, refund ID, merchant ID, or similar entity identifier.

**Auto-detect entity type from prefix:**

| Prefix | Entity | Example |
|--------|--------|---------|
| `order_` | Order | `order_Lxxxxxxxxxx` |
| `pay_` | Payment | `pay_Lxxxxxxxxxx` |
| `rfnd_` | Refund | `rfnd_Lxxxxxxxxxx` |
| `txn_` | Transaction | `txn_Lxxxxxxxxxx` |
| No prefix, 14-char alphanumeric | Merchant ID | `GFqH3SMNpZfT6p` |

### Workflow

**Step 1 -- Error logs first.** Query only ERROR and CRITICAL severity:

```json
get_logs({
  "query": "source logs | wildfind '<ENTITY_ID>' | filter $m.severity == ERROR || $m.severity == CRITICAL",
  "start_date": "<start>",
  "end_date": "<end>",
  "limit": 100
})
```

If results are found, proceed to Step 3 (categorize and report). **Do NOT run Step 2 if errors were found.**

**Step 2 -- All logs (only if Step 1 returned zero results):**

```json
get_logs({
  "query": "source logs | wildfind '<ENTITY_ID>'",
  "start_date": "<start>",
  "end_date": "<end>",
  "limit": 100
})
```

**Step 3 -- Categorize and report.** For each log entry:
1. Note the `$l.applicationname` (which service produced it)
2. Note the `$m.timestamp` (build a timeline)
3. Categorize errors using [references/error-patterns.md](references/error-patterns.md)
4. If errors originate from a downstream service, run a cross-service trace (Mode 5) using any `request_id` or `task_id` found in the logs

**Output format:**

```
ENTITY: <entity_id>
TYPE: <order/payment/refund/merchant>
TIME RANGE: <start> to <end>

TIMELINE:
  <timestamp> | <service> | <event description>
  <timestamp> | <service> | <event description>
  ...

SERVICES INVOLVED: <list>

ERRORS FOUND: <count>
  Category: <category from error-patterns.md>
  Details: <specific error message>

ROOT CAUSE: <assessment based on error category and timeline>
```

### Broadening the Search

If both steps return nothing:
1. Widen the time range (try last 7 days)
2. Try a partial match: `wildfind '<partial_id>'`
3. Try scoping to a specific service if the user knows which one: `filter $l.applicationname == '<service>'`

---

## Mode 2: Error Spike Analysis

**Trigger:** User mentions error spikes, elevated errors, increased failures, or asks why a service is failing.

### Workflow

**Step 1 -- Current error counts by message:**

```json
get_logs({
  "query": "source logs | filter $l.applicationname == '<SERVICE>' | filter $m.severity == ERROR || $m.severity == CRITICAL | groupby $d.message agg count() as error_count | orderby error_count desc",
  "start_date": "<1 hour ago>",
  "end_date": "<now>",
  "limit": 50
})
```

**Step 2 -- Baseline error counts (same window yesterday):**

```json
get_logs({
  "query": "source logs | filter $l.applicationname == '<SERVICE>' | filter $m.severity == ERROR || $m.severity == CRITICAL | groupby $d.message agg count() as error_count | orderby error_count desc",
  "start_date": "<25 hours ago>",
  "end_date": "<24 hours ago>",
  "limit": 50
})
```

**Step 3 -- Error samples for the top spiking error:**

```json
get_logs({
  "query": "source logs | filter $l.applicationname == '<SERVICE>' | filter $m.severity == ERROR || $m.severity == CRITICAL | filter $d.message == '<TOP_ERROR_MESSAGE>'",
  "start_date": "<1 hour ago>",
  "end_date": "<now>",
  "limit": 20
})
```

**Step 4 -- Analyze and report:**

1. Compare current vs baseline counts for each error type
2. Compute spike ratio: `current_count / baseline_count`
3. Flag errors with spike ratio > 2x
4. Identify new errors (present now, absent in baseline)
5. Categorize errors using [references/error-patterns.md](references/error-patterns.md)
6. Extract affected entities (merchant IDs, payment methods) from error samples

**Output format:**

```
SERVICE: <service_name>
PERIOD: <start> to <end>
BASELINE: <baseline_start> to <baseline_end>

ERROR SUMMARY:
  Error Type              Current    Baseline    Spike    Category
  <error_message>         <N>        <N>         <ratio>x <category>
  ...

NEW ERRORS (not in baseline):
  <error_message> -- <count> occurrences -- <category>

AFFECTED ENTITIES:
  Merchants: <list or count>
  Payment methods: <list if available>

ASSESSMENT: <root cause hypothesis based on error categories and patterns>
RECOMMENDATION: <actionable next steps>
```

---

## Mode 3: Service Health Check

**Trigger:** User asks how a service is doing, requests a health check, or wants to verify service status after a deployment.

### Workflow

**Step 1 -- Current log volume by severity:**

```json
get_logs({
  "query": "source logs | filter $l.applicationname == '<SERVICE>' | groupby $m.severity agg count() as log_count",
  "start_date": "<1 hour ago>",
  "end_date": "<now>",
  "limit": 20
})
```

**Step 2 -- Baseline log volume (same window yesterday):**

```json
get_logs({
  "query": "source logs | filter $l.applicationname == '<SERVICE>' | groupby $m.severity agg count() as log_count",
  "start_date": "<25 hours ago>",
  "end_date": "<24 hours ago>",
  "limit": 20
})
```

**Step 3 -- Top errors in current window:**

```json
get_logs({
  "query": "source logs | filter $l.applicationname == '<SERVICE>' | filter $m.severity == ERROR || $m.severity == CRITICAL | groupby $d.message agg count() as error_count | orderby error_count desc",
  "start_date": "<1 hour ago>",
  "end_date": "<now>",
  "limit": 10
})
```

**Step 4 -- Compute and report:**

1. Error rate: `(ERROR + CRITICAL) / total_logs * 100`
2. Compare current error rate vs baseline error rate
3. Flag if error rate increased by more than 50%
4. List top errors with counts

**Output format:**

```
SERVICE: <service_name>
STATUS: <HEALTHY / DEGRADED / CRITICAL>

CURRENT (last 1 hour):
  Total logs: <N>
  Error rate: <X>%
  Severity breakdown: INFO=<N>, WARNING=<N>, ERROR=<N>, CRITICAL=<N>

BASELINE (yesterday same hour):
  Total logs: <N>
  Error rate: <X>%

CHANGE: Error rate <increased/decreased> from <X>% to <Y>%

TOP ERRORS:
  1. <message> -- <count>
  2. <message> -- <count>
  ...

VERDICT: <assessment>
```

Status thresholds:
- **HEALTHY:** Error rate < 1% and not more than 1.5x baseline
- **DEGRADED:** Error rate 1-5% or 1.5-3x baseline
- **CRITICAL:** Error rate > 5% or > 3x baseline

---

## Mode 4: Error Pattern Search

**Trigger:** User searches for a specific error type across services (e.g., "redis timeouts", "circuit breaker", "kafka errors").

### Workflow

**Step 1 -- Search across all services:**

```json
get_logs({
  "query": "source logs | filter $m.severity == ERROR || $m.severity == CRITICAL | wildfind '<PATTERN>' | groupby $l.applicationname agg count() as hit_count | orderby hit_count desc",
  "start_date": "<1 hour ago>",
  "end_date": "<now>",
  "limit": 30
})
```

**Step 2 -- For the top affected service, get error samples:**

```json
get_logs({
  "query": "source logs | filter $l.applicationname == '<TOP_SERVICE>' | wildfind '<PATTERN>' | filter $m.severity == ERROR || $m.severity == CRITICAL",
  "start_date": "<1 hour ago>",
  "end_date": "<now>",
  "limit": 20
})
```

**Step 3 -- Report:**

```
PATTERN: "<search_term>"
PERIOD: <start> to <end>

AFFECTED SERVICES:
  Service                    Error Count
  <service_1>                <N>
  <service_2>                <N>
  ...

SAMPLE ERRORS (<top_service>):
  <timestamp> | <message excerpt>
  ...

CATEGORY: <from error-patterns.md>
ASSESSMENT: <cross-service vs isolated, severity, recommendation>
```

---

## Mode 5: Cross-Service Request Tracing

**Trigger:** User provides a request_id, task_id, or trace_id and wants to follow it across services. Also triggered automatically from Mode 1 when errors originate from downstream services.

### Workflow

**Step 1 -- Search the identifier across all services:**

```json
get_logs({
  "query": "source logs | wildfind '<REQUEST_ID>'",
  "start_date": "<start>",
  "end_date": "<end>",
  "limit": 100
})
```

**Step 2 -- Build the request journey:**

1. Group results by `$l.applicationname`
2. Order by `$m.timestamp`
3. For each service hop, note whether the log is INFO (normal flow) or ERROR (failure point)
4. Identify the service where the error originated

**Output format:**

```
REQUEST: <request_id>
PERIOD: <start> to <end>

JOURNEY:
  <timestamp> | <service_1> | <INFO> | <event description>
  <timestamp> | <service_2> | <INFO> | <event description>
  <timestamp> | <service_3> | <ERROR> | <error description>

FAILURE POINT: <service_name> at <timestamp>
ERROR CATEGORY: <from error-patterns.md>
ASSESSMENT: <whether failure is local to the service or caused by upstream/downstream>
```

---

## Tips

- Always set explicit `start_date` and `end_date` -- the 15-minute default is too narrow for most investigations
- For recent issues, use last 1-24 hours; for historical investigations, up to 3 months back
- Always check errors first; only fetch all logs if no errors found
- If a query fails, the error message describes how to fix it -- retry with the suggested fix
- Use `get_schemas` with relevant `keypath_filter` when unsure about field names
- For high-volume services, add `limit` to avoid overwhelming results
- When reporting to users, always categorize errors using [references/error-patterns.md](references/error-patterns.md) rather than presenting raw log messages
