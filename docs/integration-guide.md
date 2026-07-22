# smartEngin Licence & buy — Integration Guide

**Sell and license your own WordPress plugin (or any software) through the smartEngin Licence & buy server.**

This guide shows how to add licensing to your product: register the product on the
server, drop in the client library, show a licence panel, gate your premium
features, and receive automatic updates through the WordPress update channel.

> **New here? Give this to your AI assistant.** See the ready-made briefing block in
> [`what-licensing-does.md`](what-licensing-does.md#give-this-to-your-ai) — paste it
> into ChatGPT or Claude together with the link to this repository and it can walk you
> through the whole integration.

---

## 0. The 60-second mental model

- The **licence server** is one WordPress site running the *smartEngin Licence & buy*
  plugin. It owns products, keys, activations, and the update packages.
- Your **product** (any WordPress plugin) bundles a small, build-free PHP library —
  `Self_Client`. It talks to the server over a plain REST API (`sels/v1`).
- A **licence key** is a random token the server generates at purchase. There is
  nothing to "decrypt" on your side — you only ever hand the key to the library and
  read back a status.
- Enforcement lives **only on the server** (activation limits, the update channel,
  signed downloads). The client is deliberately *fault tolerant*: if the server is
  unreachable, your plugin keeps running on the last known status. This is a
  **business mechanism, not a copy protection** — see
  [`what-licensing-does.md`](what-licensing-does.md).

---

## 1. Prerequisites

- A licence server: a WordPress site with *smartEngin Licence & buy* installed and
  reachable over **HTTPS** (HTTPS is enforced except on `local`/`development`
  environments).
- Your product plugin, with a main file and a version constant.
- The client library folder `smartengin-licence-client/` (ships in this repository at
  the repo root; also downloadable from the plugin's *Help & docs* screen).

---

## 2. Register your product on the server

In the licence server's admin: **Licence & buy → Products → Add**. The fields that
matter for integration:

| Field | Meaning | Must match in your code |
|---|---|---|
| **Slug** | Machine name of the product, e.g. `acme-gallery-pro`. | `slug` in the client config **and** your plugin folder/text-domain, ideally identical. |
| **Latest version** | The newest version you publish. The server offers an update when the installed version is lower. | Your plugin header `Version:` / version constant. |
| **Package** | The ZIP delivered as the update (uploaded on the server). | — (server-side only) |
| **Key prefix** | Prefix for generated keys (licence-type products). | — (cosmetic) |

> The **slug is the contract.** The client sends `product=<slug>`; the server resolves
> the product and checks the key belongs to it. A mismatch means "unknown product".

For selling, you also create **purchase options** (plans) and a **product card** —
that is the shop side and is covered by the shop's own *Help & docs*, not this guide.

---

## 3. Bundle the client library

Copy the `smartengin-licence-client/` folder into your plugin, conventionally under
`lib/`:

```
your-plugin/
├── your-plugin.php
└── lib/
    └── smartengin-licence-client/
        ├── self-client.php
        └── includes/
            └── class-self-client.php
```

**Bundle the same library version in every product you ship.** The library guards its
own constant/class names, so if two smartEngin plugins are active the newest loaded
copy wins without conflict.

---

## 4. Wire it up (4 config values)

In your main plugin file, load the library and create **one** `Self_Client` instance.

```php
<?php
/**
 * Plugin Name: ACME Gallery Pro
 * Version:     1.0.0
 * License:     GPL-2.0-or-later
 * Text Domain: acme-gallery-pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ACME_GALLERY_PRO_FILE', __FILE__ );
define( 'ACME_GALLERY_PRO_VERSION', '1.0.0' );

/**
 * The ONE place the licence-server address lives. When the server later moves to a
 * dedicated domain, change this single line — nothing else.
 */
if ( ! defined( 'ACME_LICENCE_SERVER' ) ) {
	define( 'ACME_LICENCE_SERVER', 'https://your-licence-server.example' );
}

require_once __DIR__ . '/lib/smartengin-licence-client/self-client.php';

$GLOBALS['acme_gallery_licence'] = new Self_Client( array(
	'server_url'  => ACME_LICENCE_SERVER,      // licence server base URL
	'slug'        => 'acme-gallery-pro',        // = product slug on the server
	'plugin_file' => ACME_GALLERY_PRO_FILE,     // your main plugin file (__FILE__)
	'version'     => ACME_GALLERY_PRO_VERSION,  // installed version
	'text_domain' => 'acme-gallery-pro',        // optional
) );
```

That single instance registers everything automatically: the daily re-validation
cron, the AJAX handlers behind the licence panel, and the three WordPress
update-integration filters. You do not call the REST API yourself.

### Config reference

| Key | Required | Notes |
|---|---|---|
| `server_url` | yes | Base URL of the licence server. No trailing slash needed. **Keep it in one constant** (see above). |
| `slug` | yes | Must match the product slug on the server. |
| `plugin_file` | yes | Absolute path to your main plugin file (`__FILE__`). Used for `plugin_basename()` and update targeting. |
| `version` | yes | Installed version. Usually your version constant. |
| `option_name` | no | `wp_options` key for the stored state. Defaults to `<slug_with_underscores>_license`. |
| `text_domain` | no | For the library's own UI strings. |

---

## 5. Show the licence panel

Render the built-in panel anywhere in your admin UI (a settings tab, a dedicated
page). It shows a key field, Activate/Deactivate buttons, and a colour-coded status.

```php
add_action( 'admin_menu', function () {
	add_options_page(
		'ACME Gallery Pro',
		'ACME Gallery Pro',
		'manage_options',
		'acme-gallery-pro',
		function () {
			echo '<div class="wrap"><h1>ACME Gallery Pro</h1>';
			$GLOBALS['acme_gallery_licence']->render_panel( array( 'title' => 'Licence' ) );
			echo '</div>';
		}
	);
} );
```

`render_panel()` is self-contained (inline styles + script, uses `admin-ajax.php`).
No enqueue, no build step.

---

## 6. Gate your premium features (levels B + C)

Read the current state and gate accordingly. The recommended policy across all
smartEngin products is **B + C**:

- **B — updates only for valid licences** (handled automatically by the library; no
  valid key ⇒ no update appears + the panel shows the status).
- **C — premium features off without a valid licence, free core keeps working, data
  is preserved.** You implement this with one small helper.

```php
/**
 * True when this product is licensed enough to run its premium features.
 *
 * FAIL-OPEN by design: it reads only the cached state (get_state()), which keeps the
 * LAST KNOWN status when the server is unreachable. A server outage must never switch
 * off every customer's site at once — that is a far bigger risk than piracy.
 */
function acme_gallery_is_licensed() {
	$state = $GLOBALS['acme_gallery_licence']->get_state();
	// 'expired' still counts as licensed: only updates/support end, the software
	// keeps working. Adjust to taste, but keep it fail-open.
	return in_array( (string) $state['status'], array( 'active', 'expired' ), true );
}

// Example: register a premium feature only when licensed.
add_action( 'init', function () {
	if ( acme_gallery_is_licensed() ) {
		acme_gallery_register_premium_slider();
	}
} );
```

### `get_state()` fields

| Field | Type | Meaning |
|---|---|---|
| `key` | string | The stored licence key (may be empty). |
| `activated` | bool | Did this site register successfully at least once? |
| `status` | string | `active` \| `expired` \| `refunded` \| `disabled` \| `unknown` \| `''`. |
| `valid` | bool | Entitled to updates right now (last known). |
| `valid_until` | string\|null | MySQL datetime, or `null` for a lifetime licence. |
| `activations_left` | int\|null | Remaining activation slots, or `null` when unlimited. |
| `last_check` | int | Unix time of the last successful server check. |

### The Fail-Open rule (non-negotiable)

- Never hard-block on "server unreachable". Gate on the **stored status**, not on a
  live call.
- Never delete or lock user data when a licence lapses. Turn premium *off*, keep the
  free core and the data.
- Do **not** implement a "level D" (plugin dead without a key). It punishes paying
  customers and is bypassed in minutes anyway.

---

## 7. Updates — nothing to do

Once the instance exists, updates flow through the normal WordPress screens:

1. WordPress asks the server (`GET /update`) whether a newer version exists for this
   key + product.
2. If yes and the licence is valid, the server returns the new version and a **signed,
   short-lived** `/download` URL (plus an optional SHA-256).
3. WordPress shows the update. On install, the library fetches a **fresh** signed link,
   verifies the checksum, and installs.

If no valid licence: the server simply answers "no update" (HTTP 200) — the plugin
keeps running, nothing breaks.

> **Same-site caveat:** if the licence server runs on the *same* site as the product
> (e.g. during local testing, or the server licensing itself), WordPress must download
> the package from the site to itself; many hosts block that self-request with a 503.
> The library detects this and shows a clear "install manually / use a separate domain"
> message. In production, run the server on its own domain.

---

## 8. Test your integration

1. On the server, create a product with your slug and a test purchase option, and
   generate a **test key** (Licence & buy supports a test-purchase switch that issues a
   real, valid key).
2. In your plugin's licence panel, paste the key and click **Activate** → the status
   should turn green.
3. Confirm `acme_gallery_is_licensed()` returns `true` and your premium feature is on.
4. Click **Deactivate** → the slot is freed on the server; your gate returns `false`.
5. Bump *Latest version* on the server and upload a newer package → the update appears
   under **Dashboard → Updates**.

A minimal, working reference implementation lives in
[`../example/smartengin-licence-example/`](../example/smartengin-licence-example/) —
fork it or read it side by side with this guide.

---

## 9. Checklist

- [ ] Product registered on the server (slug + latest version + package).
- [ ] Library bundled under `lib/smartengin-licence-client/`.
- [ ] One `Self_Client` instance with the 4 config values.
- [ ] Server URL kept in a **single constant**.
- [ ] Licence panel rendered in the admin.
- [ ] Premium features gated via a **fail-open** `is_licensed()` helper (B + C).
- [ ] Activate / deactivate / update tested against the server.

See also: [REST reference](rest-reference.md) · [OpenAPI spec](openapi.yaml) ·
[What licensing does (and does not) do](what-licensing-does.md).
