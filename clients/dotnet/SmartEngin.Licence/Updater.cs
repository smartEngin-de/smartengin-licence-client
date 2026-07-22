using System;
using System.Diagnostics;
using System.Globalization;
using System.IO;
using System.Net.Http;
using System.Security.Cryptography;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;

namespace SmartEngin.Licence;

/// <summary>
/// Silent self-update, WordPress-style: check the server for a newer version,
/// download it, verify its SHA-256, then swap the running .exe and restart.
///
/// A running single-file .exe locks itself, so it cannot overwrite its own file.
/// The swap is therefore done by a tiny helper: a copy of the NEW exe is launched
/// with <c>--se-apply-update</c>, waits for this process to exit, replaces the
/// old file and relaunches it. Put <see cref="TryRunUpdaterMode"/> on the very
/// first line of Main so that helper invocation is handled before the app's own
/// UI starts.
/// </summary>
public sealed class Updater
{
    private readonly LicenceOptions _o;
    private readonly SelsApi _api;
    private readonly string _instance;

    public Updater(LicenceOptions options)
    {
        _o = options;
        _api = new SelsApi(options);
        _instance = MachineId.Get(options.ProductSlug);
    }

    /// <summary>Ask the server for a newer version. Returns null when up to date, on
    /// an invalid/expired licence, or on any network error (fail-open).</summary>
    public async Task<UpdateInfo?> CheckForUpdateAsync(string key, CancellationToken ct = default)
    {
        try
        {
            var q = "/update"
                + "?key=" + Uri.EscapeDataString(key)
                + "&product=" + Uri.EscapeDataString(_o.ProductSlug)
                + "&version=" + Uri.EscapeDataString(_o.AppVersion)
                + "&instance=" + Uri.EscapeDataString(_instance)
                + "&instance_type=" + Uri.EscapeDataString(_o.InstanceType);

            using var doc = await _api.GetAsync(q, ct).ConfigureAwait(false);
            var root = doc.RootElement;

            if (!root.TryGetProperty("update", out var upd) || upd.ValueKind != JsonValueKind.True)
            {
                return null;
            }

            return new UpdateInfo
            {
                NewVersion = Str(root, "new_version"),
                PackageUrl = Str(root, "package"),
                Sha256 = Str(root, "sha256"),
                FileName = Str(root, "filename"),
                Platform = Str(root, "platform"),
                ChangelogUrl = Str(root, "changelog_url"),
            };
        }
        catch
        {
            return null;
        }
    }

    /// <summary>Download the package to a staging folder and verify its SHA-256.
    /// Returns the verified file path. Throws on a checksum mismatch.</summary>
    public async Task<string> DownloadAndVerifyAsync(UpdateInfo info, CancellationToken ct = default)
    {
        if (string.IsNullOrWhiteSpace(info.PackageUrl))
        {
            throw new InvalidOperationException("The update has no package URL.");
        }

        var dir = Path.Combine(Path.GetTempPath(), "SmartEnginUpdate", SafeSlug(_o.ProductSlug));
        Directory.CreateDirectory(dir);

        // Guard the server-provided file name against path traversal.
        var name = string.IsNullOrWhiteSpace(info.FileName) ? "payload.bin" : Path.GetFileName(info.FileName);
        var target = Path.Combine(dir, name);

        using (var resp = await _api.Client
            .GetAsync(info.PackageUrl, HttpCompletionOption.ResponseHeadersRead, ct)
            .ConfigureAwait(false))
        {
            resp.EnsureSuccessStatusCode();
            await using var fs = File.Create(target);
            await resp.Content.CopyToAsync(fs, ct).ConfigureAwait(false);
        }

        if (!string.IsNullOrEmpty(info.Sha256))
        {
            var actual = Sha256File(target);
            if (!string.Equals(actual, info.Sha256, StringComparison.OrdinalIgnoreCase))
            {
                try { File.Delete(target); } catch { /* ignore */ }
                throw new InvalidOperationException(
                    $"Checksum mismatch: expected {info.Sha256}, got {actual}.");
            }
        }

        return target;
    }

