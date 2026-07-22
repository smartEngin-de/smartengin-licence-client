using System;
using System.IO;
using System.Security.Cryptography;
using System.Text;

namespace SmartEngin.Licence;

/// <summary>
/// Stable, privacy-preserving activation identifier for the "device" instance
/// type. A random GUID is generated once and stored under the app's local-data
/// folder, then hashed together with the machine name into an opaque 64-hex
/// string. No personal data ever leaves the machine — this is exactly the
/// `instance` value the server stores (comparable to a website's host name).
/// </summary>
public static class MachineId
{
    public static string Get(string productSlug)
    {
        var dir = LicencePaths.DataDir(productSlug);
        Directory.CreateDirectory(dir);
        var file = Path.Combine(dir, "instance.id");

        string guid;
        if (File.Exists(file))
        {
            guid = File.ReadAllText(file).Trim();
        }
        else
        {
            guid = Guid.NewGuid().ToString("N");
            try { File.WriteAllText(file, guid); } catch { /* best effort */ }
        }

        var raw = guid + "|" + Environment.MachineName;
        var hash = SHA256.HashData(Encoding.UTF8.GetBytes(raw));
        return Convert.ToHexString(hash).ToLowerInvariant();
    }
}
