# smartEngin Licence — .NET client

A small, dependency-free C# library that licenses and **auto-updates** a Windows
.NET application against a **smartEngin Licence & buy** server — the same way
WordPress plugins update themselves, but for desktop software.

- `SmartEngin.Licence/` — the reusable library (embed or reference it in your app).
- `SampleApp/` — a minimal console app showing the whole flow.

## What it does

1. **Activate** the device against a licence key (device id = a stable, anonymous
   identifier; no personal data leaves the machine).
2. **Validate** periodically, **fail-open**: if the server is unreachable the last
   known status is used, so an outage never bricks a paying customer.
3. **Self-update**: ask the server for a newer version, download it, verify its
   **SHA-256**, then silently replace the running `.exe` and restart.

Everything talks to the server's public `sels/v1` REST API. No external NuGet
packages — only `System.Net.Http` and `System.Text.Json` from the runtime.

## Prerequisites

- **.NET SDK 8.0** (free): https://dotnet.microsoft.com/download
  (only needed to *build*; end users just run the produced `.exe`.)

## Build & run

```bash
dotnet build SmartEngin.Licence.sln
dotnet run --project SampleApp
```

Produce a single-file `.exe` for distribution / for testing the update swap:

```bash
dotnet publish SampleApp -c Release
# → SampleApp\bin\Release\net8.0-windows\win-x64\publish\SampleApp.exe
```

## Integrate into your own app

Three touch-points:

```csharp
// 1) FIRST line of Main — handle the self-update helper mode.
if (Updater.TryRunUpdaterMode(args)) return;

// 2) Configure once.
var options = new LicenceOptions {
    ServerBaseUrl = "https://your-licence-server.example",
    ProductSlug   = "your-product-slug",
    AppVersion    = "1.0.0",              // your installed version
};
var client  = new LicenceClient(options);
var updater = new Updater(options);

// 3) Activate / validate / update.
await client.ActivateAsync(key);
var status = await client.ValidateAsync(key);   // status.FeaturesEnabled gates premium features
var update = await updater.CheckForUpdateAsync(key);
if (update is not null) {
    var pkg = await updater.DownloadAndVerifyAsync(update);
    updater.ApplyUpdateAndRestart(pkg);          // replaces the .exe and restarts
}
```

## How the silent update works

A running single-file `.exe` locks its own file and cannot overwrite itself. So:

1. The new `.exe` is downloaded to `%TEMP%\SmartEnginUpdate\<slug>\` and its
   SHA-256 is checked against the value from `/update`.
2. A copy of the new exe is launched as a **helper** with `--se-apply-update`.
3. The app exits; the helper waits for it to close, replaces the old `.exe`, and
   relaunches it. `TryRunUpdaterMode` (step 1 of the integration) is what runs in
   that helper process.

`.msi` packages are handed to `msiexec /qb` instead of being swapped.

## Backend setup (Licence & buy)

Create the product with **Platform = "Windows app (.exe / .msi)"**, upload the
new `.exe`/`.msi` as the **Update package**, and set **Latest version** to the new
version string. The server then returns it from `/update` with a signed download
link, the `sha256` and the real `filename` — which is exactly what this client
consumes.

## Code signing (before selling to customers)

An unsigned `.exe` downloaded from the internet triggers a Windows SmartScreen
warning. For internal testing this is fine; for paying customers, sign the `.exe`
with an Authenticode certificate (~100–300 €/year). This is independent of the
licensing system.

## Note: Python apps

The same server and the same update mechanism (download → verify SHA-256 → swap
the locked single-file exe via a helper) will be mirrored in a Python client
later, for PyInstaller `--onefile` apps such as KIFOX Chat. This .NET client is
the reference implementation.
