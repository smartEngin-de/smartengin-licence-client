using System;
using System.Collections.Generic;
using System.Net.Http;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;

namespace SmartEngin.Licence;

/// <summary>Thin HTTP layer over the sels/v1 REST endpoints.</summary>
internal sealed class SelsApi
{
    // One shared HttpClient for the whole app (recommended .NET practice).
    private static readonly HttpClient Http = new() { Timeout = TimeSpan.FromSeconds(20) };

    private readonly LicenceOptions _o;

    public SelsApi(LicenceOptions options) => _o = options;

    /// <summary>The shared client, for streaming the package download.</summary>
    public HttpClient Client => Http;

    public async Task<JsonDocument> PostAsync(
        string path,
        IEnumerable<KeyValuePair<string, string>> form,
        CancellationToken ct)
    {
        using var content = new FormUrlEncodedContent(form);
        using var resp = await Http.PostAsync(_o.ApiBase + path, content, ct).ConfigureAwait(false);
        var body = await resp.Content.ReadAsStringAsync(ct).ConfigureAwait(false);
        return JsonDocument.Parse(body);
    }

    public async Task<JsonDocument> GetAsync(string pathWithQuery, CancellationToken ct)
    {
        using var resp = await Http.GetAsync(_o.ApiBase + pathWithQuery, ct).ConfigureAwait(false);
        var body = await resp.Content.ReadAsStringAsync(ct).ConfigureAwait(false);
        return JsonDocument.Parse(body);
    }
}
