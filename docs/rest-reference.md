# smartEngin Licence & buy — REST Reference (`sels/v1`)

Base URL: `https://<licence-server>/wp-json/sels/v1`

These are the endpoints a **licensed product** uses. The client library
(`Self_Client`) calls them for you; this reference is for understanding, debugging, or
integrating **non-WordPress software** in any language. A machine-readable
[`openapi.yaml`](openapi.yaml) accompanies this file.

> The shop also exposes checkout, webhook, portal, account and invoice routes under the
> same namespace. Those are **internal to the shop** and intentionally not part of the
> developer integration surface — they are omitted here.

## Conventions

- **Transport:** HTTPS required (except on `local`/`development` server environments).
- **Auth:** none for the licensing endpoints — the licence **key** is the credential.
  These routes are public by design.
- **Rate limit:** per IP + key, sliding 60-second window (default 30 requests/min;
  configurable server-side). Over the limit ⇒ `429 rate_limited`.
- **Identity:** every call carries `product`, `instance`, and `instance_type`.
  - `instance` = the activation identifier. For websites, the **normalized host**:
    no scheme, no `www.`, lower-case (e.g. `example.com`).
  - `instance_type` = `domain` (default) or `device`.
- **Errors:** JSON `{ "success": false, "error": "<slug>", "message": "<human text>" }`
  with a matching HTTP status.

---

## POST `/activate`

Register this instance against a key. Idempotent: re-activating the same instance just
refreshes it.

**Parameters** (form-encoded body)

| Name | Required | Description |
|---|---|---|
| `key` | yes | Licence key. |
| `product` | yes | Product slug. |
| `instance` | yes | Activation identifier (host for domains). |
| `instance_type` | no | `domain` (default) or `device`. |
| `label` | no | Optional human label for the activation. |

**Success `200`**

```json
{
  "success": true,
  "status": "active",
  "valid_until": "2027-01-31 23:59:59",
  "activations_left": 2,
  "subscription": true,
  "grace_days": 3
}
```

`valid_until` is in **UTC**, or `null` for a lifetime licence; `activations_left` is
`null` when the licence allows unlimited activations. `subscription` and
`grace_days` (server 1.6.43+) are explained under [`/validate`](#post-validate).

An instance that **already** holds an activation can always re-activate (idempotent,
`200`) — even with an expired key; `status` then says `expired`. Only a **new**
instance is refused with `license_expired`.

**Errors**

| HTTP | `error` | When |
|---|---|---|
| 400 | `bad_request` | Missing `key` or `instance`. |
| 404 | `product_not_found` | Unknown product slug. |
| 404 | `license_not_found` | Key unknown, or not for this product. |
| 403 | `license_inactive` | Licence refunded or disabled. |
| 403 | `license_expired` | Licence past its end date, and this instance does not hold an activation yet (server 1.6.43+). Renew first. |
| 403 | `trial_used_on_site` | A free trial was already used on this website. |
| 409 | `limit_reached` | Activation limit reached. |
| 403 | `https_required` | Called over plain HTTP in production. |
| 429 | `rate_limited` | Too many requests. |

---

## POST `/deactivate`

Free this instance's activation slot. Idempotent and forgiving: always returns success
(so a client can always "log out" cleanly).

**Parameters**

| Name | Required | Description |
|---|---|---|
| `key` | yes | Licence key. |
| `instance` | yes | Activation identifier to release. |
| `instance_type` | no | `domain` (default) or `device`. |

**Success `200`**

```json
{ "success": true }
```

---

## POST `/validate`

Re-check a key's current status. Never blocks: an unknown key returns `valid:false` /
`status:"unknown"` with HTTP 200. The client library calls this daily and keeps the
**last known good** status on any transport error.

**Parameters**

| Name | Required | Description |
|---|---|---|
| `key` | yes | Licence key. |
| `product` | yes | Product slug. |
| `instance` | no | Activation identifier (touched to mark "seen" if present). |
| `instance_type` | no | `domain` (default) or `device`. |

**Success `200`**

```json
{
  "valid": true,
  "status": "active",
  "valid_until": "2027-01-31 23:59:59",
  "activations_left": 2,
  "subscription": true,
  "grace_days": 3
}
```

| `status` | Meaning |
|---|---|
| `active` | Licence is active and not past `valid_until`. |
| `expired` | End date has passed (since server 1.6.43 also when the stored status is still "active"; until 1.6.42 such a licence was reported as `active` with `valid:false`). Updates stop. **One-off purchase:** the software keeps working. **Subscription:** premium features stay on for `grace_days`, then switch off. |
| `refunded` / `disabled` | No longer active — premium features off. |
| `unknown` | Key not recognised (no further fields). |

`valid` is `true` only when the licence is **active and not past `valid_until`** (UTC).

| Field (server 1.6.43+) | Meaning |
|---|---|
| `subscription` | `true` when the licence was sold as a subscription. Instalment plans are one-off purchases (`false`). |
| `grace_days` | Days after `valid_until` that an expired **subscription** keeps its premium features (3 on smartengin.de). |

The client decides the lock from the **stored** answer, without a live call:
switch off when `subscription` is `true` and now ≥ `valid_until` + `grace_days`.
That way the lock happens on time offline, and an outage never locks early. A
server older than 1.6.43 omits both fields — treat the licence as a one-off
purchase (never locks). The ready clients (PHP 0.8.0, .NET 0.2.0, Python 0.2.0)
implement exactly this.

---

## GET `/update`

The update check — for WordPress plugins **and** non-WordPress products (Windows
`.exe`/`.msi`, other). Always tolerant — any missing entitlement, unknown key/product,
or "already current" returns a plain **no-update** answer (HTTP 200). Only a genuine
newer version for a **valid** licence returns a package.

**Query parameters**

| Name | Required | Description |
|---|---|---|
| `key` | yes | Licence key. |
| `product` | yes | Product slug. |
| `version` | yes | Installed version reported by the client. |
| `instance` | **yes** | Activation identifier. Required in practice for a limited licence — see *Activation limit* below. |
| `instance_type` | no | `domain` (default) or `device`. Desktop/Windows apps use `device`. |

### Activation limit (since server 1.1.0)

The update channel enforces the same "how many installations" promise as
`/activate`. Before answering, the server checks whether the **asking**
installation belongs to the licence:

- **Unlimited licence** (`activation_limit = 0`) — always passes.
- **Known installation** — passes, and its last-seen timestamp is refreshed.
- **Unknown installation, licence has a free slot** — silently registered, then
  passes. This is deliberate: a customer who changes domain or promotes a
  staging copy to live must never lose updates without noticing.
- **Unknown installation, licence is full** — refused, with a reason (below).
- **No `instance` supplied** — plain no-update. Always send one.

Without this check the limit would live only in the client, as a flag in that
site's own options — which a cloned site (migration plugin, staging copy,
restored backup) carries along together with the real key.

