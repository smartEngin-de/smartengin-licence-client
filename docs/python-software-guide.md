# Selling & auto-updating Python (Windows) software

smartEngin Licence & buy licenses and **silently auto-updates Python desktop apps** the
same way it does WordPress plugins and .NET apps. This guide shows how, using a small
dependency-free Python client that mirrors the .NET reference client one-to-one.

- The **server side is identical** to WordPress and .NET: register a product, upload the
  new build, set the version. The only product-specific setting is **Platform = Windows
  app (.exe / .msi)**.
- A ready **Python reference client** (`se_licence.py`, standard library only) does
  activation, fail-open validation and the silent self-update for you.
- It is the exact Python equivalent of the [.NET client](windows-software-guide.md); both
  talk to the same public REST API (`sels/v1`).

---

## 1. Register the product (backend)

*Licence & buy → Products → Add product* (a licence product):

| Field | Value |
|---|---|
| **Platform** | **Windows app (.exe / .msi)** |
| **Latest version** | the version you are shipping, e.g. `1.0.0` |
| **Update package** | upload your `.exe` build (PyInstaller `--onefile`) |
| **Changelog URL** (optional) | shown to the user |

Add a **purchase option** (price/plan), place the offer cards on a page, and you are done.
A buyer pays, receives a **licence key**, and downloads the app.

To ship an update later: increase **Latest version**, upload the new `.exe`, save.

---

## 2. What the client does

Four steps, all against the public `sels/v1` REST API — identical to every other client:

1. **Device id** — a stable, anonymous identifier for this installation (sent as
   `instance` with `instance_type=device`). A random GUID stored under
   `%LOCALAPPDATA%\SmartEnginLicence\<slug>\`, hashed with the machine name. No personal
   data.
2. **Activate** the licence key once (`POST /activate`), then store the key locally so the
   app never asks again.
3. **Validate** periodically (`POST /validate`), **fail-open**: on a network error keep
   the last known status so a server outage never bricks a paying customer.
4. **Update** (`GET /update`): if a newer version exists, download the package, **verify
   its SHA-256**, then replace the running `.exe` and restart.

---

## 3. The Python reference client

Drop `se_licence.py` and `licence_config.py` next to your app. `licence_config.py` bakes
the three product constants so the end user only ever types a key:

```python
# licence_config.py
SERVER_URL   = "https://your-licence-server.example"   # your Licence & buy site (keep in ONE place)
PRODUCT_SLUG = "your-product-slug"        # exactly as in the backend
APP_VERSION  = "1.0.0"                     # bump on every release
```

Integration is three touch-points in your entry point:

```python
import sys
import se_licence
import licence_config

def main():
    # (1) FIRST line: if launched as the self-update helper, swap the file and exit.
    if se_licence.Updater.try_run_updater_mode(sys.argv):
        return

    options = se_licence.LicenceOptions(
        server_url=licence_config.SERVER_URL,
        product_slug=licence_config.PRODUCT_SLUG,
        app_version=licence_config.APP_VERSION,
    )
    client = se_licence.LicenceClient(options)
    updater = se_licence.Updater(options)
    slug = licence_config.PRODUCT_SLUG

    # (2) Gate: locked until the first successful activation.
    if not se_licence.is_licensed(slug):
        key = ask_user_for_key()                 # your UI (window / prompt)
        result = client.activate(key)
        if not result["success"]:
            show_error(result["message"]); return
        se_licence.save_key(slug, key)           # remember it – never ask again

    # (3) Fail-open validate + silent update (do this in the background).
    key = se_licence.load_key(slug)
    client.validate(key)                         # refreshes the cached status; never raises
    info = updater.check_for_update(key)
    if info:
        package = updater.download_and_verify(info)     # raises on checksum mismatch
        updater.apply_update_and_restart(package)       # replaces the .exe and restarts

    start_your_app()

if __name__ == "__main__":
    main()
```

`client.validate(key)` returns a dict; gate premium features on its `features_enabled`
flag (fail-open — only a confirmed refunded/disabled licence turns them off).

### Build a single `.exe`

```powershell
pyinstaller --onefile --windowed --name "YourApp" app.py
```

The `.py` modules (`se_licence`, `licence_config`) are imported, so PyInstaller bundles
them automatically — no `--add-data` needed for them.

### Why the self-update needs a "helper"

A running single-file `.exe` (PyInstaller `--onefile`) unpacks to a temp folder and
**locks its own file**, so it cannot overwrite itself. The swap works like this:

1. The new `.exe` is downloaded to a temp folder; its **SHA-256** is checked against the
   value from `/update`.
2. A copy of the new exe is launched as a **helper** with `--se-apply-update …`.
3. The app exits; the helper waits for it to close (by PID), replaces the old `.exe`, and
   relaunches it.

`se_licence.Updater.try_run_updater_mode(sys.argv)` on the **first line of `main()`** is
what runs in that helper process. A `.msi` package is handed to `msiexec /qb` instead of
being swapped.

> **Packaging note:** this in-place swap suits a single-file `.exe`. A PyInstaller
> *folder* build (`--onedir`) is a whole directory, which cannot be swapped as one file —
> use `--onefile` for self-update, or ship an installer and upload that as the package.

---

## 4. Any other language (the raw recipe)

The Python client is optional. In any language:

```
Activate:  POST /activate  key, product, instance, instance_type=device
Validate:  POST /validate  key, product, instance, instance_type=device
Update:    GET  /update?key=…&product=…&version=…&instance=…&instance_type=device
Download:  GET  <package URL from /update>   → verify sha256 → install
```

Store `status` + `valid_until` locally, re-check periodically, and gate features
**fail-open** on the last known status. See [`rest-reference.md`](rest-reference.md) and
[`openapi.yaml`](openapi.yaml) (machine-readable, for codegen).

---

## 5. Code signing (before selling to customers)

An unsigned `.exe` downloaded from the internet triggers a Windows **SmartScreen**
warning. For internal testing this is fine; for paying customers, sign your `.exe` with an
**Authenticode code-signing certificate** (~100–300 €/year). This is independent of
licensing — it is about Windows trusting your publisher identity. The server already
provides the SHA-256; ship a signed binary on top.

---

## 6. Honest scope

Licensing is a **business mechanism, not unbreakable copy protection** — the app runs on
the customer's machine. Real enforcement is server-side: updates require a valid key, and
downloads are signed and short-lived. Keep the client **fail-open** so a server outage or
an expired licence never leaves a paying customer with a dead app (it keeps working; only
updates pause). Same philosophy as the WordPress and .NET clients —
see [`what-licensing-does.md`](what-licensing-does.md).
