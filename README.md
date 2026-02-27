# JvMTECH.Flow.SecurityHeaders

PSR-15 middleware for [Neos Flow](https://flow.neos.io) that adds configurable security headers to every HTTP response.

## Features

- Context-aware header values — apply different sources for the Neos backend, development, and [Monocle](https://github.com/sitegeist/Sitegeist.Monocle)
- Fully configurable contexts via Settings.yaml — no hardcoded paths
- Variable substitution in header values (`{HTTP_HOST}`, `{REQUEST_URI}`, `{FLOW_CONTEXT}`)
- Ships with strict defaults; extend per project via Settings.yaml

## Installation

```bash
composer require jvmtech/security-headers
```

## Default headers

| Header | Default value |
|---|---|
| `Content-Security-Policy` | Strict `'self'`-only policy with Neos backend overrides |
| `X-Frame-Options` | `SAMEORIGIN` |
| `X-Content-Type-Options` | `nosniff` |

The middleware runs `after routing`, meaning any IP allowlist middleware at the routing step executes before security headers are applied.

## Contexts

Contexts are named conditions evaluated against each request. For each directive, the **highest-position active context** that defines a value wins exclusively — its value is used as-is, with no concatenation. `default` (position 0) is the fallback when no higher-priority context defines a value for that directive. If the winning context doesn't define a value for a given directive, the next highest-position active context is tried.

### Built-in contexts

| Context | Position | Active when |
|---|---|---|
| `default` | 0 (implicit) | Always |
| `backendOrDevelopment` | 100 | URI starts with `/neos/` **or** `FLOW_CONTEXT` is `Development` |
| `backend` | 200 | URI starts with `/neos/` |
| `development` | 200 | `FLOW_CONTEXT` is `Development` |

### Defining custom contexts

Add to your site package's `Configuration/Settings.yaml`:

```yaml
JvMTECH:
  Flow:
    SecurityHeaders:
      contexts:
        api:
          position: 300
          uriPrefixes: ['/api/', '/graphql/']
        staging:
          position: 150
          flowContexts: ['Production/Staging']
        apiInDevelopment:
          position: 400
          uriPrefixes: ['/api/']
          flowContexts: ['Development']
          # operator: 'and' is the default
        backendOrStaging:
          position: 100
          uriPrefixes: ['/neos/']
          flowContexts: ['Production/Staging']
          operator: 'or'
```

Each context supports:

| Key | Description |
|---|---|
| `position` | Integer priority; higher position wins when multiple contexts match (default: `100`) |
| `uriPrefixes` | List of URI prefixes — active if the request URI starts with **any** of them |
| `flowContexts` | List of Flow contexts — active if `FLOW_CONTEXT` matches **any** of them (exact match or subcontext, e.g. `Production` matches `Production/Staging`) |
| `operator` | `and` (default) — both conditions must match; `or` — either condition suffices |

If only `uriPrefixes` is set, only the URI is checked. If only `flowContexts` is set, only the context is checked.

## Header configuration

Override any header value in your site package's `Configuration/Settings.yaml`. All keys are merged, so you only need to specify what differs from the defaults.

```yaml
JvMTECH:
  SecurityHeaders:
    Headers:
      Content-Security-Policy:
        img-src:
          default: "'self' data: i.ytimg.com www.youtube.com"
          monocle: "picsum.photos fastly.picsum.photos"
        frame-src: "'self' youtube.com www.youtube.com player.vimeo.com"
        report-uri: "/csp-violation-report/"
      Strict-Transport-Security: "max-age=7776000; includeSubDomains"
```

For context-aware CSP directives, use a map keyed by context name. All active contexts for the current request are concatenated:

```yaml
script-src-elem:
  default: "'self' cdn.example.com"
  backend: "'unsafe-inline'"
  development: "'unsafe-inline' cdn.tailwindcss.com"
  myCustomContext: "extra.example.com"
```

### Variable substitution

The following placeholders are replaced at runtime inside any header value:

| Placeholder | Replaced with |
|---|---|
| `{HTTP_HOST}` | Current HTTP host |
| `{REQUEST_URI}` | Current request URI |
| `{FLOW_CONTEXT}` | Current Flow context |
