# Reference clients

Ready-to-use implementations of the licensing + silent auto-update recipe for software
**outside** WordPress. Each talks to the same public REST API (`sels/v1`).

| Folder | Language | Guide |
|---|---|---|
| [`dotnet/`](dotnet/) | .NET 8 / C# library (`SmartEngin.Licence` 0.2.0) + sample app | [`../docs/windows-software-guide.md`](../docs/windows-software-guide.md) |
| [`python/`](python/) | Dependency-free Python client (`se_licence.py` 0.2.0) | [`../docs/python-software-guide.md`](../docs/python-software-guide.md) |

Both follow the same rule as the PHP library 0.8.0 (server smartEngin Licence & buy
1.6.43+): an expired **one-off purchase** keeps its premium features (only updates
stop); an expired **subscription** keeps them for `grace_days` (3), then they switch
off until it is renewed; refunded/disabled switches off at once. The decision uses the
cached status only — on time even offline, and a server outage never locks early.
Against a server older than 1.6.43 nothing ever locks (as before).

> The **WordPress** PHP client (`Self_Client`) lives at the repository root
> (`self-client.php` + `includes/`), with a working example under `example/`. See the main
> [README](../README.md).

Build artefacts (`bin/`, `obj/`, `venv/`, `dist/`, `build/`, `__pycache__/`) are excluded
via `.gitignore` — only source ships.
