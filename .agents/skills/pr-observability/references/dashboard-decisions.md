# Dashboard Layout Decisions for PR Observability

For JSON panel templates, variable templates, and grid positioning — load the `grafana-dashboard` skill's `references/dashboard-structure.md`.

This file covers the **decision rules** specific to PR observability dashboards: which rows to include, panel count limits, and stat vs timeseries selection.

---

## Row Menu — include only what the PR instruments

Each section is a candidate. Only add a section if the PR has actual instrumentation for it. Skip everything else — empty rows add noise.

| Section | Include when |
|---------|-------------|
| Key Health Stats | always — when PR touches any HTTP/gRPC route or operation with success/failure outcome; includes Error Codes panel when metric exists |
| Latency | histogram metric exists (response time, processing duration) |
| Upstream | flow makes network calls to services outside the service boundary. Exclude in-process libraries and local DB unless explicitly treated as an external dependency |
| Saturation | queues, worker pools, Kafka lag, goroutine counts, connection pools |
| Resources | CPU, memory, GC — only when PR directly touches resource-intensive paths |
| Feature-specific | metric discovered in Pass B that represents a distinct subsystem or behavior — not a random leftover metric that doesn't fit elsewhere |

---

## Query Scope — Match Dashboard Granularity to PR Granularity

All queries must match the granularity of the PR's change — no broader, no narrower.

The scope for each flow is determined in Step 3 and applies to every query for that flow: traffic, latency, upstream, error codes, all of it.

- If the PR's change is only visible in a subset of traffic → scope all queries to the label and value identified in Step 3
- If no scoping label exists on the metric → use service-wide aggregate queries
- Label names and values always come from the registered label slice in code (Pass A) — never assumed

### Multiple independent flows

When the PR touches two or more independent flows, each flow gets its own **`Key Health Stats — <Flow Name>`** row. Queries in each row are scoped to that flow's label value. Do not merge independent flows into a single panel — a failure in one flow must not be hidden by the other's volume.

**Complexity budget with multiple flows:** each flow consumes one row. With two flows, the remaining budget (max 3–4 rows total) may only fit one additional row (Latency or Upstream or Saturation). Prioritise the signal most relevant to what the PR changes.

---

## Mandatory Panel Checklist

> **This is a floor, not a ceiling.** The mandatory sections (Key Health Stats, Traffic) must always be present. Beyond that, add as many additional sections as the PR's instrumentation warrants — Latency, Upstream, Saturation, Resources, or any feature-specific metric discovered in Pass B. Never omit a discovered metric just because it isn't in the mandatory list.

### Section naming vs physical layout — two separate concerns

> **Row header naming is independent of physical panel grouping.**
> - Each signal type gets its own row header: `Key Health Stats`, `Traffic`, `Latency`, `Upstream`
> - **Never combine signal names into a row header** — not "Traffic & Error Code", not "Latency + Upstream"
> - A row header always contains exactly one concept name, even if the panels below it sit alongside panels from another section
> - Physical layout (how many panels share a grid row, widths) is a separate decision from section naming

### Sections (each always gets its own row header)

| Section header | Always required | Signal type |
|---|---|---|
| **Key Health Stats** | Yes | Rates (req/s) for HTTP/gRPC; percentages / ratios for cache, workers, DB |
| **Latency** | When histogram metric exists | Duration (ms) |
| **Upstream** | When flow makes external network calls | Rates (req/s) per dependency |
| **Saturation** | When queues, lag, or pools are relevant | Primary: lag, queue depth. Optional: goroutines, connection pools |

### Key Health Stats

#### HTTP / gRPC operations (rate-based breakdown available)

Row 1 — side by side:

| Panel | Series | Y-axis | Width |
|---|---|---|---|
| **Traffic by Status Code** | `sum by (code) rate(...)` — one series per full HTTP status code (200, 400, 404, 500, etc.) | req/s, auto | w:12 |
| **Error Codes** | `sum by (<error_code_label>) rate(...)` — all error codes, no topk limit | req/s, auto | w:12 |

