---
name: pr-observability
description: "Generate a comprehensive observability plan from a GitHub PR by tracing full code flows — not just what the PR changes, but every metric and log already instrumented across all flows the PR touches. Uses repo skills, rzp-discover, and Coralogix MCP to produce validated DataPrime log queries and Prometheus metric queries. Use when asked: what should I monitor for this PR, give me observability for PR 123, logs and metrics for my changes, monitoring queries for this feature."
dependencies:
  - grafana-dashboard   # dashboard JSON templates and layout rules (dashboard-structure.md)
  - rzp-discover        # cross-service context for multi-service PRs
---

# PR Observability Skill

## Core Principle

Do NOT limit analysis to what the PR adds. A PR that changes `callback.go` means the entire callback flow needs monitoring — every metric and log instrumented anywhere in that flow, across all services it touches.

**The goal:** trace flows end-to-end → discover all existing instrumentation → generate queries for all of it.

---

## Multi-PR Handling

If more than one PR URL is provided in ARGUMENTS, **never process them in a single pass** — the second PR will be under-covered as context fills up.

**Required approach: one subagent per PR.**

1. Immediately spawn one `Agent` (subagent_type=general) per PR — each runs Steps 1-6 of this skill independently and in parallel
2. Pass each subagent exactly one PR URL plus the full skill instructions
3. Each subagent writes its own output files:
   - `<repo-root>/<pr-slug>-dashboard.json`
   - `<repo-root>/<pr-slug>-coralogix-queries.md`
4. Main agent waits for all subagents to finish, then outputs a combined Coverage Map:

```
## Combined Coverage Map

| PR | Flows touched | Dashboard file | Coralogix file |
|----|--------------|----------------|----------------|
| #N | <flow>       | <file>         | <file>         |
```

Do not proceed to Step 1 below until this check is done. If ARGUMENTS contains multiple URLs, dispatch subagents and stop.

---

## Workflow

### Step 1: Fetch PR Context

```bash
gh pr view <PR_NUMBER_OR_URL> --json title,body,files,headRefName,url
gh pr diff <PR_NUMBER_OR_URL>
```

Note: which files changed, which packages, which functions were added/modified/deleted.

---

### Step 2: Load Domain Context

**Always do both in parallel:**

**A. Repo skill** — If a repo skill exists at `.agents/skills/repo-skill/SKILL.md`, load it. The repo skill's own progressive disclosure instructions will tell you which domain modules to load based on what the PR touches. Follow those instructions — do not hardcode domain paths.

**B. Cross-service context** — If the PR touches integrations (Kafka, HTTP calls to other services, shared libs), invoke `rzp-discover:brainstorm` to understand upstream/downstream services involved.

---

### Step 3: Map PR Changes to Flows

Using the repo skill flow docs and the PR diff, identify:
- **Which named flows** are affected (e.g., "Flow 1: Payment Create", "Flow 2: Callback Processing")
- **Where in each flow** the PR change lands (early? middleware? terminal step?)
- **Which services** participate in each affected flow (pg-router, stork, api, payments-upi, ksf, etc.)

**Also determine the query scope for each flow:**
- Is the PR's change only visible in a subset of traffic (e.g. gated by a specific dimension — a channel, a provider, a route, or any other label)?
- If yes: find the label on the primary metric that scopes to that subset — label name and value come from the registered label slice in code (Pass A), never assumed
- If no scoping label exists on the metric: queries for that flow use service-wide aggregates

**This scope applies to ALL queries written in Step 5 for that flow** — not just Key Health Stats, but latency, upstream, error codes, everything.

**If Step 3 identifies two or more independent flows**, each flow gets its own scope. Do not merge them. Step 6 will give each flow its own dashboard row.

This determines the scope of instrumentation discovery in Step 4.

---

### Step 4: Discover ALL Instrumentation Across Affected Flows

