using System;
using System.Collections.Generic;
using System.Globalization;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;

namespace SmartEngin.Licence;

/// <summary>
/// Activate, validate and deactivate a licence against a smartEngin Licence &amp;
/// buy server. Validation fails open: on any network error the last-known-good
/// status is returned, so the app never breaks when the server is unreachable.
/// </summary>
public sealed class LicenceClient
{
    private readonly LicenceOptions _o;
    private readonly SelsApi _api;
    private readonly string _instance;

    public LicenceClient(LicenceOptions options)
    {
        _o = options;
        _api = new SelsApi(options);
        _instance = MachineId.Get(options.ProductSlug);
    }

    /// <summary>The device's activation identifier (opaque, no personal data).</summary>
    public string Instance => _instance;

    /// <summary>Register this device against a licence key. Idempotent server-side.</summary>
    public async Task<ActivationResult> ActivateAsync(string key, CancellationToken ct = default)
    {
        try
        {
            var form = new List<KeyValuePair<string, string>>
            {
                new("key", key),
                new("product", _o.ProductSlug),
                new("instance", _instance),
                new("instance_type", _o.InstanceType),
            };
            if (!string.IsNullOrWhiteSpace(_o.Label))
            {
                form.Add(new("label", _o.Label!));
            }

            using var doc = await _api.PostAsync("/activate", form, ct).ConfigureAwait(false);
            var root = doc.RootElement;

            if (root.TryGetProperty("success", out var ok) && ok.ValueKind == JsonValueKind.True)
            {
                var status = ParseStatus(root);
                LicenceCache.Save(_o.ProductSlug, status);
                return new ActivationResult { Success = true, Status = status };
            }

            return new ActivationResult
            {
                Success = false,
                Error = GetString(root, "error"),
                Message = GetString(root, "message"),
            };
        }
        catch (Exception ex)
        {
            return new ActivationResult { Success = false, Error = "network_error", Message = ex.Message };
        }
    }

    /// <summary>Re-check the licence. On a network error returns the cached status
    /// (or a permissive default), never throwing.</summary>
    public async Task<LicenceStatus> ValidateAsync(string key, CancellationToken ct = default)
    {
        try
        {
            var form = new List<KeyValuePair<string, string>>
            {
                new("key", key),
                new("product", _o.ProductSlug),
                new("instance", _instance),
                new("instance_type", _o.InstanceType),
            };

            using var doc = await _api.PostAsync("/validate", form, ct).ConfigureAwait(false);
            var status = ParseStatus(doc.RootElement);
            LicenceCache.Save(_o.ProductSlug, status);
            return status;
        }
        catch
        {
            return LicenceCache.Load(_o.ProductSlug)
                ?? new LicenceStatus { Valid = true, State = LicenceState.Unknown, FromCache = true };
        }
    }

    /// <summary>Release this device's activation slot. Best-effort (never throws).</summary>
    public async Task DeactivateAsync(string key, CancellationToken ct = default)
    {
        try
        {
            var form = new List<KeyValuePair<string, string>>
            {
                new("key", key),
                new("instance", _instance),
                new("instance_type", _o.InstanceType),
            };
            using var _ = await _api.PostAsync("/deactivate", form, ct).ConfigureAwait(false);
        }
        catch { /* deactivation is best-effort */ }
    }

    /// <summary>Parse a /activate or /validate body into a status.</summary>
    private static LicenceStatus ParseStatus(JsonElement root)
    {
        var statusText = GetString(root, "status");

        var state = statusText switch
        {
            "active" => LicenceState.Active,
            "expired" => LicenceState.Expired,
            "refunded" => LicenceState.Refunded,
            "disabled" => LicenceState.Disabled,
            _ => LicenceState.Unknown,
        };

        // /validate returns an explicit "valid"; /activate implies it via status.
        bool valid = root.TryGetProperty("valid", out var v)
            ? v.ValueKind == JsonValueKind.True
            : state == LicenceState.Active;

        DateTime? until = null;
        var vu = GetString(root, "valid_until");
        if (!string.IsNullOrEmpty(vu)
            && DateTime.TryParse(vu, CultureInfo.InvariantCulture, DateTimeStyles.None, out var dt))
        {
            until = dt;
        }

        int? left = null;
        if (root.TryGetProperty("activations_left", out var al) && al.ValueKind == JsonValueKind.Number)
        {
            left = al.GetInt32();
        }

        return new LicenceStatus
        {
            Valid = valid,
            State = state,
            ValidUntil = until,
            ActivationsLeft = left,
            CheckedAt = DateTimeOffset.UtcNow,
            FromCache = false,
        };
    }

    private static string GetString(JsonElement el, string prop)
        => el.TryGetProperty(prop, out var p) && p.ValueKind == JsonValueKind.String
            ? p.GetString() ?? string.Empty
            : string.Empty;
}
