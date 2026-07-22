using System;
using System.IO;
using System.Text.Json;

namespace SmartEngin.Licence;

/// <summary>
/// Persists the last-known-good licence status so validation can fail open when
/// the server is unreachable (Kein-Bricking).
/// </summary>
internal static class LicenceCache
{
    private const string FileName = "status.json";

    public static void Save(string slug, LicenceStatus status)
    {
        try
        {
            var dir = LicencePaths.DataDir(slug);
            Directory.CreateDirectory(dir);
            File.WriteAllText(Path.Combine(dir, FileName), JsonSerializer.Serialize(status));
        }
        catch { /* best effort */ }
    }

    public static LicenceStatus? Load(string slug)
    {
        try
        {
            var file = Path.Combine(LicencePaths.DataDir(slug), FileName);
            if (!File.Exists(file))
            {
                return null;
            }
            var s = JsonSerializer.Deserialize<LicenceStatus>(File.ReadAllText(file));
            if (s == null)
            {
                return null;
            }
            // Re-flag as cache-sourced regardless of what was stored.
            return new LicenceStatus
            {
                Valid = s.Valid,
                State = s.State,
                ValidUntil = s.ValidUntil,
                ActivationsLeft = s.ActivationsLeft,
                CheckedAt = s.CheckedAt,
                FromCache = true,
            };
        }
        catch
        {
            return null;
        }
    }
}
