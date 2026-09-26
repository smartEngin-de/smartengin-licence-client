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

/// <summary>What the app should do with its premium features (0.2.0).</summary>
public enum LicenceMode
{
    /// <summary>Premium on: valid licence, an expired ONE-OFF purchase, or unknown (fail-open).</summary>
    Licensed,

    /// <summary>Premium still on: an expired SUBSCRIPTION within its grace days. Ask to renew.</summary>
    Grace,

    /// <summary>Premium off: refunded/disabled, or an expired subscription past its grace days.</summary>
    Locked,
}

/// <summary>A licence's current status, as last seen from the server or the cache.</summary>
public sealed class LicenceStatus
{
    /// <summary>True only when the licence is active and not past its end date.</summary>
    public bool Valid { get; init; }

    /// <summary>The status the server reported. Prefer <see cref="EffectiveState"/>.</summary>
    public LicenceState State { get; init; } = LicenceState.Unknown;

    /// <summary>End date in UTC, or null for a lifetime licence.</summary>
    public DateTime? ValidUntil { get; init; }

    /// <summary>Remaining activations, or null when unlimited.</summary>
    public int? ActivationsLeft { get; init; }

    /// <summary>
    /// True when the licence was sold as a subscription (server L&amp;b 1.6.43+;
    /// an older server does not send it, and the licence then never locks).
    /// </summary>
    public bool Subscription { get; init; }

    /// <summary>Days an expired subscription keeps its premium features (server: 3).</summary>
    public int GraceDays { get; init; }

    public DateTimeOffset CheckedAt { get; init; } = DateTimeOffset.UtcNow;

    /// <summary>True when this status came from the local cache (a network error).</summary>
    public bool FromCache { get; init; }

    /// <summary>
    /// The state as it IS right now: "active" whose end date has passed reads
    /// <see cref="LicenceState.Expired"/> — also offline, from the cached date.
    /// </summary>
    [JsonIgnore]
    public LicenceState EffectiveState
        => State == LicenceState.Active && ValidUntil.HasValue && AsUtc(ValidUntil.Value) <= DateTime.UtcNow
            ? LicenceState.Expired
            : State;

    /// <summary>
    /// When the premium features of an expired SUBSCRIPTION switch off (UTC), or
    /// null when they never do (one-off purchase, lifetime, no date).
    /// </summary>
    [JsonIgnore]
    public DateTime? LockTime
        => Subscription && ValidUntil.HasValue
            ? AsUtc(ValidUntil.Value).AddDays(Math.Max(0, GraceDays))
            : null;

    /// <summary>
    /// Licensed | Grace | Locked. Decided from this status alone, never with a
    /// server call: the lock happens on time even offline, and a server outage
    /// can never switch a paying customer off early (the date comes from the
    /// last good answer, and every renewal moves it forward).
    /// </summary>
    [JsonIgnore]
    public LicenceMode Mode
    {
        get
        {
            var state = EffectiveState;
            if (state == LicenceState.Refunded || state == LicenceState.Disabled)
            {
                return LicenceMode.Locked;
            }
            // Active, and Unknown too (never reached the server): fail-open.
            if (state != LicenceState.Expired || !Subscription)
            {
                return LicenceMode.Licensed;
            }
            var lockAt = LockTime;
            return lockAt.HasValue && DateTime.UtcNow < lockAt.Value ? LicenceMode.Grace : LicenceMode.Locked;
        }
    }

    /// <summary>
    /// THE check for premium features. Off for a refunded/disabled licence and
    /// for an expired subscription past its grace days. An expired one-off
    /// purchase keeps working (only updates stop); unknown or a cached value
    /// (server unreachable) keeps working too, so an outage never bricks a
    /// paying customer.
    /// </summary>
    [JsonIgnore]
    public bool FeaturesEnabled => Mode != LicenceMode.Locked;

    // Das Datum kommt vom Server in UTC. Aeltere Cache-Dateien (vor 0.2.0) tragen
    // es ohne Zeitzonen-Kennung - auch das ist UTC.
    private static DateTime AsUtc(DateTime d)
        => d.Kind == DateTimeKind.Unspecified ? DateTime.SpecifyKind(d, DateTimeKind.Utc) : d.ToUniversalTime();
}

/// <summary>Result of an activation attempt.</summary>
public sealed class ActivationResult
{
    public bool Success { get; init; }

    /// <summary>
    /// Error slug on failure, e.g. "limit_reached", "license_inactive",
    /// "license_expired" (an expired key cannot claim a NEW device; a device
    /// that already has it stays activated), or "network_error".
    /// <see cref="Message"/> carries the server's readable text.
    /// </summary>
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
