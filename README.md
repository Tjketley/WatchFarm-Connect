# WatchFarm Connect

WatchFarm Connect is the optional WordPress companion for FarmWatch. It receives statistics pushed outbound by FarmWatch Core and presents them in a private WordPress dashboard.

## Architecture

- FarmWatch Core contains the optional uploader, disabled by default.
- WatchFarm Connect is the WordPress receiver, storage layer and dashboard.
- Communication is one-way: FarmWatch Core makes outbound HTTPS requests to the WordPress REST endpoint.
- No inbound ASF ports, public ASF API, reverse tunnel, remote bot controls or commands are required.
- Upload credentials are generated in WordPress and stored as a SHA-256 hash. The plaintext token is shown once.

## Current foundation

- WordPress plugin bootstrap and activation.
- Dedicated database tables for statistics snapshots and claim history.
- Authenticated REST upload endpoint at `/wp-json/watchfarm-connect/v1/upload`.
- Administrator page for generating/rotating the upload token.
- Payload validation, size limits and duplicate-safe claim insertion.

## Planned dashboard

- Lifetime, daily and monthly statistics.
- Games claimed and claim history.
- Bot and title counts.
- Discovery and eligibility activity.
- Last successful upload and connection health.
- Admin dashboard widgets / blocks for selected statistics.

## Development

Requires WordPress 6.4+ and PHP 8.0+. Install this directory as a plugin and activate it. Generate an upload token from **WatchFarm** in the WordPress admin, then configure the optional uploader in FarmWatch Core.

The uploader is not implemented in this repository; it belongs in FarmWatch Core and remains disabled unless explicitly enabled.
