using System;
using System.IO;

namespace SmartEngin.Licence;

/// <summary>
/// Everything the client needs to talk to a smartEngin Licence &amp; buy server.
/// Create one instance and hand it to <see cref="LicenceClient"/> and
/// <see cref="Updater"/>.
/// </summary>
public sealed class LicenceOptions
{
    /// <summary>The Licence &amp; buy site, e.g. "https://smartengin.de".</summary>
    public required string ServerBaseUrl { get; init; }

    /// <summary>The product slug configured in the backend, e.g. "kifox-chat".</summary>
    public required string ProductSlug { get; init; }

    /// <summary>The version currently installed, e.g. "1.0.0".</summary>
    public required string AppVersion { get; init; }

    /// <summary>
    /// Path of the executable to replace on update. Defaults to the running
    /// process when null (correct for a single-file published app).
    /// </summary>
    public string? ExecutablePath { get; init; }

    /// <summary>Activation identity kind. "device" for desktop apps.</summary>
    public string InstanceType { get; init; } = "device";

    /// <summary>Optional human label stored with the activation (e.g. the PC name).</summary>
    public string? Label { get; init; }

    /// <summary>Base of the REST namespace (sels/v1) on the server.</summary>
    internal string ApiBase => ServerBaseUrl.TrimEnd('/') + "/wp-json/sels/v1";
}

/// <summary>Where per-product client state (device id, cached status) is stored.</summary>
internal static class LicencePaths
{
    public static string DataDir(string slug)
        => Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "SmartEnginLicence",
            Sanitize(slug));

    private static string Sanitize(string s)
    {
        foreach (var c in Path.GetInvalidFileNameChars())
        {
            s = s.Replace(c, '_');
        }
        return string.IsNullOrWhiteSpace(s) ? "product" : s;
    }
}
