# WatchFarm Connect

WatchFarm Connect is the optional WordPress receiver and private dashboard for FarmWatch running inside ArchiSteamFarm (ASF).

FarmWatch initiates every connection. This plugin exposes one authenticated HTTPS REST endpoint and does not connect back to, control, or expose your ASF instance.

## Install

1. Upload this plugin directory to `wp-content/plugins/watchfarm-connect/`.
2. Activate **WatchFarm Connect** in WordPress Admin → Plugins.
3. Open **WatchFarm** in the WordPress admin menu.
4. Select **Generate / rotate upload token** and copy the token immediately. It is shown once; WordPress stores only its SHA-256 hash.
5. Copy the upload endpoint shown on the page.
6. In FarmWatch's ASF UI, open **Safety & controls → WatchFarm Connect**, paste the endpoint and token, and enable the integration.

The endpoint normally looks like:

```
https://your-site.example/wp-json/watchfarm-connect/v1/upload
```

## Transport contract v1

The receiver accepts the exact FarmWatch v1 envelope:

- `schema_version`: integer `1`
- `upload_id`: UUID generated once per snapshot and reused on retries
- `instance_id`: stable 32-character random identifier for one FarmWatch installation
- `recorded_at`: UTC ISO-8601 timestamp
- `statistics`: aggregate FarmWatch counters and state, stored as JSON
- `claims`: up to 500 successful claim events containing `claim_id`, `target_type` (`App` or `Sub`), `target_id`, `claimed_at`, and `source`

The claim ledger is sent as the most recent 500 claim events. Claim IDs are stable and deduplicated independently of upload IDs.

## Delivery and deduplication

- The receiver acknowledges successful uploads with `{ "ok": true }`.
- Uploads are deduplicated by `instance_id + upload_id`.
- Claim events are deduplicated by `instance_id + claim_id`.
- Snapshot and claim writes are committed in one database transaction.
- The request body is limited to 256 KB and 500 claim events per upload.
- Authenticated instances are limited to 120 uploads per five minutes.
- Invalid payloads, authentication failures and storage errors return appropriate HTTP error codes. FarmWatch retains failed uploads and retries using the same upload UUID.

## Security and privacy

- Bearer-token authentication; only token hashes are stored.
- Token generation and rotation require the WordPress `manage_options` capability and a valid admin nonce.
- FarmWatch requires HTTPS before sending.
- No inbound connection to ASF is created.
- Statistics are aggregate counters and state. Claim events include Steam App/Package target IDs and timestamps, but no bot name.
- Steam credentials, Steam IDs, account names, bot names, filters and raw diagnostics are not uploaded.
- Rotating the token invalidates the previous token immediately.

## Dashboard and storage

The WatchFarm admin page shows:
- Connected FarmWatch instances and last report time
- FarmWatch version and bot online/total counts
- Aggregate claims and farming hours
- Global auto-claim state
- Recent claim history

The plugin creates two tables using the active WordPress table prefix:

- `wfc_snapshots`: one row per unique upload snapshot, including the statistics JSON payload
- `wfc_claims`: normalized, deduplicated successful claim events

Schema updates run automatically when the plugin is loaded. Deactivating the plugin does not delete stored data.

## Automated package

Every pull request targeting `main` builds and validates an installable ZIP named `watchfarm-connect.zip`, with the required lowercase `watchfarm-connect/` plugin directory. The ZIP is retained as a GitHub Actions artifact for seven days.

## Requirements

- WordPress 6.4+
- PHP 8.0+
- MySQL or MariaDB with InnoDB transaction support