**No update `200`**

```json
{ "success": true, "update": false }
```

**Refused `200`** — the licence is fine, but not for *this* installation. Still
HTTP 200 with `update: false`, so a client that does not know the field behaves
exactly as before; a current client reads `blocked` and tells the customer why.

```json
{
  "success": true,
  "update": false,
  "blocked": "limit_reached",
  "message": "This website is not registered for this licence, and the licence has no free activation left …"
}
```

| `blocked` | Meaning |
|---|---|
| `limit_reached` | This installation is not registered and no activation slot is free. |
| `trial_used_on_site` | A free trial already ran on this installation, under a different key. |

`message` is plain wording in the **licence server's** language, meant to be
shown to the customer as-is. Never key logic off `message`; use `blocked`.

### `server_moved` (rides along on every licence answer)

When the server owner has announced a permanent move (since server 1.1.0), every
answer of the four licence endpoints — success and refusal alike — additionally
carries:

```json
{ "…": "…", "server_moved": "https://new.example.com" }
```

Client library 0.7.0+ handles this by itself: it validates the address (HTTPS
only, same domain family as the configured server) and asks there from then on.
If you build your own client, apply the same validation — following an
unvalidated `server_moved` would let a single spoofed answer redirect your
licence traffic permanently.

**Update available `200`** — the product's **platform** shapes the payload.

WordPress plugin (carries the WordPress-only `requires` / `requires_php` / `tested`):

```json
{
  "success": true,
  "update": true,
  "slug": "acme-gallery-pro",
  "new_version": "1.1.0",
  "package": "https://smartengin.de/wp-json/sels/v1/download?token=…",
  "platform": "wordpress",
  "requires": "6.4",
  "requires_php": "7.4",
  "tested": "6.4",
  "changelog_url": "https://…",
  "homepage_url": "https://…",
  "sha256": "e3b0c44298fc1c149afbf4c8996fb924…"
}
```

Windows app (omits the WordPress fields; adds `filename`, the real name to save the
download as):

```json
{
  "success": true,
  "update": true,
  "slug": "acme-desktop",
  "new_version": "1.1.0",
  "package": "https://smartengin.de/wp-json/sels/v1/download?token=…",
  "platform": "windows",
  "filename": "acme-desktop-1.1.0-ab12cd.exe",
  "changelog_url": "https://…",
  "homepage_url": "https://…",
  "sha256": "e3b0c44298fc1c149afbf4c8996fb924…"
}
```

`package` is a **signed, short-lived** `/download` URL. `sha256` is present when the
server can compute the package checksum — the client **must** verify it before
installing. Building a Windows/desktop client? See
[Selling & updating Windows software](windows-software-guide.md).

---

## GET `/download`

Streams the update package for a valid **signed token** — a WordPress `.zip`, a
Windows `.exe`/`.msi`, or any file product. The token is produced by `/update`; you do
not build it yourself. On success it streams the file and exits; on failure it returns
JSON. The `Content-Disposition` header carries the real file name.

**Query parameters**

| Name | Required | Description |
|---|---|---|
| `token` | yes | Signed download token from `/update`. |

**Errors**

| HTTP | `error` | When |
|---|---|---|
| 400 | `bad_token` | Missing/invalid token. |
| 403 | `token_expired` | Link expired (tokens are short-lived). |
| 404 | `license_not_found` | Licence gone. |
| 403 | `license_inactive` | Licence no longer active. |
| 404 | `no_package` / `file_missing` | No package configured / file missing. |
| 403 | `https_required` | Plain HTTP in production. |

---

## Minimal non-WordPress example (activate)

```bash
curl -sS -X POST "https://smartengin.de/wp-json/sels/v1/activate" \
  -d "product=acme-gallery-pro" \
  -d "instance=example.com" \
  -d "instance_type=domain" \
  -d "key=XXXX-XXXX-XXXX-XXXX-XXXX"
```

Store `status`, `valid_until`, `subscription` and `grace_days` locally, re-check
periodically via `/validate`, and gate your features on the last known answer:
off for `refunded`/`disabled`, off for an expired subscription after `grace_days`,
on otherwise — never off because the server is unreachable. That is the entire
integration in any language.
