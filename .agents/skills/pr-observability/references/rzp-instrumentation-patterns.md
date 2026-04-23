# Razorpay Go Instrumentation Patterns

## Table of Contents
1. [Metric Instrumentation Patterns](#1-metric-instrumentation-patterns)
2. [Log Instrumentation Patterns](#2-log-instrumentation-patterns)
3. [Event / Kafka Emission Patterns](#3-event--kafka-emission-patterns)
4. [Grep Reference — Discover Instrumentation in a Codebase](#4-grep-reference)
5. [Mapping Code to Query Fields](#5-mapping-code-to-query-fields)

---

## 1. Metric Instrumentation Patterns

### goutils metrics (most common in pg-router, stork, scrooge)

```go
// Counter
metrics.Increment(ctx, "payment.create.success", tags...)
metrics.Increment(ctx, "payment.callback.error", map[string]string{"gateway": gateway})

// Timing / histogram
metrics.Timing(ctx, "payment.create.latency", elapsed, tags...)
metrics.Timing(ctx, "optimizer.select.latency", elapsed, nil)

// Gauge
metrics.Gauge(ctx, "queue.depth", float64(len(queue)), nil)
```

**Metric name → Prometheus label mapping:**
- `metrics.Increment(ctx, "foo.bar.success", ...)` → Prometheus metric `foo_bar_success_total` or `foo_bar` with `status="success"` label
- Tags become Prometheus labels: `map[string]string{"gateway": "hdfc"}` → `{gateway="hdfc"}`

### Prometheus direct (less common, found in some workers)

```go
var paymentCreateTotal = prometheus.NewCounterVec(
    prometheus.CounterOpts{Name: "payment_create_total", Help: "..."},
    []string{"method", "status", "gateway"},
)
paymentCreateTotal.With(prometheus.Labels{"method": "upi", "status": "success"}).Inc()

var paymentLatency = prometheus.NewHistogramVec(
    prometheus.HistogramOpts{Name: "payment_duration_seconds"},
    []string{"method"},
)
paymentLatency.With(prometheus.Labels{"method": "card"}).Observe(elapsed.Seconds())
```

### OpenTelemetry / OTEL (newer services)

```go
meter.Int64Counter("webhook.sent")
meter.Float64Histogram("webhook.latency")
```

---

## 2. Log Instrumentation Patterns

### Standard logger (pg-router, stork, most Go services)

```go
// Error
logger.Logger(ctx).Errorw(trace.ErrorConstant, map[string]interface{}{
    "error":      err.Error(),
    "payment_id": paymentID,
    "gateway":    gateway,
})

// Info
logger.Logger(ctx).Infow("payment.created", map[string]interface{}{
    "payment_id":  paymentID,
    "merchant_id": merchantID,
    "method":      method,
})

// Warn
logger.Logger(ctx).Warnw("webhook.retry", map[string]interface{}{
    "event_id": eventID,
    "attempt":  attempt,
})
```

**Key fields in structured logs:**
- `payment_id`, `order_id`, `merchant_id` — entity identifiers
- `gateway`, `method` — routing context
- `error` — error string (only present when `err.Error()` is logged; no guaranteed `error_code` field)
- `request_id`, `trace_id` — distributed trace
- `latency` — timing info (sometimes logged explicitly)

**Do not assume fields.** Services log what they log. Always grep the code to see what fields are actually emitted before writing a query.

### Error constants (look for these to find all error log sites)

```go
// In internal/trace/ or pkg/trace/
const ErrorConstant = "error"
const PaymentCreateError = "payment.create.error"
const CallbackProcessingError = "payment.callback.error"
```

When you find `logger.Logger(ctx).Errorw(trace.SomeConstant, ...)`, the constant value is the log `msg` field to filter on in Coralogix Lucene: `msg:"<constant_value>"`.

### Zap logger (some services)

```go
zap.L().Error("webhook delivery failed",
    zap.String("event_id", eventID),
    zap.Int("response_code", code),
)
```

---

## 3. Event / Kafka Emission Patterns

### Outbox / event publishing

```go
// Look for these patterns to find what Kafka events are emitted in a flow
events.Publish(ctx, "PGROUTER.PAYMENT.WEBHOOK.SENT", payload)
producer.Produce(topic, key, value)
datalakeEvents.Emit(ctx, eventType, entity)
```

Each emitted event = a log entry AND a potential Kafka topic to monitor consumer lag on.

---

## 4. Grep Reference

Run these in the repo root to discover all instrumentation in affected code paths.

```bash
# --- METRICS ---

# goutils metrics calls
grep -rn "metrics\.Increment\|metrics\.Timing\|metrics\.Gauge" <paths>

# Prometheus direct registrations (find metric names)
grep -rn "NewCounterVec\|NewHistogramVec\|NewGaugeVec\|NewCounter\b\|NewHistogram\b" <paths>

# OTEL
grep -rn "Int64Counter\|Float64Histogram\|Int64Gauge" <paths>

# --- LOGS ---

# All structured log calls
grep -rn "\.Errorw\|\.Infow\|\.Warnw\|\.Debugw" <paths>

# Logger instantiation points (find all logger usage)
grep -rn "logger\.Logger(ctx)" <paths>

# Error constants (find log message strings)
grep -rn "trace\." <paths> | grep -v "_test\.go"

# Zap logger
grep -rn "zap\.L()\." <paths>

# --- KAFKA / EVENTS ---

# Event emission
grep -rn "\.Produce\|\.Publish\|EmitEvent\|datalakeEvents\." <paths>

# Topic references (what topics does this flow write to)
grep -rn "topic\s*=\|Topic:\|\"lumberjack\.\|\"events\." <paths>

# --- HTTP OUTBOUND (latency sources) ---

# Outbound calls to other services
grep -rn "\.Do(\|\.Post(\|\.Get(\|httpClient\." <paths>
```

**Tip:** For each changed file in the PR, also grep its parent package directory — callers and callees in the same package share instrumentation.

---

## 5. Mapping Code to Query Fields

### Step A: Find the Prometheus namespace (ALWAYS do this first)

```bash
grep -rn "PrometheusNamespace\|Namespace:" <repo>/internal/metrics/ --include="*.go" | grep -v "_test.go"
```

Query name depends on metric type:

| Type | Query as | Why |
|------|----------|-----|
| Counter | `{namespace}_{metric_name}` | No suffix — Coralogix/metrics pipeline does not append `_total` |
| Gauge | `{namespace}_{metric_name}` | No suffix |
| Histogram | `{namespace}_{metric_name}_bucket` / `_count` / `_sum` | Prometheus always creates these as separate structural time series for every histogram |

**Never assume the metric name from code alone** — the namespace prefix is easy to miss.

### Step B: Find label values (ALWAYS trace to source)

For every label in a metric, find the function that sets it and trace its value:

```bash
# Find where the metric is pushed
grep -rn "PushMyMetric\|myMetric.With" <repo>/internal/ --include="*.go"

# Then read that function to see where label values come from
```

**Two cases:**

**Case 1 — Value is a code constant:**
```go
AppModeLive = "live"   // use exactly this string
AppModeTest = "test"
```
→ Use the constant value directly in queries.

**Case 2 — Value comes from `os.Getenv(...)` or config:**
```go
func GetEnv() string { return os.Getenv("APP_ENV") }
```
→ Grep kube-manifests for the env var name to find ALL actual deployed values:
```bash
# Step 1: find the var name in code, then search kube-manifests
grep -rn "<ENV_VAR_NAME>" /path/to/kube-manifests/helmfile/charts/<repo>/

# Step 2: check all env-specific values files
grep -rn "<ENV_VAR_NAME>" /path/to/kube-manifests/helmfile/charts/<repo>/values-*.yaml
grep -rn "<ENV_VAR_NAME>" /path/to/kube-manifests/helmfile/charts/<repo>/*/values.yaml
```
List ALL values found — one per deployment environment. Never hardcode just one.

> **Example — what this discovery produced for pg-router:**
> - `PrometheusNamespace = "pg_router"` → all metric names start with `pg_router_`
>
> | Label | Env var | Values found in kube-manifests |
> |---|---|---|
> | `env` | `APP_ENV` | `prod`, `stage`, `bvt`, `perf`, `func` |
> | `mode` | `APP_MODE` (constants: `live`/`test`) | `live`, `test` |
>
> Run the same steps for any other repo — do not copy these values across services.

### Metric name → Prometheus query

| Code pattern | Query name | Example |
|---|---|---|
| `NewCounterVec({Name: "foo_metric"})` | `{namespace}_foo_metric` | `rate({namespace}_foo_metric{<labels>}[$__rate_interval])` |
| `NewHistogramVec({Name: "foo_duration_seconds"})` | `{namespace}_foo_duration_seconds_bucket` / `_count` / `_sum` | `histogram_quantile(0.95, rate({namespace}_foo_duration_seconds_bucket{<labels>}[$__rate_interval]))` |

**When metric name is still uncertain:** use Coralogix MCP to search available metrics by prefix before writing the full PromQL.

### Log message → Coralogix Lucene query

| Code pattern | Lucene query |
|---|---|
| `logger.Errorw(trace.ErrorConstant, ...)` | `applicationname:"<svc>" AND _exists_:error` |
| `logger.Infow("payment.created", ...)` | `applicationname:"<svc>" AND msg:"payment.created"` |
| `logger.Errorw("payment.callback.error", ...)` | `applicationname:"<svc>" AND msg:"payment.callback.error"` |
| `logger.Warnw("webhook.retry", ...)` | `applicationname:"<svc>" AND msg:"webhook.retry"` |
| `zap.Error("webhook delivery failed", ...)` | `applicationname:"<svc>" AND text:"webhook delivery failed"` |

Do NOT filter by `severity` or `level` — monitor all log levels for a flow, not just errors.

### Tag/label → Prometheus label or Lucene field

| Code tag | Prometheus label | Lucene field |
|---|---|---|
| `"gateway": gateway` | `{gateway="hdfc"}` | `gateway:"hdfc"` |
| `"method": method` | `{method="upi"}` | `method:"upi"` |
| `"merchant_id": merchantID` | `{merchant_id="M123"}` | `merchant_id:"M123"` |
| `"payment_id": paymentID` | N/A (high cardinality, don't label) | `payment_id:"pay_xxx"` |
