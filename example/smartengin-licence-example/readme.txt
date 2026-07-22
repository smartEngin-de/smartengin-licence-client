=== smartEngin Licence Example ===
Requires at least: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

A minimal, working reference plugin for licensing your own plugin with smartEngin
Licence & buy. It is meant to be read and forked, not shipped as-is.

== What it demonstrates ==

1. Loading the client library and creating ONE Self_Client instance.
2. Rendering the built-in licence panel (key field + activate/deactivate + status).
3. Gating a premium feature FAIL-OPEN via get_state() (policy B + C).
4. Automatic updates through the WordPress update channel (no extra code).

== Setup ==

1. On your licence server, create a product with the slug "smartengin-licence-example"
   (or change SLE_SLUG in the plugin file to match your product).
2. Set SLE_LICENCE_SERVER to your licence server URL (default: https://your-licence-server.example).
3. Install and activate this plugin, then open Settings -> Licence Example.
4. Paste a valid licence key and click Activate. The status turns green and the
   "Premium feature ACTIVE" notice appears.

See ../../docs/integration-guide.md for the full explanation.
