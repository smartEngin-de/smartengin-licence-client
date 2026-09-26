# smartEngin Licence — Python reference client

A small, **dependency-free** Python client (standard library only) to license and
silently auto-update a Python desktop app through a
[smartEngin Licence & buy](https://smartengin.de) server. It mirrors the
[.NET reference client](../dotnet/) one-to-one and talks to the same public REST API
(`sels/v1`). The same code runs on **Windows, macOS and Linux** — see the
[guide's platform notes](../../docs/python-software-guide.md#other-platforms-macos--linux).

Full walkthrough: [`../../docs/python-software-guide.md`](../../docs/python-software-guide.md).

## Files

| File | Purpose |
|---|---|
| `se_licence.py` | The client: `machine_id`, `LicenceClient` (activate/validate/deactivate), `has_key` / `is_licensed` / `status_mode`, `Updater` (check / download+SHA-256 / apply-and-restart), and the `--onefile` swap helper `Updater.try_run_updater_mode`. |
| `licence_config.example.py` | Copy to `licence_config.py` and fill in `SERVER_URL`, `PRODUCT_SLUG`, `APP_VERSION`. |

## When premium features switch off (se_licence 0.2.0)

`se_licence.is_licensed(slug)` is **the** check (and `status["features_enabled"]`
on a status returned by `validate`). It follows the server's rule (smartEngin
Licence & buy 1.6.43 and later):

| Licence | `status["mode"]` | premium |
|---|---|---|
| Active, or lifetime | `licensed` | on |
| Expired **one-off purchase** | `licensed` | on — only updates stop |
| Expired **subscription**, within `grace_days` (3) of its end date | `grace` | on — ask to renew; `status["lock_time"]` is the switch-off moment (Unix time) |
| Expired subscription, grace days over | `locked` | **off** |
| Refunded or disabled | `locked` | **off** |
| Unknown / server never reached | `licensed` | on (fail-open) |

- `status["effective_state"]` reads `expired` the moment the end date passes.
- Decided from the **cached** status only: the lock happens on time even offline,
  and a server outage never switches a paying customer off early. A renewal
  switches premium back on at the next `validate`.
- A server older than 1.6.43 sends no `subscription` / `grace_days`: the licence is
  then treated as a one-off purchase and never locks (as in 0.1.0).
- **Changed in 0.2.0:** `is_licensed(slug)` used to mean "a key is stored". That
  question is now `has_key(slug)`.
- `activate()` now returns the server's refusal (`error` + readable `message`, e.g.
  `license_expired` for an expired key on a NEW device, `limit_reached`) instead of
  `network_error`. A device that already had the key stays activated.

## Quickstart (four touch-points)

```python
import sys, time, se_licence, licence_config

def main():
    # (1) FIRST line: if launched as the self-update helper, swap the file and exit.
    if se_licence.Updater.try_run_updater_mode(sys.argv):
        return

    options = se_licence.LicenceOptions(
        server_url=licence_config.SERVER_URL,
        product_slug=licence_config.PRODUCT_SLUG,
        app_version=licence_config.APP_VERSION,
    )
    client, updater = se_licence.LicenceClient(options), se_licence.Updater(options)
    slug = licence_config.PRODUCT_SLUG

    # (2) Ask for a key until the first activation.
    if not se_licence.has_key(slug):
        key = ask_user_for_key()                 # your window / prompt
        result = client.activate(key)
        if not result["success"]:
            show_message(result["message"])      # e.g. license_expired: the server's own text
            return
        se_licence.save_key(slug, key)           # remember it — never ask again

    # (3) Validate + silent update, ideally in the background.
    key = se_licence.load_key(slug)
    status = client.validate(key)                # refreshes cached status; never raises
    if status["mode"] == "grace":                # expired subscription, still in its grace days
        show_message("Please renew — premium switches off on %s."
                     % time.strftime("%Y-%m-%d", time.localtime(status["lock_time"])))
    info = updater.check_for_update(key)
    if info:
        package = updater.download_and_verify(info)     # raises on checksum mismatch
        updater.apply_update_and_restart(package)       # replaces the .exe and restarts

    # (4) Gate premium features — anywhere, any time, no server call.
    if se_licence.is_licensed(slug):
        enable_premium()

    start_your_app()

if __name__ == "__main__":
    main()
```

## Build a single .exe

```powershell
pyinstaller --onefile --windowed --name "YourApp" app.py
```

`se_licence` and `licence_config` are imported, so PyInstaller bundles them automatically.
The in-place self-update needs a **single-file** `.exe` (`--onefile`); a `--onedir` folder
build cannot be swapped as one file — use `--onefile`, or ship an installer as the package.

## Honest scope

Licensing is a **business mechanism, not unbreakable copy protection** — the app runs on
the customer's machine. Real enforcement is server-side (update channel, signed short-lived
downloads, activation limits). The client stays **fail-open**: a server outage or an
expired one-off purchase never leaves a paying customer with a dead app — only an
unpaid subscription (after its grace days) or a refunded key switches premium off. See
[`../../docs/what-licensing-does.md`](../../docs/what-licensing-does.md).

## Licence

Provided **as-is** for the community, without warranty. Free to use and adapt in your own
projects.