    /// <summary>
    /// Replace the running executable with the verified package and restart.
    /// For a single-file .exe this launches the swap helper and exits the app.
    /// A .msi package is handed to the Windows installer instead. This method
    /// does not return (the process exits).
    /// </summary>
    public void ApplyUpdateAndRestart(string verifiedPackagePath, bool relaunch = true)
    {
        var target = _o.ExecutablePath ?? Environment.ProcessPath
            ?? throw new InvalidOperationException("Cannot resolve the executable path to update.");

        var ext = Path.GetExtension(verifiedPackagePath).ToLowerInvariant();
        if (ext == ".msi")
        {
            Process.Start(new ProcessStartInfo("msiexec", $"/i \"{verifiedPackagePath}\" /qb")
            {
                UseShellExecute = true,
            });
            Environment.Exit(0);
            return;
        }

        // Copy the new exe to a helper path that is NOT the file being replaced,
        // so it can run while the old exe is overwritten.
        var helper = Path.Combine(Path.GetDirectoryName(verifiedPackagePath)!, "se-apply.exe");
        File.Copy(verifiedPackagePath, helper, overwrite: true);

        var args =
            "--se-apply-update"
            + " --target \"" + target + "\""
            + " --source \"" + verifiedPackagePath + "\""
            + " --pid " + Environment.ProcessId.ToString(CultureInfo.InvariantCulture)
            + (relaunch ? " --relaunch" : string.Empty);

        Process.Start(new ProcessStartInfo(helper, args) { UseShellExecute = false });
        Environment.Exit(0);
    }

    /// <summary>
    /// Handle the swap-helper invocation. Call this as the FIRST statement in
    /// Main: when the process was started with <c>--se-apply-update</c> it waits
    /// for the old app to exit, replaces its file, relaunches it and exits,
    /// returning true. Otherwise it returns false and the app continues normally.
    /// </summary>
    public static bool TryRunUpdaterMode(string[] args)
    {
        if (Array.IndexOf(args, "--se-apply-update") < 0)
        {
            return false;
        }

        var target = ArgVal(args, "--target");
        var source = ArgVal(args, "--source");
        var relaunch = Array.IndexOf(args, "--relaunch") >= 0;
        int pid = int.TryParse(ArgVal(args, "--pid"), NumberStyles.Integer, CultureInfo.InvariantCulture, out var p)
            ? p
            : 0;

        ApplySwap(target, source, pid, relaunch);

        Environment.Exit(0);
        return true;
    }

    /// <summary>
    /// The actual file swap, factored out of <see cref="TryRunUpdaterMode"/> so it
    /// is testable without exiting the process: wait for the old process to close,
    /// replace the target with the source, optionally relaunch, then clean up.
    /// </summary>
    internal static void ApplySwap(string target, string source, int pid, bool relaunch)
    {
        // Wait for the old process to exit so its .exe unlocks.
        if (pid > 0)
        {
            try
            {
                using var proc = Process.GetProcessById(pid);
                proc.WaitForExit(20000);
            }
            catch { /* already gone */ }
        }

        // Replace the file, retrying while the lock clears.
        for (int attempt = 0; attempt < 20; attempt++)
        {
            try
            {
                File.Copy(source, target, overwrite: true);
                break;
            }
            catch
            {
                Thread.Sleep(250);
            }
        }

        if (relaunch)
        {
            try { Process.Start(new ProcessStartInfo(target) { UseShellExecute = true }); } catch { /* ignore */ }
        }

        try { File.Delete(source); } catch { /* helper file cleans up with TEMP */ }
    }

    private static string ArgVal(string[] args, string name)
    {
        int i = Array.IndexOf(args, name);
        return (i >= 0 && i + 1 < args.Length) ? args[i + 1] : string.Empty;
    }

    private static string Sha256File(string path)
    {
        using var s = File.OpenRead(path);
        return Convert.ToHexString(SHA256.HashData(s)).ToLowerInvariant();
    }

    private static string SafeSlug(string slug)
    {
        foreach (var c in Path.GetInvalidFileNameChars())
        {
            slug = slug.Replace(c, '_');
        }
        return string.IsNullOrWhiteSpace(slug) ? "product" : slug;
    }

    private static string Str(JsonElement el, string prop)
        => el.TryGetProperty(prop, out var pr) && pr.ValueKind == JsonValueKind.String
            ? pr.GetString() ?? string.Empty
            : string.Empty;
}
