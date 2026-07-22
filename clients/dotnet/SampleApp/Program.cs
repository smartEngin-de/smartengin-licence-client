using System;
using System.IO;
using System.Linq;
using System.Reflection;
using System.Text.Json;
using System.Threading.Tasks;
using SmartEngin.Licence;

// ---------------------------------------------------------------------------
// 1) FIRST line: if we were launched as the self-update helper, do the swap and
//    exit. Otherwise this returns false and the app starts normally.
// ---------------------------------------------------------------------------
if (Updater.TryRunUpdaterMode(args))
{
    return;
}

// ---------------------------------------------------------------------------
// 2) Configure the client. Point ServerBaseUrl at your Licence & buy site and
//    ProductSlug at the product you created in the backend.
// ---------------------------------------------------------------------------
// A CUSTOMER-READY build bakes the server URL + product slug into the assembly
// (MSBuild -p:SeServer=… -p:SeSlug=…), so the end user only enters their key —
// exactly like a purchased app. A plain TEST build has neither baked in, so it
// asks once and remembers the answers in a small config file next to the .exe
// (which survives self-updates because the .exe is replaced in place).
string? Meta(string k) => Assembly.GetExecutingAssembly()
    .GetCustomAttributes<AssemblyMetadataAttribute>()
    .FirstOrDefault(a => a.Key == k)?.Value;

var bakedServer = Meta("SeServer");
var bakedSlug   = Meta("SeSlug");

string server, slug;
if (!string.IsNullOrWhiteSpace(bakedServer) && !string.IsNullOrWhiteSpace(bakedSlug))
{
    // Customer-ready: fixed for this product; the customer never sees these.
    server = bakedServer!;
    slug   = bakedSlug!;
}
else
{
    var cfgPath = Path.Combine(
        Path.GetDirectoryName(Environment.ProcessPath ?? Environment.CurrentDirectory) ?? ".",
        "licence-test-config.json");

    server = "";
    slug = "";
    if (File.Exists(cfgPath))
    {
        try
        {
            var j = JsonDocument.Parse(File.ReadAllText(cfgPath)).RootElement;
            server = j.TryGetProperty("ServerBaseUrl", out var s) ? s.GetString() ?? "" : "";
            slug   = j.TryGetProperty("ProductSlug", out var p) ? p.GetString() ?? "" : "";
        }
        catch { /* re-ask below */ }
    }
    if (string.IsNullOrWhiteSpace(server))
    {
        Console.Write("Server URL [https://your-licence-server.example]: ");
        server = (Console.ReadLine() ?? "").Trim();
        if (server.Length == 0) server = "https://your-licence-server.example";
    }
    if (string.IsNullOrWhiteSpace(slug))
    {
        Console.Write("Product slug (exactly as in the backend): ");
        slug = (Console.ReadLine() ?? "").Trim();
    }
    try { File.WriteAllText(cfgPath, JsonSerializer.Serialize(new { ServerBaseUrl = server, ProductSlug = slug })); } catch { /* best effort */ }
}

var options = new LicenceOptions
{
    ServerBaseUrl = server,
    ProductSlug   = slug,
    AppVersion    = Assembly.GetExecutingAssembly().GetName().Version?.ToString(3) ?? "1.0.0",
    Label         = Environment.MachineName,
};

var client  = new LicenceClient(options);
var updater = new Updater(options);

Console.WriteLine($"SampleApp v{options.AppVersion}");
Console.WriteLine($"Device id: {options.ProductSlug} / {client.Instance[..12]}…");
Console.WriteLine();

Console.Write("Enter your licence key: ");
var key = (Console.ReadLine() ?? string.Empty).Trim();

// 3) Activate this device.
var activation = await client.ActivateAsync(key);
if (activation.Success)
{
    var st = activation.Status!;
    Console.WriteLine($"Activated. State: {st.State}, valid until: {(st.ValidUntil?.ToString() ?? "lifetime")}, "
        + $"activations left: {(st.ActivationsLeft?.ToString() ?? "unlimited")}.");
}
else
{
    Console.WriteLine($"Activation failed: {activation.Error} — {activation.Message}");
}

// 4) Validate (fail-open) and gate features.
var status = await client.ValidateAsync(key);
Console.WriteLine($"Validation: state {status.State}, features "
    + $"{(status.FeaturesEnabled ? "ENABLED" : "DISABLED")}{(status.FromCache ? " (from cache)" : "")}.");

// 5) Check for and apply an update.
Console.WriteLine();
Console.WriteLine("Checking for updates…");
var update = await updater.CheckForUpdateAsync(key);
if (update is null)
{
    Console.WriteLine("You are up to date.");
}
else
{
    Console.WriteLine($"Update available: {update.NewVersion} ({update.Platform}).");
    Console.Write("Download and install now? [y/N] ");
    if ((Console.ReadLine() ?? string.Empty).Trim().Equals("y", StringComparison.OrdinalIgnoreCase))
    {
        Console.WriteLine("Downloading and verifying…");
        var package = await updater.DownloadAndVerifyAsync(update);
        Console.WriteLine($"Verified. Installing (the app will restart)…");
        updater.ApplyUpdateAndRestart(package);   // replaces this .exe and relaunches — does not return
    }
}

Console.WriteLine();
Console.WriteLine("Done. Press Enter to quit.");
Console.ReadLine();
