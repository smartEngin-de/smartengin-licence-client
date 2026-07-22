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
  "activations_left": 2
}
```

`valid_until` is `null` for a lifetime licence; `activations_left` is `null` when the
licence allows unlimited activations.

**Errors**

| HTTP | `error` | When |
|---|---|---|
| 400 | `bad_request` | Missing `key` or `instance`. |
| 404 | `product_not_found` | Unknown product slug. |
| 404 | `license_not_found` | Key unknown, or not for this product. |
| 403 | `license_inactive` | Licence refunded or disabled. |
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
  "activations_left": 2
}
```

| `status` | Meaning |
|---|---|
| `active` | Licence is active. |
| `expired` | Term ended; software keeps working, updates/support paused. |
| `refunded` / `disabled` | No longer active. |
| `unknown` | Key not recognised. |

`valid` is `true` only when the licence is **active and not past `valid_until`**.

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
| `instance` | no | Activation identifier. |
| `instance_type` | no | `domain` (default) or `device`. Desktop/Windows apps use `device`. |

**No update `200`**

```json
{ "success": true, "update": false }
```

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

Store `status` + `valid_until` locally, re-check periodically via `/validate`, and gate
your features **fail-open** on the last known status. That is the entire integration in
any language.
