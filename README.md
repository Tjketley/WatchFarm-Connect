# WatchFarm Connect

WatchFarm Connect is the optional WordPress companion for FarmWatch. It receives statistics pushed outbound by FarmWatch Core and stores them for a private WordPress dashboard.

## Architecture and security boundary

- FarmWatch Core contains the optional uploader, disabled by default.
- WatchFarm Connect is the WordPress receiver and storage layer.
- Communication is one-way: FarmWatch Core makes outbound HTTPS requests to WordPress.
- No inbound ASF ports, public ASF API, reverse tunnel, remote bot controls or commands are provided.
- Upload credentials are generated in WordPress. Only a SHA-256 token hash is stored; plaintext is shown once.
- The receiver stores the statistics object as JSON without interpreting or aggregating its fields. This keeps the transport contract independent from the future dashboard design.

## Upload API — contract version 1

**Endpoint:** `POST /wp-json/watchfarm-connect/v1/upload`

**Headers:**

```http
Content-Type: application/json
Authorization: Bearer YOUR_WORDFARM_CONNECT_TOKEN
```

**Request body:**

```json
{
  "schema_version": 1,
  "upload_id": "2b5f8d76-2dd3-4c72-8c24-03dbd8e0f3c1",
  "instance_id": "farmwatch-pi-01",
  "recorded_at": "2026-11-15T10:30:00Z",
  "statistics": {
    "example_counter": 42,
    "example_group": {
      "example_value": 12
    }
  },
  "claims": [
    {
      "claim_id": "stable-claim-record-id",
      "app_id": 123456,
      "title": "Example game title",
      "claimed_at": "2026-11-15T10:15:00Z"
    }
  ]
}
```

The `statistics` object is deliberately opaque at this stage. Its keys and meanings will be agreed with FarmWatch Core before the dashboard interprets them. Unknown fields are retained. The example values above are illustrative only.

### Field rules

| Field | Requirement | Meaning |
|---|---|---|
| `schema_version` | Required integer, currently `1` | Transport contract version |
| `upload_id` | Required UUID | Unique per upload attempt; reuse the same value for retries |
| `instance_id` | Required, 8–64 safe characters | Stable identifier for one FarmWatch installation |
| `recorded_at` | Required RFC3339 timestamp with timezone | Time the snapshot was taken |
| `statistics` | Required JSON object | FarmWatch statistics payload, stored without interpretation |
| `claims` | Optional array, maximum 500 entries | Claim-history records included with the snapshot |
| `claims[].claim_id` | Recommended stable identifier | Deduplicates claim records; a deterministic fallback is generated if omitted |
| `claims[].app_id` | Required positive Steam app ID | Claimed application |
| `claims[].title` | Optional string | Display title captured by FarmWatch |
| `claims[].claimed_at` | Required RFC3339 timestamp with timezone | Time the claim occurred |

The full request body is limited to 256 KB. Timestamps must be within the previous year and no more than five minutes in the future. The endpoint accepts 120 uploads per five minutes per authenticated instance.

### Response

Successful first upload:

```json
{
  "ok": true,
  "duplicate": false,
  "upload_id": "2b5f8d76-2dd3-4c72-8c24-03dbd8e0f3c1",
  "snapshot_id": 101,
  "claims_recorded": 1,
  "received_at": "2026-11-15T10:30:02+00:00"
}
```

If the same `instance_id` and `upload_id` are sent again, the receiver responds with `ok: true` and `duplicate: true`. FarmWatch Core should treat that as a successful acknowledgement and must reuse the original upload ID when retrying a timed-out request.

### HTTP status codes

- `200`: accepted, or already accepted duplicate.
- `400`: invalid JSON, unsupported schema, invalid fields or timestamps.
- `401`: missing or invalid Bearer token.
- `413`: request exceeds the payload limit.
- `429`: rate limit reached; retry later with the same upload ID.
- `500`: storage failure; retry later with the same upload ID.

## Storage

- `wp_wfc_snapshots`: one JSON statistics snapshot per unique instance/upload ID.
- `wp_wfc_claims`: normalized claim history with a unique per-instance claim key.
- Snapshot and claim writes are wrapped in a database transaction.
- Repeated upload IDs do not create duplicate snapshots; repeated claim IDs do not create duplicate claim rows.

## Current implementation

- WordPress plugin bootstrap and automatic database schema upgrade.
- Bearer-token authentication and administrator token rotation.
- Versioned REST upload endpoint with strict envelope validation.
- Body-size and upload-rate limits.
- Retry-safe snapshot storage and duplicate-safe claim history.
- Admin page documenting the endpoint and connection details.

## Deliberately not implemented yet

- Interpretation or aggregation of statistics fields.
- Dashboard cards, charts, filters, widgets or public-facing blocks.
- FarmWatch Core uploader implementation.
- Remote bot control or inbound ASF connectivity.

## Requirements and installation

Requires WordPress 6.4+ and PHP 8.0+. Install this directory as a plugin and activate it. Generate an upload token from **WatchFarm** in the WordPress admin. Configure the optional uploader in FarmWatch Core to send the version 1 envelope to the endpoint above over HTTPS.
