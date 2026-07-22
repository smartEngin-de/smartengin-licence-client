# smartEngin Licence — Python reference client

A small, **dependency-free** Python client (standard library only) to license and
silently auto-update a Python desktop app through a
[smartEngin Licence & buy](https://smartengin.de) server. It mirrors the
[.NET reference client](../dotnet/) one-to-one and talks to the same public REST API
(`sels/v1`).

Full walkthrough: [`../../docs/python-software-guide.md`](../../docs/python-software-guide.md).

## Files

| File | Purpose |
|---|---|
| `se_licence.py` | The client: `machine_id`, `LicenceClient` (activate/validate/deactivate, **fail-open**), `Updater` (check / download+SHA-256 / apply-and-restart), and the `--onefile` swap helper `Updater.try_run_updater_mode`. |
| `licence_config.example.py` | Copy to `licence_config.py` and fill in `SERVER_URL`, `PRODUCT_SLUG`, `APP_VERSION`. |

## Quickstart (three touch-points)

```python
import sys, se_licence, licence_config

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

    # (2) Locked until the first activation — fail-open afterwards.
    if not se_licence.is_licensed(slug):
        key = ask_user_for_key()                 # your window / prompt
        if not client.activate(key)["success"]:
            return
        se_licence.save_key(slug, key)           # remember it — never ask again

    # (3) Validate (fail-open) + silent update, ideally in the background.
    key = se_licence.load_key(slug)
    client.validate(key)                         # refreshes cached status; never raises
    info = updater.check_for_update(key)
    if info:
        package = updater.download_and_verify(info)     # raises on checksum mismatch
        updater.apply_update_and_restart(package)       # replaces the .exe and restarts

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
downloads, activation limits). Keep the client **fail-open** so a server outage or an
expired licence never leaves a paying customer with a dead app. See
[`../../docs/what-licensing-does.md`](../../docs/what-licensing-does.md).

## Licence

Provided **as-is** for the community, without warranty. Free to use and adapt in your own
projects.
