# Error Pattern Reference

Known error categories for Razorpay services. Use this to categorize errors found in Coralogix logs and provide meaningful assessments instead of raw log messages.

## Error Categories

### Gateway / Banking Partner Errors

Indicates payment gateway or banking partner issues. Usually external to Razorpay.

| Pattern | Examples |
|---------|----------|
| `gateway_timeout` | Gateway did not respond within SLA |
| `upstream_timeout` | Upstream service timed out |
| `GATEWAY_ERROR` | Generic gateway failure |
| `ERROR_RESPONSE_5XX` | 5xx from gateway/bank |
| `gateway timeout` | HTTP-level gateway timeout |
| `bank_decline` | Bank actively declined the transaction |
| `acquirer_error` | Acquiring bank returned an error |

**Typical impact:** Payment failures for specific banks/gateways. Other gateways unaffected.
**Recommended action:** Check gateway status page, verify if bank is undergoing maintenance, check if the issue is isolated to one bank or widespread.

### Circuit Breaker Errors

Indicates a downstream dependency is failing repeatedly and the circuit breaker has tripped.

| Pattern | Examples |
|---------|----------|
| `circuit break` | Circuit breaker tripped |
| `breaker open` | Circuit is in open state |
| `circuit_open` | Variant spelling |
| `circuit breaker tripped` | Explicit trip message |
| `half-open` | Circuit attempting recovery |

**Typical impact:** All requests to the affected dependency fail fast. Can cascade if multiple services depend on the same downstream.
**Recommended action:** Identify which downstream service triggered the breaker. Check that service's health. The breaker will auto-recover once the downstream stabilizes.

### Database Errors

Indicates MySQL, PostgreSQL, or other database issues.

| Pattern | Examples |
|---------|----------|
| `deadlock` | Database deadlock detected |
| `lock wait timeout` | Lock wait timeout exceeded |
| `connection pool exhausted` | No available DB connections |
| `too many connections` | MySQL max_connections reached |
| `duplicate entry` | Unique constraint violation |
| `SQLSTATE` | Raw SQL error code |
| `database error` | Generic DB error |
| `query timeout` | Query execution timeout |
| `replication lag` | Read replica is behind |

**Typical impact:** Varies. Connection pool exhaustion affects all queries. Deadlocks affect specific transactions. Replication lag causes stale reads.
**Recommended action:** Check DB metrics (connections, replication lag, slow queries). For pool exhaustion, check if a query is holding connections too long.

### Redis / Cache Errors

Indicates Redis or caching layer issues.

| Pattern | Examples |
|---------|----------|
| `redis connection` | Redis connection failure |
| `redis error` | Generic Redis error |
| `redis timeout` | Redis operation timed out |
| `MOVED` | Redis cluster slot migration |
| `CLUSTERDOWN` | Redis cluster is down |
| `cache miss` | Expected cache entry not found (may be benign) |
| `connection refused` (port 6379) | Redis not reachable |
| `pool exhausted` (redis context) | Redis connection pool full |

**Typical impact:** Increased latency (cache misses fall through to DB), potential cascading failures if Redis is used for locks or rate limiting.
**Recommended action:** Check Redis cluster health, connection counts, memory usage. CLUSTERDOWN is critical and needs immediate SRE attention.

### Kafka / Event Processing Errors

Indicates Kafka producer, consumer, or event processing issues.

| Pattern | Examples |
|---------|----------|
| `kafka error` | Generic Kafka error |
| `consumer lag` | Consumer is behind producer |
| `offset commit failed` | Consumer failed to commit offset |
| `producer timeout` | Kafka producer timed out |
| `message too large` | Message exceeds max size |
| `broker not available` | Kafka broker unreachable |
| `rebalance` | Consumer group rebalancing |

**Typical impact:** Delayed event processing, duplicate processing on rebalance, lost events if DLQ is not configured.
**Recommended action:** Check consumer lag in Kafka dashboard, verify broker health, check if a recent deployment triggered rebalancing.

### Rate Limiting Errors

