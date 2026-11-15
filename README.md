# WatchFarm Connect

WatchFarm Connect is the WordPress receiver and dashboard for FarmWatch running inside ArchiSteamFarm (ASF).

FarmWatch initiates every connection. This plugin exposes one authenticated HTTPS REST endpoint and does not connect to, control, or expose your ASF instance.

## Install

1. Copy this repository's `watchfarm-connect.php` into a directory named `watchfarm-connect` inside `wp-content/plugins/`.
2. Activate **WatchFarm Connect** in WordPress Admin → Plugins.
3. Open **WatchFarm** in the WordPress admin menu.
4. Select **Generate / rotate upload token** and copy the token immediately. It is shown once; only its SHA-256 hash is stored by WordPress.
5. Copy the upload endpoint displayed on the page.
6. In FarmWatch's ASF UI, open **Safety & controls → WatchFarm Connect**, paste the endpoint and token, and enable the integration.

The endpoint normally looks like:

```
https://your-site.example/wp-json/watchfarm-connect/v1/upload
```

## Transport contract

The receiver accepts schema version 1:

- `schema_version`: integer `1`
- `upload_id`: UUID for one immutable upload snapshot
- `instance_id`: stable 32-character instance identifier
- `recorded_at`: ISO-8601 timestamp
- `statistics`: aggregate FarmWatch counters and state
- `claims`: successful claim events containing `claim_id`, `target_type` (`App` or `Sub`), `target_id`, `claimed_at`, and `source`

Upload retries are idempotent by `instance_id + upload_id`. Claim events are independently deduplicated by `instance_id + claim_id`. The upload and its claim events are committed in one database transaction.

## Security and privacy

- Bearer-token authentication; token hashes are stored, not plaintext tokens.
- HTTPS is required by FarmWatch before it will send data.
- Token generation and rotation require the WordPress `manage_options` capability and an admin nonce.
- No inbound connection to ASF is created.
- The receiver stores aggregate statistics and Steam app/package target IDs for successful claim events. It does not receive Steam credentials, Steam IDs, account names, bot names, filters, or raw diagnostics.
- Rotate the token from the WatchFarm admin page if it is exposed. The previous token becomes invalid immediately.

## Data storage

The plugin creates two tables using the active WordPress table prefix:

- `watchfarm_uploads`: one row per unique snapshot, including aggregate statistics JSON.
- `watchfarm_claims`: deduplicated successful claim events.

The admin dashboard shows recent instance snapshots and recent claim events. Deactivating the plugin does not delete data; uninstall/data removal should be a deliberate administrator action.

## Requirements

- WordPress 6.2+
- PHP 8.0+
- MySQL or MariaDB with InnoDB transaction support
