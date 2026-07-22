# Reference clients

Ready-to-use implementations of the licensing + silent auto-update recipe for software
**outside** WordPress. Each talks to the same public REST API (`sels/v1`).

| Folder | Language | Guide |
|---|---|---|
| [`dotnet/`](dotnet/) | .NET 8 / C# library (`SmartEngin.Licence`) + sample app | [`../docs/windows-software-guide.md`](../docs/windows-software-guide.md) |
| [`python/`](python/) | Dependency-free Python client (`se_licence.py`) | [`../docs/python-software-guide.md`](../docs/python-software-guide.md) |

> The **WordPress** PHP client (`Self_Client`) lives at the repository root
> (`self-client.php` + `includes/`), with a working example under `example/`. See the main
> [README](../README.md).

Build artefacts (`bin/`, `obj/`, `venv/`, `dist/`, `build/`, `__pycache__/`) are excluded
via `.gitignore` — only source ships.