Indicates internal or external rate limits being hit.

| Pattern | Examples |
|---------|----------|
| `rate limit` | Rate limit exceeded |
| `too many requests` | HTTP 429 |
| `429` | HTTP status code |
| `throttled` | Request was throttled |
| `quota exceeded` | API quota reached |

**Typical impact:** Specific merchants or API consumers hitting limits. Usually not a system-wide issue unless internal rate limits are misconfigured.
**Recommended action:** Identify which merchant/API key is hitting limits. Check if limits are correctly configured. For internal rate limits, check if traffic has legitimately increased.

### Validation / Client Errors

Indicates bad input from clients. Usually NOT an incident -- these are expected errors.

| Pattern | Examples |
|---------|----------|
| `BAD_REQUEST` | HTTP 400 |
| `validation failed` | Input validation failure |
| `invalid` | Invalid parameter |
| `missing required` | Required field not provided |
| `malformed` | Malformed request body |
| `unauthorized` | Auth failure (HTTP 401) |
| `forbidden` | Permission denied (HTTP 403) |

**Typical impact:** Individual requests fail. Not a system issue unless count spikes dramatically (could indicate a broken client integration).
**Recommended action:** Usually no action needed. If spiking, check if a specific merchant's integration is broken or if a recent API change broke backward compatibility.

### Infrastructure / Process Errors

Indicates service crashes, resource exhaustion, or infrastructure problems.

| Pattern | Examples |
|---------|----------|
| `OOMKilled` | Container killed for exceeding memory |
| `panic` | Go panic (unrecovered) |
| `SIGKILL` | Process forcefully terminated |
| `SIGTERM` | Graceful shutdown signal |
| `goroutine` | Goroutine leak or dump |
| `runtime error` | Go runtime error |
| `stack trace` | Exception with stack trace |
| `connection refused` | Target service not reachable |
| `connection timeout` | TCP connection timed out |
| `DNS resolution` | DNS lookup failure |

**Typical impact:** Service restarts, request failures during restart window. OOM and panics are high severity.
**Recommended action:** Check pod restart counts, memory usage trends. For panics, check recent deployments. For connection issues, check target service health and network policies.

### Retry / Timeout Cascade

Indicates retry storms or cascading timeout failures.

| Pattern | Examples |
|---------|----------|
| `retry exhausted` | All retry attempts failed |
| `max retries` | Hit maximum retry count |
| `retry attempt` | Currently retrying (may be benign) |
| `context deadline exceeded` | Go context timeout |
| `context canceled` | Go context was canceled |
| `upstream error` | Upstream service returned error |
| `service unavailable` | HTTP 503 |

**Typical impact:** Often a cascading effect of another root cause. Retry storms can amplify load on an already struggling service.
**Recommended action:** Identify the original error that triggered retries. Fix the root cause, not the retries.

## Categorization Logic

When multiple error patterns match, prioritize:

1. **Infrastructure errors** (OOM, panic, SIGKILL) -- highest priority, indicates service instability
2. **Database errors** (deadlock, pool exhaustion) -- data layer issues affect everything
3. **Redis/Cache errors** -- caching layer failures cascade
4. **Gateway errors** -- external dependency issues
5. **Circuit breaker** -- usually a symptom of one of the above
6. **Retry/timeout cascade** -- usually a symptom, find the root cause
7. **Rate limiting** -- usually isolated
8. **Validation errors** -- lowest priority, usually benign

## Severity Assessment

| Condition | Severity |
|-----------|----------|
| Error rate > 5% or > 3x baseline | **CRITICAL** -- likely an active incident |
| Error rate 1-5% or 1.5-3x baseline | **DEGRADED** -- warrants investigation |
| Error rate < 1% and < 1.5x baseline | **HEALTHY** -- normal error rates |
| New error type not seen in baseline | **ATTENTION** -- could be a regression from a deployment |
| Errors concentrated on one merchant | **ISOLATED** -- likely merchant-specific issue |
| Errors across many merchants/services | **WIDESPREAD** -- likely infrastructure or gateway issue |
