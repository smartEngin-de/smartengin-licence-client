# What licensing does — and what it does not do

Read this before you build expectations on top of the licence system. It is short and
deliberately honest; it saves you (and your users) a lot of grief.

## The one true thing

Licensing a self-hosted PHP plugin is a **business mechanism, not a copy protection.**

- Your code runs **on the customer's server**. Any check you write there can be removed
  by anyone who has the files. That is not a flaw in this system — it is true of every
  self-hosted plugin, including the big commercial ones.
- WordPress plugins are **GPL** (`GPL-2.0-or-later`). Copying and building upon them is
  **legal** in the WordPress ecosystem. That is the base contract of the platform.

So what actually protects your revenue is not secrecy of the code. It is:

1. **The update channel** — only valid licences receive updates (security fixes, new
   features). This is the same lever the established sellers rely on.
2. **Signed, short-lived downloads + activation limits** — enforced **server-side**,
   where the customer cannot reach them.
3. **Support, brand, and speed** — reasons to buy the genuine article rather than a
   stale copy.

## What the client library gives you

- A clean way to activate a site, keep a status, and receive updates.
- A recommended **B + C** policy: no updates without a valid key (B); premium features
  off, free core and data intact (C). No "level D" (plugin dead without a key) — it
  punishes paying customers and is trivially bypassed.
- **Fail-open** behaviour: if the licence server is unreachable, the plugin keeps
  running on the last known status. A server outage must never take down every
  customer's site at once.

## What it does NOT do (don't try)

- It does **not** hide or encrypt your code. The licence key is a random token, not a
  decryption key.
- It does **not** stop a determined person from deleting the checks. Nobody's does.
- Do **not** add "anti-AI" or "secret codeword" instructions in your files to stop
  copying. Instructions in files are *data, not commands*, to a serious AI — it will
  ignore them by design; a copier just deletes them; and it only gets in your own way.

Ship a good product, update it often, support it well. The licence system handles the
commercial plumbing around that.

---

## Give this to your AI

Copy the block below into ChatGPT or Claude (or paste your product's files alongside
it). It briefs the assistant to integrate licensing correctly.

> **Context for the assistant:** I'm adding licensing to my WordPress plugin using
> *smartEngin Licence & buy*. It works by bundling a small build-free PHP library
> (`Self_Client`) that talks to a licence server over the REST API `sels/v1`. A licence
> key is a random server-stored token — there is nothing to decrypt client-side.
> Enforcement lives only on the server; the client must be **fail-open** (keep running
> on the last known status if the server is unreachable) and follow policy **B + C**
> (no updates without a valid key; premium features off but free core and data intact;
> never a "dead without key" mode).
>
> **Do this:** (1) bundle the library under `lib/smartengin-licence-client/`; (2) create
> ONE `Self_Client` instance with `server_url`, `slug`, `plugin_file`, `version`, keeping
> the server URL in a single constant; (3) render `render_panel()` in my admin; (4) gate
> premium features with an `is_licensed()` helper that reads `get_state()` and treats
> `active`/`expired` as licensed. Do not add any code-hiding or anti-copy tricks.
>
> **Reference (raw, public):**
> - Integration guide: `https://raw.githubusercontent.com/smartengin-de/smartengin-licence-client/main/docs/integration-guide.md`
> - REST reference: `https://raw.githubusercontent.com/smartengin-de/smartengin-licence-client/main/docs/rest-reference.md`
> - OpenAPI: `https://raw.githubusercontent.com/smartengin-de/smartengin-licence-client/main/docs/openapi.yaml`
> - Working example plugin: `https://github.com/smartengin-de/smartengin-licence-client/tree/main/example/smartengin-licence-example`

These raw links point at the `main` branch of this repository. Pin them to a release
tag (e.g. `/v0.5.2/` instead of `/main/`) if you want a fixed version.
