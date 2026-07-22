# Selling & auto-updating Windows (and other non-WordPress) software

smartEngin Licence & buy is not WordPress-only. The same server that licenses and
updates a WordPress plugin can license and **silently auto-update a Windows desktop
app** — the customer gets new versions in the background, the way WordPress plugins
update themselves. This guide shows how.

- The **server side is identical** to WordPress: you register a product, upload the new
  build, set the version. The only difference is the product's **Platform** setting and
  the client that talks to the API.
- A ready **.NET/C# reference client** ships with this kit — activation, fail-open
  validation and the silent self-update are done for you.
- Any language works via the plain REST API (`sels/v1`); the .NET client is just the
  reference implementation of the recipe below.

---

## 1. Register the product (backend)

*Licence & buy → Products → Add product* (a licence product):

| Field | Value |
|---|---|
| **Platform** | **Windows app (.exe / .msi)** |
| **Latest version** | the version you are shipping, e.g. `1.0.0` |
| **Update package** | upload your `.exe` or `.msi` build |
| **Changelog URL** (optional) | shown to the user |

Then add a **purchase option** (price/plan) as for any product, and place the offer
cards on a page. A buyer pays, receives a **licence key** by e-mail and on the page, and
downloads the app. That is the whole sales side — nothing Windows-specific.

To ship an update later: increase **Latest version**, upload the new `.exe`/`.msi`, save.
The server does the rest.

---

## 2. What the client does

Four steps, all against the public `sels/v1` REST API:

1. **Device id** — a stable, anonymous identifier for this installation (sent as
   `instance` with `instance_type=device`). No personal data.
2. **Activate** the licence key once (`POST /activate`).
3. **Validate** periodically (`POST /validate`), **fail-open**: on a network error keep
   the last known status so a server outage never bricks a paying customer.
4. **Update** (`GET /update`): if a newer version exists for a valid licence, download
   the package, **verify its SHA-256**, then replace the running executable and restart.

---

## 3. The .NET/C# reference client

The kit ships a small, dependency-free library (`SmartEngin.Licence`, .NET 8) plus a
sample app. Integration is three touch-points:

```csharp
using SmartEngin.Licence;

// (1) FIRST line of Main — handle the self-update helper mode, then continue.
if (Updater.TryRunUpdaterMode(args)) return;

// (2) Configure once. Keep ServerBaseUrl in a single place.
var options = new LicenceOptions {
    ServerBaseUrl = "https://your-licence-server.example",
    ProductSlug   = "your-product-slug",
    AppVersion    = "1.0.0",               // your installed version
};
var client  = new LicenceClient(options);
var updater = new Updater(options);

// (3) Activate / validate / update.
await client.ActivateAsync(key);
var status = await client.ValidateAsync(key);   // status.FeaturesEnabled gates premium features
var update = await updater.CheckForUpdateAsync(key);
if (update is not null) {
    var package = await updater.DownloadAndVerifyAsync(update);  // throws on checksum mismatch
    updater.ApplyUpdateAndRestart(package);                      // replaces the .exe and restarts
}
```

Build with the free **.NET 8 SDK** (`dotnet publish -c Release`). End users just run the
produced `.exe`.

### Why the self-update needs a "helper"

A running single-file `.exe` locks its own file and cannot overwrite itself. So the
update is applied like this:

1. The new `.exe` is downloaded to a temp folder and its **SHA-256** is checked against
   the value from `/update`.
2. A copy of the new exe is launched as a **helper** (`--se-apply-update`).
3. The app exits; the helper waits for it to close, replaces the old `.exe`, and
   relaunches it.

`Updater.TryRunUpdaterMode(args)` on the first line of `Main` is what runs in that helper
process. `.msi` packages are handed to `msiexec /qb` instead of being swapped.

> **Packaging note:** this in-place swap suits a single-file `.exe`. If you ship an
> installer (Inno Setup, MSI), upload that as the package instead — the client runs the
> installer silently rather than swapping a file.

---

## 4. Any other language (the raw recipe)

The .NET client is optional. In any language:

```
Activate:  POST /activate  key, product, instance, instance_type=device
Validate:  POST /validate  key, product, instance, instance_type=device
Update:    GET  /update?key=…&product=…&version=…&instance=…&instance_type=device
Download:  GET  <package URL from /update>   → verify sha256 → install
```

Store `status` + `valid_until` locally, re-check periodically, and gate features
**fail-open** on the last known status. See [`rest-reference.md`](rest-reference.md) and
[`openapi.yaml`](openapi.yaml) (machine-readable, for codegen). A **Python** reference
client using the same swap mechanism ships too — see
[`python-software-guide.md`](python-software-guide.md).

---

## 5. Code signing (before selling to customers)

An unsigned `.exe` downloaded from the internet triggers a Windows **SmartScreen**
warning ("Windows protected your PC"). For internal testing this is fine; for paying
customers, sign your `.exe`/`.msi` with an **Authenticode code-signing certificate**
(~100–300 €/year from a CA). This is independent of licensing — it is about Windows
trusting your publisher identity. Ship the SHA-256 (the server already provides it) and,
ideally, a signed binary.

---

## 6. Honest scope

Licensing is a **business mechanism, not unbreakable copy protection** — the app runs on
the customer's machine. Real enforcement is server-side: updates require a valid key, and
downloads are signed and short-lived. Keep the client **fail-open** so a server outage or
an expired licence never leaves a paying customer with a dead app (it keeps working; only
updates pause). Same philosophy as the WordPress client —
see [`what-licensing-does.md`](what-licensing-does.md).
