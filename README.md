# smartEngin Licence Client

A small, **build-free** PHP library to license and auto-update your WordPress plugin
through a [smartEngin Licence & buy](https://smartengin.de) server. Drop it in, create
one object, done — no npm, no build step.

> **Sell your own plugin with licensing, updates, and a licence panel — in ~15 lines.**

## Quickstart

1. **Register your product** on the licence server (*Licence & buy → Products*): set a
   **slug** and a **latest version**.
2. **Bundle** this library in your plugin under `lib/smartengin-licence-client/`.
3. **Instantiate it once** in your main plugin file:

   ```php
   require_once __DIR__ . '/lib/smartengin-licence-client/self-client.php';

   $GLOBALS['my_licence'] = new Self_Client( array(
       'server_url'  => 'https://your-licence-server.example', // keep in ONE constant
       'slug'        => 'my-plugin-slug',          // = product slug on the server
       'plugin_file' => __FILE__,
       'version'     => '1.0.0',
   ) );
   ```

4. **Show the panel** anywhere in your admin: `$GLOBALS['my_licence']->render_panel();`
5. **Gate premium features** fail-open on `get_state()['status']` (`active`/`expired` =
   licensed).

That's it. Updates then flow through the normal WordPress update screens automatically.

> **Not WordPress?** The same server licenses and **silently auto-updates Windows desktop
> apps** too — reference clients for **.NET/C#** and **Python** are included. See
> [`docs/windows-software-guide.md`](docs/windows-software-guide.md) and
> [`docs/python-software-guide.md`](docs/python-software-guide.md).

## What's in here

| Path | Contents |
|---|---|
| `self-client.php`, `includes/` | The client library (`Self_Client`). |
| `docs/integration-guide.md` | Full step-by-step integration guide (K2). |
| `docs/rest-reference.md` | REST endpoints, human-readable (K3). |
| `docs/openapi.yaml` | Machine-readable API spec (K3) for codegen / other languages. |
| `docs/windows-software-guide.md` | Selling & silently auto-updating **Windows / non-WordPress** software (with the .NET reference client). |
| `docs/python-software-guide.md` | The same, for **Python** desktop apps (dependency-free `se_licence.py` reference client). |
| `docs/what-licensing-does.md` | Honest scope + a **"give this to your AI"** prompt (K5). |
| `example/smartengin-licence-example/` | A minimal, working reference plugin (K4). |
| `clients/dotnet/`, `clients/python/` | Reference clients for **non-WordPress** software (.NET/C# and Python). See [`clients/README.md`](clients/README.md). |
| `llms.txt` | Wayfinder for AI tools. |

## Honest note

Licensing self-hosted PHP is a **business mechanism, not a copy protection** — the code
runs on the customer's server and WordPress plugins are GPL. Real enforcement is
server-side (update channel, signed downloads, activation limits). Keep the client
**fail-open**. Details: [`docs/what-licensing-does.md`](docs/what-licensing-does.md).

## Support

Provided **as-is** for the community. Issues and pull requests are welcome, but no
support is guaranteed. Use at your own responsibility.

## Licence

- Library code: **GPL-2.0-or-later** (consistent with the WordPress plugins).
- Documentation examples and snippets: **free to use** in your own projects, without
  warranty.
