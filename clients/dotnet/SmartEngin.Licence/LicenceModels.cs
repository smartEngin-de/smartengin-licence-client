using System;
using System.Text.Json.Serialization;

namespace SmartEngin.Licence;

/// <summary>Server-reported licence state.</summary>
public enum LicenceState
{
    Unknown,
    Active,
    Expired,
    Refunded,
    Disabled,
}

/// <summary>A licence's current status, as last seen from the server or the cache.</summary>
public sealed class LicenceStatus
{
    /// <summary>True only when the licence is active and not past its end date.</summary>
    public bool Valid { get; init; }

    public LicenceState State { get; init; } = LicenceState.Unknown;

    /// <summary>End date, or null for a lifetime licence.</summary>
    public DateTime? ValidUntil { get; init; }

    /// <summary>Remaining activations, or null when unlimited.</summary>
    public int? ActivationsLeft { get; init; }

    public DateTimeOffset CheckedAt { get; init; } = DateTimeOffset.UtcNow;

    /// <summary>True when this status came from the local cache (a network error).</summary>
    public bool FromCache { get; init; }

    /// <summary>
    /// Whether premium features should be available. Fail-open: only a confirmed
    /// refunded/disabled licence turns features off. Active, expired, unknown, or
    /// a cached value (server unreachable) all keep the app fully working, so an
    /// outage never bricks a paying customer.
    /// </summary>
    [JsonIgnore]
    public bool FeaturesEnabled
        => State != LicenceState.Refunded && State != LicenceState.Disabled;
}

/// <summary>Result of an activation attempt.</summary>
public sealed class ActivationResult
{
    public bool Success { get; init; }

    /// <summary>Error slug on failure, e.g. "limit_reached", "license_inactive".</summary>
    public string? Error { get; init; }

    public string? Message { get; init; }

    public LicenceStatus? Status { get; init; }
}

/// <summary>An available update, as returned by GET /update.</summary>
public sealed class UpdateInfo
{
    public required string NewVersion { get; init; }

    /// <summary>Signed, short-lived download URL for the package.</summary>
    public required string PackageUrl { get; init; }

    /// <summary>Expected SHA-256 of the package (verified before installing).</summary>
    public string? Sha256 { get; init; }

    /// <summary>Real package file name to save the download as (non-WP products).</summary>
    public string? FileName { get; init; }

    /// <summary>"windows", "other", or "wordpress".</summary>
    public string? Platform { get; init; }

    public string? ChangelogUrl { get; init; }
}
