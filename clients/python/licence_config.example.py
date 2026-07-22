"""
Baked licence configuration — copy this file to `licence_config.py` and fill in
your product's values. These three constants are the Python equivalent of the
.NET client's baked MSBuild properties: with them in place the end user only ever
types their licence key (server address and slug are never shown).

- SERVER_URL   : your smartEngin Licence & buy site.
- PRODUCT_SLUG : the product slug, exactly as created in the backend.
- APP_VERSION  : the installed version. Bump on EVERY release — this is what the
                 app reports to /update.
"""

SERVER_URL = "https://smartengin.de"
PRODUCT_SLUG = "your-product-slug"
APP_VERSION = "1.0.0"
