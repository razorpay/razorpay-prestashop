# Prometheus Metric Patterns — Razorpay Services

## Table of Contents
1. [Label Conventions](#1-label-conventions)
2. [Common Query Templates](#2-common-query-templates)
3. [Per-Service Metric Patterns](#3-per-service-metric-patterns)
4. [Infrastructure Metrics](#4-infrastructure-metrics)
5. [Alert Thresholds](#5-alert-thresholds)

---

## 1. Metric Naming Rules (verify before writing ANY query)

**Rule 1 — Always find the namespace:**
```bash
grep -rn "PrometheusNamespace" <repo>/internal/metrics/ --include="*.go"
```
Query name by type:
- **Counter**: `{namespace}_{metric_name}` — no suffix
- **Gauge**: `{namespace}_{metric_name}` — no suffix
- **Histogram**: `{namespace}_{metric_name}_bucket` / `_count` / `_sum` — BUT always validate `_bucket` exists in Prometheus before using `histogram_quantile`. Run `sum(rate({namespace}_{metric_name}_bucket[5m])) by (le)` — if empty, fall back to avg latency: `rate({namespace}_{metric_name}_sum[...]) / rate({namespace}_{metric_name}_count[...])`
- **Summary** (`getSummaryVec` in code): does NOT produce `_bucket` — `histogram_quantile` cannot be used. Check the registration call in code (`NewHistogramVec` vs `getSummaryVec`) before writing any latency query. If the service tracks duration via two separate named metrics (e.g. `*_sum_metric` + `*_metric`), avg latency = `rate(sum_metric[...]) / rate(count_metric[...])`

**Rule 2 — Always trace label values to source:**
- Constant in code → use that exact string
- `os.Getenv("VAR")` → grep kube-manifests for `VAR` across all env values.yaml files to list all possible values

**Rule 2a — Always include standard infrastructure labels in every query:**

After reading the registered label slice, identify labels whose values come from `os.Getenv()` — these are infrastructure-scoping labels (e.g. `env`, `mode`, `namespace`). They must appear in **every** query for that metric as Grafana template variables, not hardcoded values.

```promql
# Correct — infrastructure labels included as template variables
rate(metric{env="prod", mode=~"$mode", <pr_scope_label>="<value>"}[$__rate_interval])

# Wrong — infrastructure labels missing
rate(metric{<pr_scope_label>="<value>"}[$__rate_interval])
```

This is mandatory and separate from the PR-specific scope label (Step 3). Missing infrastructure labels means the query returns data across all environments and modes mixed together.

After writing the dashboard JSON, verify no template variables were corrupted:
```bash
grep -E 'mode=~""|"expr":.*\[\]' <dashboard-file>.json && echo "BROKEN - fix before finishing" || echo "OK"
```
If any match is found, rewrite the affected queries before proceeding.


---

## 2. Label Conventions

| Label | Common values |
|-------|--------------|
| `method` | `upi`, `card`, `netbanking`, `wallet`, `emi` |
| `gateway` | `hdfc`, `axis`, `razorpay`, `stripe` |
| `status` | `success`, `failure`, `error` |
| `subsystem` | `worker`, `api`, `consumer` |

**Note:** When metric name is uncertain, use Coralogix MCP to discover available metrics by prefix before writing a full query.

**Rule 3 — For ANY metric you haven't personally verified: run a label-discovery query first.**

Before writing a filtered panel query, always run an unfiltered aggregation to discover the actual label names and values:

```promql
# Step 1: find what labels exist on the metric
sum(rate(<namespace>_<metric_name>[5m])) by (<guessed_label_1>, <guessed_label_2>)

# Step 2: confirm label values match your filter
# e.g. if you plan to filter serviceName="cps", first run:
sum(rate(pg_router_external_request_metric[5m])) by (serviceName)
# → reveals actual values (Payment, API, optimizer, ...) — none contain "cps"
```

This applies especially to **upstream/external call metrics** where label names and service identifier values are set deep in shared HTTP client code, not at the call site you're reading.

Never write a label filter (e.g. `{service_name=~".*cps.*"}`) without first confirming:
- The exact label name (e.g. `serviceName` not `service_name`)
- That at least one actual value matches your filter (query returns non-empty)

---

## 2. Common Query Templates

```promql
# Error rate
rate(<metric>_total{service="<svc>", status="error"}[5m])
  / rate(<metric>_total{service="<svc>"}[5m])

# p95 latency
histogram_quantile(0.95, rate(<metric>_duration_seconds_bucket{service="<svc>"}[5m]))

# Throughput (RPS)
rate(<metric>_total{service="<svc>"}[5m])

# Breakdown by dimension
sum(rate(<metric>{service="<svc>"}[5m])) by (method, status)

# Kafka consumer lag (sum across partitions)
sum(kafka_consumergroup_lag{consumergroup="<group>", topic="<topic>"}) by (topic)
```

---

## 3. Query-Building Template (from Discovery)

After running Rules 1 and 2 above, you have: the namespace, the metric name, and the verified label values. Build queries from those — do not assume metric names or label values for any service.

### Counter
```promql
# Throughput
rate({namespace}_{metric_name}{<label>="<verified_value>"}[$__rate_interval])

# Error rate
rate({namespace}_{metric_name}{<label>="<verified_value>", status="error"}[$__rate_interval])
  / rate({namespace}_{metric_name}{<label>="<verified_value>"}[$__rate_interval])

# Breakdown by dimension — `by` requires wrapping in an aggregation
sum(rate({namespace}_{metric_name}{<label>="<verified_value>"}[$__rate_interval])) by (<dim>)
```

### Histogram
```promql
# p95 latency
histogram_quantile(0.95,
  rate({namespace}_{metric_name}_bucket{<label>="<verified_value>"}[$__rate_interval])
)

# p50 / p99
histogram_quantile(0.50, rate({namespace}_{metric_name}_bucket{...}[$__rate_interval]))
histogram_quantile(0.99, rate({namespace}_{metric_name}_bucket{...}[$__rate_interval]))
```

### Gauge
```promql
{namespace}_{metric_name}{<label>="<verified_value>"}
```

### Kafka consumer lag
```promql
# Discover consumergroup name: grep topic/consumer config in the repo
sum(kafka_consumergroup_lag{consumergroup="<discovered-group>", topic="<discovered-topic>"}) by (topic)
```

**When metric name is still uncertain after grepping:** use Coralogix MCP to search by prefix:
```promql
# Search available metrics — find the right name before writing a full query
{__name__=~"<namespace>_.*"}
```

---

## 4. Infrastructure Metrics

```promql
# Goroutine count (leak indicator)
go_goroutines{service="<svc>"}

# Heap memory
go_memstats_heap_inuse_bytes{service="<svc>"}

# GC pause p99
histogram_quantile(0.99, rate(go_gc_duration_seconds_bucket{service="<svc>"}[5m]))
```

---

## 5. Alert Thresholds

| Signal | Warning | Critical |
|--------|---------|----------|
| Error rate | > 1% for 5m | > 5% for 2m |
| Latency p95 | > 2× baseline | > 5× baseline |
| Kafka lag | > 1000 msgs | > 10000 msgs |
| Webhook drop rate | > 1% | > 5% |
| Goroutines | > 2× normal | > 10000 |
