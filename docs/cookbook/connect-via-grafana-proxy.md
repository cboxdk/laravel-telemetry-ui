---
title: Connect through a Grafana datasource proxy
description: Query Tempo/Loki/Prometheus behind Grafana when only the Grafana host is reachable
weight: 3
---

# Connect through a Grafana datasource proxy

The dashboard talks to the **query APIs** of Tempo, Loki and Prometheus/Mimir
directly — not to Grafana. But those backends are often internal-only
(`http://tempo:3200`, `http://loki:3100`, …) and the single host you *can*
reach is a Grafana instance (e.g. an all-in-one LGTM stack).

Grafana can stand in as the gateway: it proxies datasource queries at
`/api/datasources/proxy/uid/<uid>/…`, forwarding the path to the backend.
Because the drivers append their own API paths on top of the connection URL,
pointing a connection at the proxy base is all it takes — **no code, no new
driver**.

## Recipe

```dotenv
# One host, three datasource UIDs (see below), one token.
TELEMETRY_UI_METRICS_URL=https://monitor.example.com/api/datasources/proxy/uid/<prometheus-or-mimir-uid>
TELEMETRY_UI_TEMPO_URL=https://monitor.example.com/api/datasources/proxy/uid/<tempo-uid>
TELEMETRY_UI_LOKI_URL=https://monitor.example.com/api/datasources/proxy/uid/<loki-uid>

TELEMETRY_UI_TOKEN=glsa_xxx   # Grafana service-account token, Viewer role
```

`TELEMETRY_UI_TOKEN` becomes an `Authorization: Bearer …` header on every
connection (or set a per-connection `TELEMETRY_UI_METRICS_TOKEN` etc.). For a
gateway that wants HTTP basic auth instead, use
`TELEMETRY_UI_<CONN>_BASIC_AUTH=user:pass`.

## Finding the datasource UIDs

In Grafana: **Connections → Data sources → <name>**; the UID is in the URL and
the settings page. Or over the API:

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  https://monitor.example.com/api/datasources | jq '.[] | {name, type, uid}'
```

## Getting a token (least privilege)

Create a **service account with the Viewer role** and issue a token for it —
never use an admin login. Viewer can run datasource queries but cannot change
anything (dashboards, datasources, users). Via the API:

```bash
# as an admin, once:
SA=$(curl -s -X POST https://monitor.example.com/api/serviceaccounts \
  -u admin:… -H 'Content-Type: application/json' \
  -d '{"name":"telemetry-ui","role":"Viewer"}' | jq -r .id)

curl -s -X POST https://monitor.example.com/api/serviceaccounts/$SA/tokens \
  -u admin:… -H 'Content-Type: application/json' \
  -d '{"name":"telemetry-ui"}' | jq -r .key   # -> glsa_…
```

Rotate/revoke the token in Grafana when you're done.

## Gotchas learned the hard way

- **Loki needs its `/loki` path *on top of* the proxy base.** The proxy base
  is `…/proxy/uid/<loki-uid>`; the driver then sends `/loki/api/v1/query_range`,
  so the backend sees `…/proxy/uid/<loki-uid>/loki/api/v1/…`. That is correct —
  don't strip the `/loki`. (If you point the base one level deeper you'll get
  `404 page not found`.)
- **Queries run server-side.** The Laravel HTTP client makes the calls, not the
  browser — so there's no CORS to configure; only the app server needs network
  access to Grafana.
- **Pages light up only for signals that are actually emitted.** Detected pages
  (Statamic, Cache, System, …) stay hidden until their metrics exist, and cards
  show a clean empty state otherwise. A partial telemetry rollout simply shows
  fewer sections — that's expected, not a misconfiguration.
- **Lock the gate down.** The `viewTelemetryUi` gate is local-only by default;
  an instance pointed at production must define the gate for real users. See
  [installation](../getting-started/installation.md#authorization).

## Prefer direct access when you can

Going through Grafana adds a hop and couples you to Grafana's auth. If the
Tempo/Loki/Mimir query endpoints are reachable from the app server (same
network/VPN), point the connections straight at them with a `tenant`
(`X-Scope-OrgID`) instead — see [connections](../core-concepts/connections.md).