> **TWO-PASS RULE — both passes are mandatory, in this order:**
> 1. **Pass A — Infra metrics** (run first, no topic filter): find every metric registered in the service; identify which have scoping labels; always include them in the dashboard
> 2. **Pass B — Feature instrumentation** (topic-filtered): find PR-specific metrics, logs, Kafka events, outbound calls in the affected call chain
>
> A dashboard missing Pass A is incomplete regardless of how many feature metrics it includes. Never skip Pass A.

---

#### Pass A — Infra Metrics (MANDATORY, run first)

Run these three commands with **no topic filter** against any service's codebase:

```bash
# 1. Find every registered metric — no filter
grep -rn "NewCounterVec\|NewHistogramVec\|NewGaugeVec\|NewCounter\b\|NewHistogram\b\|NewGauge\b" \
  internal/ --include="*.go" | grep -v "_test.go"

# 2. For every metric found, pull its label slice
grep -rn "\[\]string{" internal/metrics/ --include="*.go" | grep -v "_test.go"

# 3. Find error code metrics — search for where internal/application error codes are set as labels
grep -rn "error_code\|errorCode\|internalErrorCode\|error_type\|errorType\|err_code" \
  internal/ --include="*.go" | grep -v "_test.go" | grep -v "//.*grep"
```

From the results, read the actual label slice registered for each metric. Do not assume label names — they vary by service and metric.

For each metric found, apply this two-step filter — in order:

**Step 0 — Relevance gate (apply first):**
> Is this metric instrumented in a code path that the PR touches or could plausibly affect? If no — skip it entirely, regardless of how well it scopes to the flow.
>
> A metric is relevant only if:
> - The PR's changed code calls it directly, OR
> - The PR's change could cause it to change at a detectable level (not drowned by unrelated traffic), AND
> - It can be scoped to the PR's specific dimension (label exists to isolate the affected subset)
>
> If either condition fails — skip the metric. Do not add panels for metrics that measure code paths the PR doesn't touch.

**Step 1 — Observability requirements (apply only if metric passes Step 0):**

Determine what **observability requirement** it can satisfy. There are four requirements; check each one:

| Requirement | What you need | How to check |
|---|---|---|
| **Flow scoping** | A label whose values identify this specific route/endpoint/operation | Read the label slice from code; run `sum(rate(metric[5m])) by (label)` in Prometheus to see actual values — do any values correspond to the affected flow? |
| **Traffic volume** | A label whose values distinguish response outcomes (success vs error class) | Same — look for a label carrying HTTP status codes or success/failure strings |
| **Error identity** | A label whose values identify *which specific error* occurred | Look for a label carrying error code names or internal error identifiers |
| **Upstream scoping** | A label that identifies the upstream dependency AND a label that scopes to the flow | Both must exist; if the metric lacks flow scoping (e.g. pushed from a shared RoundTripper with no route context), a filtered upstream panel is not possible — note the limitation and use the log signal at the call site instead |

**Never hardcode assumed label names.** Always read them from the registered label slice in code, then validate actual values via Prometheus before writing any query.

**What to build from what you find:**
- Metric satisfies flow scoping + traffic volume → Traffic panel + Key Health Stats panel
- Metric satisfies flow scoping + error identity → Error Codes panel
- Metric satisfies flow scoping + latency (histogram) → Latency panel
- Metric satisfies upstream dependency but NOT flow scoping → no metric panel; use log signal from call site instead
- Metric satisfies none → skip it for this PR's dashboard

---

#### Pass B — Feature Instrumentation (topic-filtered)

For each affected flow, trace the complete call chain and grep every file in that chain for existing metrics and log instrumentation.

**Do not limit to PR-changed files.** If the flow touches 8 files, grep all 8.

Load `references/rzp-instrumentation-patterns.md` for grep patterns and what to look for.