- Traffic by Status Code is always present
- Error Codes is present only when the error code metric exists in the codebase (discovered in Pass A grep #3)
- 4xx and 5xx are always separate series — never collapsed into a single "error" line
- Show individual status codes, not aggregated classes
- Show **all** error codes — no `topk` limit, every code matters for debugging

#### Cache / Worker / DB operations (ratio signals only)

Row 1 — side by side:

| Panel | Series | Y-axis | Width |
|---|---|---|---|
| **Success / Hit Rate** | success or hit ratio × 100 — single line | 0–100%, locked | w:12 |
| **Error / Miss Rate** | error or miss ratio × 100 — one series per class | 0–100%, locked | w:12 |

### Latency

| Panel | Series | Y-axis | Width |
|---|---|---|---|
| **Latency** | p50 + p95 + p99 | ms, auto | w:24 |


### Upstream

| Panel | Series | Y-axis | Width |
|---|---|---|---|
| **Upstream** (one panel per dependency) | status code rates by dependency | req/s, auto | split equally across row |

- One panel per upstream dependency — name each panel after the service (e.g. "CPS", "API Bypass")
- Row header is always **"Upstream"** — not "Upstream Dependencies" or combined with Latency

---

### Rates vs Ratios — when to use each

| Signal type | Use | Panel | Why |
|---|---|---|---|
| HTTP/gRPC route | Rates (req/s) | Key Health Stats | Status code breakdown makes ratios redundant — you can see the split directly |
| Cache, Worker, DB, Kafka | Ratios (%) | Key Health Stats | No natural status code breakdown — ratio is the only normalized signal |
| p50 / p95 / p99 | Duration (ms) | Latency | Not a rate or ratio |

---

## Signal Type Classification

For every metric discovered in Pass B, classify it first, then apply the matching panel rule. Never leave a discovered metric unclassified.

### Operations with success/failure outcome → Key Health Stats model

| Signal type | Key Health Stats panels | Extra panels |
|---|---|---|
| HTTP / gRPC route | Traffic by status code (req/s) + Error Codes (req/s) — side by side w:12 | Latency if histogram exists |
| Cache | hit ratio (%) + miss/error ratio (%) — side by side w:12 | — |
| Worker / job | success ratio (%) + failure ratio (%) — side by side w:12 | processing duration |
| DB query | success ratio (%) + error ratio (%) — side by side w:12 | query duration |
| Kafka consumer | success ratio (%) + failure ratio (%) — side by side w:12 | lag (saturation panel) |

### Signals without success/failure → single panel, type by shape

| Signal type | Panel | Y-axis | Query pattern |
|---|---|---|---|
| Saturation (queue depth, Kafka lag, goroutine count, connection pool) | timeseries | count, auto | gauge value over time |
| Resource (CPU, memory, GC pause) | timeseries | bytes / percent, auto | gauge value over time |
| Latency / histogram | timeseries — p50 + p95 + p99 on same panel | ms, auto | `histogram_quantile()` |
| One-time / unexpected event ("did this happen?") | timeseries — expect flat at 0 | count | `increase()` over `$__range` |

---

## Y-axis and Color Rules

- **Key Health Stats panels (HTTP/gRPC)** → Y-axis auto, unit `req/s`
- **Key Health Stats panels (cache/worker/DB)** → Y-axis locked 0–100%, unit `percent (0-100)`, no threshold lines
- **Latency panels** → Y-axis auto, unit `ms`
- **Upstream panels** → Y-axis auto, unit `req/s`
- **2xx / success series** → green
- **4xx series** → orange
- **5xx / failure series** → red
- **p50** → green, **p95** → orange, **p99** → red
- **Error code series** → auto-assigned colours
- **Legend labels on percentage panels must not include `%`** — the Y-axis unit already shows it; `"success"` not `"success %"`, `"4xx"` not `"4xx %"`, `"miss"` not `"miss %"`. Redundant units in labels look cluttered.
- **Panel title format:** `<Signal Name> (<composition>)` — signal name plus composition. No observation hints, no em dash annotations. Example: `"Success Rate (2xx/total)"` not `"Success Rate (2xx/total) — drop = broken"`.
- **Panel titles must not include hardcoded baseline values** — titles go stale after every deploy; let the live graph show actual values.

---

## Complexity Rules

- **Max 3–4 rows per dashboard** — a PR rarely touches everything
- **Max 2–3 panels per row** — combine related metrics onto one panel
- **Key Health Stats always comes first** — it answers "is it broken?" and must be visible without scrolling
- **Traffic and Error Codes live in Key Health Stats side by side** (`w:12` each) for HTTP/gRPC — no separate Traffic row
- Percentiles (p50/p95/p99) → same panel
- Multiple co-equal primary signals → all in Key Health Stats side by side (`w:12` each) — do NOT demote any to a lower row
- **Panels across sections should use the same time range** — do not mix `$__rate_interval` and fixed windows in the same dashboard; keep all panels visually comparable

---

## Panel Type

Use **timeseries** for all panels. Prefer trend visibility over point-in-time values.

| Signal | Panel type | Query pattern |
|---|---|---|
| Rate (absolute volume) | **timeseries** | `rate()` over `$__rate_interval` by status class |
| Ratio / health (relative signal) | **timeseries** | `rate(subset) / rate(total) * 100` |
| Error counters by code | **timeseries** | `topk(10, sum by (error_code) rate(...))` |
| Percentiles (p50/p95/p99) | **timeseries** | `histogram_quantile()` over time |
| Saturation / gauge | **timeseries** | instant metric value over time |
| One-time event | **timeseries** | `increase()` over `$__range` — expect 0 |

**Do not use stat panels.** A timeseries always tells you more — it shows when something changed, not just that it changed.

---

## Templating Variables

**No datasource variable.** Set `"datasource": { "type": "prometheus" }` in every panel.

For label variables:
- Values known from code/kube-manifests → use `custom` type with hardcoded options
- Truly dynamic labels on existing metrics → use `query`/`label_values()`
- Never use `label_values()` for new/not-yet-deployed metrics — returns empty