```bash
# Metric calls in affected packages (use actual paths from Step 3)
grep -rn "metrics\.\|Increment\|Timing\|Gauge\|NewCounter\|NewHistogram" \
  <affected_package_paths>

# Structured log calls
grep -rn "\.Errorw\|\.Infow\|\.Warnw\|\.Debugw\|logger\." \
  <affected_package_paths>

# Kafka / event emission
grep -rn "Produce\|PublishEvent\|EmitEvent" internal/

# Outbound HTTP calls (latency to monitor)
grep -rn "\.Do(\|\.Post(\|\.Get(\|httpClient\.\|client\." internal/
```

Collect all instrumentation found — metric names, log message constants, Kafka topic names, error codes emitted.

---

### Step 5: Build Queries for All Discovered Instrumentation

For every metric and log found in Step 4:

**Prometheus metrics:**
- Before writing any query, verify two things from code:
  1. **Full metric name** — grep for `PrometheusNamespace` in `internal/metrics/` → full name = `{namespace}_{metric_name}_total`
  2. **Label values** — for each label, trace the function that sets it: if a constant → use that exact value; if `os.Getenv(...)` → grep kube-manifests for that env var name across all `values.yaml` files to find every deployed value
- Load `references/prometheus-patterns.md` for naming rules, label verification steps, and query templates
- Validate each query via **Coralogix MCP** (Prometheus endpoint) — remove panels whose queries return no data, **unless** the metric is newly introduced by this PR (pre-deploy). Pre-deploy metrics are expected to have no data today — keep them, they will fire post-deploy.

**Coralogix DataPrime log queries:**
- Load `references/dataprime-syntax.md` — run the discovery workflow for every repo:
  - Grep kube-manifests for `coralogix-subsytem` label → find subsystemname values
  - Grep code for `MessageKey` → determine whether message field is `$d.message` or `$d.msg`
  - Grep context field injectors → find available `$d.context.*` fields
  - Validate via Coralogix MCP with a broad `limit 5` query before writing full queries
  - `dataprime-syntax.md` includes a verified pg-router example showing what this discovery produces
- Do NOT filter by `level` — monitor all log levels for a flow, not just errors
- Validate each query via **Coralogix MCP** (logs endpoint) before including in output

---

### Step 6: Output the Observability Plan + Dashboard

**Always output both** — the observability plan AND a Grafana dashboard JSON file. Do not wait to be asked.

```markdown
## Observability Plan for PR #<N>: <title>

### Flows Affected
- **<Flow Name>** (<service> → <service>): <one-line description of what changed and where>

---

### Metrics to Monitor

#### <metric_name> — <service> — <flow>
**Instrumented at:** `<file>:<line>`
**Why:** <what failure mode this surfaces for this PR>
**Prometheus query:**
```promql
<validated full query>
```
**Alert:** <threshold suggestion>

---

### Logs to Monitor

#### <log pattern / error constant> — <service> — <flow>
**Instrumented at:** `<file>:<line>`
**Why:** <what this log reveals in context of this PR>
**Coralogix Lucene query:**
```
k8s_namespace_name:"<service>" AND subsystemname:"<subsystem>" AND message:"<trace constant>"
```

---

### Coverage Map
| Flow | Service | Metrics covered | Logs covered |
|------|---------|----------------|-------------|
| <flow> | <svc> | N | M |

### Alert Recommendations
- [ ] <metric> > <threshold> for <duration>

### What to Monitor
- **Primary signal:** <what to watch first post-deploy>
- **Spike/drop means:** <what degradation looks like>
- **Normal looks like:** <expected baseline behaviour>
- <any PR-specific signal worth calling out>

---

### Dashboard Panels (if generating a Grafana dashboard)

When generating a dashboard, output two things alongside the JSON:

**1. What to monitor** — 3-5 bullet points:
- The primary signal to watch first post-deploy
- What a spike/drop means in context of this PR
- What normal looks like vs degraded

**2. Panel type — stat vs timeseries:**

Use **stat** only when a single number is sufficient and trend is not needed. Use **timeseries** for anything that changes over the time range and needs trend visibility.

| Signal | Panel type | Query pattern |
|---|---|---|
| Ratio / rate that trends (success/error ratio, hit rate) | **timeseries** | `rate()` or ratio over `$__rate_interval` |
| "Did this happen?" (panic, unexpected event) | **stat** | `increase()` over `$__range` — expected 0 |
| Current state (gauge, queue depth) | **stat** | Instant value |
| Percentiles (p50/p95/p99) | **timeseries** | `histogram_quantile()` over time |

Never add a stat that duplicates what a time series already shows.

**3. Panel rules:**

All panel type decisions, mandatory panel checklist, signal type classification, Y-axis rules, color conventions, and complexity limits are defined in `references/dashboard-decisions.md`. Load it before generating any dashboard JSON.

Key rules summarised:
- Every dashboard must have a **Traffic panel** (2xx + 4xx + 5xx rates) and a **Health panel** (success + 4xx + 5xx ratios) — always paired, never one without the other
- Every metric discovered in Pass B must be classified by signal type before deciding its panel — operations with success/failure → Traffic + Health model; saturation/resource/latency → single panel
- 4xx and 5xx are always separate series — never collapsed into a single "error" line
- Error code breakdown is mandatory when the metric exists
- No stat panels — timeseries always
- Max 3–4 rows, max 2–3 panels per row

**4. Dashboard file:**
- Write to `<repo-root>/<pr-slug>-dashboard.json`
- Dashboard `title` field format: `<service-name> — PR #<N>: <pr-title>` — service name always first, then PR number and title
- **Panel titles must never contain hardcoded baseline values** (e.g. no `"baseline ~99.4%"`, `"p50≈914ms"`). Baseline values change after every deploy and titles go stale. Use the panel title only for the signal name and brief qualifier — let the live graph show the actual values.
- **Datasource default — always discover before writing the variable:** Call `grafana:list_datasources` (or `mcp__grafana__list_datasources`) before writing the datasource template variable. Find the prometheus-compatible datasource name (e.g. `promxy`, `Prometheus`, `thanos`). Set `current.text` and `current.value` in the datasource variable to that name so the dashboard loads with the correct datasource without user intervention. Never leave the default as `"default"` — it will silently load the wrong datasource on import.
  ```json
  // Correct — default set to actual datasource name discovered via Grafana MCP
  "current": { "selected": true, "text": "promxy", "value": "promxy" }

  // Wrong — user must manually change every time
  "current": { "selected": false, "text": "default", "value": "default" }
  ```
- Load `references/dashboard-decisions.md` for layout rules, row selection, and panel type decisions
- Load `grafana-dashboard` skill's `references/dashboard-structure.md` for JSON panel templates and variable templates

**5. Coralogix queries file:**
- Always write a separate `<repo-root>/<pr-slug>-coralogix-queries.md` alongside the dashboard
- Use DataPrime syntax (load `references/dataprime-syntax.md`)
- Keep queries simple: **subsystemname + trace code only**. No field-specific filters, no breakdowns. One query per trace constant.
- Structure:
  ```
  ## Coralogix Queries — PR #<N>: <title>

  ### What to monitor
  <1-2 lines: when to open this file>

  ### <Signal Name>
  ```dataprime
  source logs
  | filter $l.subsystemname == '<subsystem>'
  | filter $d.message == '<TRACE_CONSTANT>'
  ```

  ### <Next Signal>
  ...
  ```
- Group by signal name (what it tells you), not by log constant name
- Paste-and-go: no placeholders to fill in except optionally a merchant/payment ID

---

## Reference Files

- **`references/rzp-instrumentation-patterns.md`** — Razorpay Go metric/log instrumentation patterns, grep commands, how to map discovered code to query fields. Load in Step 4.
- **`references/prometheus-patterns.md`** — Razorpay Prometheus metric naming, label conventions, per-service query templates. Load in Step 5.
- **`references/dataprime-syntax.md`** — DataPrime operators, pg-router verified field structure + applicationname discovery, discovery workflow for other repos. Load in Step 5.
