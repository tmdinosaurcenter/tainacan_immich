# Tainacan Immich

A WordPress plugin that links [Tainacan](https://tainacan.org/) items to photos, albums and people on an [Immich](https://immich.app/) server. It adds one metadata type, "Immich link". The plugin only reads from Immich. It never writes there.

## Features

- **Metadata type `Immich_Link`.** It shows as "Immich link" in Tainacan. Each metadatum has one kind:
  - **Asset** (many values allowed). Shows a thumbnail.
  - **Album** (one value). Shows an "Open in Immich" link.
  - **Person** (one value). Shows the face thumbnail and an "Open in Immich" link.
- **Flexible input.** Enter an Immich ID (UUID), the photo, album or person URL, or a share link (`/s/<slug>` or `/share/<key>`). The plugin checks the ID against Immich when the editor types it.
- **EXIF block in the item edit form.** For an asset, the editor sees the place (city, state, country), the date taken and an OpenStreetMap link. The block is read-only and visible to editors only.
- **Signed thumbnail proxy.** Thumbnails load through a REST route on your WordPress site. The route checks an HMAC signature. The API key never goes to the browser.
- **Settings page.** The admin sets the Immich URL and an API key. The key is stored write-only.
- **Update notices.** WordPress shows new releases from GitHub on the Plugins page. See Configuration for the controls.

## Requirements

- WordPress 6.5 or newer.
- PHP 7.1 or newer.
- Tainacan 1.4.1 or newer, active.
- An Immich server. The plugin is tested against the 3.3 API.
- An Immich API key with read scopes only. The plugin needs `asset.read`, `asset.view`, `album.read` and `person.read`.

## Installation

1. Download `tainacan-immich.zip` from the [latest release](https://github.com/tmdinosaurcenter/tainacan_immich/releases/latest).
2. In the WordPress admin, go to Plugins > Add New > Upload Plugin. Choose the zip, click Install Now, then Activate.
3. Open the settings page and enter the Immich URL and API key (see Configuration).

Without Tainacan, the plugin shows the admin notice "Tainacan Immich plugin requires Tainacan plugin to be installed and activated." and stays inactive.

Later updates arrive on the normal Plugins page.

## Configuration

### Settings page

Open the Tainacan admin menu and click "Immich" (`wp-admin/admin.php?page=tainacan_immich`). Only users with the `manage_options` capability can change the settings.

| Field | Option | Default | Notes |
|---|---|---|---|
| Immich server URL | `tainacan_immich_url` | empty | Base URL without `/api`, for example `https://immich.example.org`. Must be a full http or https URL. The plugin strips a trailing slash. An invalid URL shows an error and keeps the old value. |
| Immich API key | `tainacan_immich_api_key` | empty | Password field. It is always empty on load. The page says if a key is saved. An empty submit keeps the saved key. |
| Check for plugin updates | `tainacan_immich_update_check` | on | Checkbox. |

### Kind of Immich link

Each "Immich link" metadatum has the option "Kind of Immich link". The default is Asset.

| Kind | Values | Public output |
|---|---|---|
| Asset | Many | Thumbnail |
| Album | One | "Open in Immich" link (when the Immich URL is set) |
| Person | One | Face thumbnail and "Open in Immich" link |

On save, each value must be an ID of the chosen kind. An album or person metadatum accepts one value. The plugin refuses an ID that Immich reports as not found, and an asset that is in the Immich trash. Other Immich errors, such as a server that is down, do not block the save.

### Update check

The plugin asks the GitHub releases API for the latest release. It uses GET only and sends no site data. The result is cached for 12 hours. WordPress core downloads the release asset `tainacan-immich.zip` when the admin clicks Update. "View details" shows the release notes.

| Control | Effect |
|---|---|
| "Check for plugin updates" checkbox | Turns the check on or off. |
| `tainacan_immich_enable_update_check` filter | Gets the checkbox value as a bool. Return `false` to turn the check off. |
| `TAINACAN_IMMICH_UPDATE_REPO` constant | Repository in `owner/repo` form. Default: `tmdinosaurcenter/tainacan_immich`. An empty string or an invalid value turns the check off. |

Examples:

```php
// wp-config.php
define( 'TAINACAN_IMMICH_UPDATE_REPO', '' );

// theme or plugin code
add_filter( 'tainacan_immich_enable_update_check', '__return_false' );
```

## Usage

1. In Tainacan, open a collection > Metadata. Add a metadatum of type **Immich link**.
2. Set "Kind of Immich link" and save.
3. In an item, paste an Immich ID or URL:
   - Asset: `https://<immich>/photos/<UUID>`
   - Album: `https://<immich>/albums/<UUID>`
   - Person: `https://<immich>/people/<UUID>`
   - Share link to an album, for an album field: `https://<immich>/s/<slug>` or `https://<immich>/share/<key>`
   - Share link to one photo, for a photo field: the same two forms

Share links must use the Immich URL set in the plugin. A share link with a password cannot be used. A share link with two or more photos is refused: paste a link to one photo for each value. The plugin asks Immich which album or photo the link holds, then the field keeps only the UUID. The share key or slug is never saved. The plugin sends the request from your server, so a share link also needs the Immich URL and API key set in the plugin settings.

A bare UUID works too. The plugin stores it in lowercase. If a URL has more than one match, the last one wins. For example, `/albums/<A>/photos/<B>` gives asset `<B>`.

After the editor types, the field calls the check route. On success it shows the thumbnail, the label (asset file name, album name or person name) and replaces the pasted URL with the UUID. On failure it shows the error message.

For an asset, the field also shows the EXIF block: the coordinates with an "Open in OpenStreetMap" link (or a note that the photo has no GPS location), the place and the date taken with time zone.

The public page shows the thumbnail or link for the kind. It makes no call to Immich while it builds the HTML.

## Security and privacy

- The plugin is read-only toward Immich. It sends GET requests only: asset, album and person data, share link data (`GET /shared-links/me`, sent without the API key) and the two thumbnail routes. It never writes, moves or deletes.
- The API key is a WordPress option. It goes out only in the `x-api-key` header of server-side requests to Immich. The settings page never shows it again, and it never goes to the browser.
- Thumbnails go through the route `GET /wp-json/tainacan-immich/v1/thumbnail?kind=&id=&sig=`. The `kind` is `asset` or `person`. The `sig` is an HMAC-SHA256 of `kind|id` made with the WordPress `auth` salt. A bad signature gives 403. The response has `Cache-Control: public, max-age=86400` and `X-Content-Type-Options: nosniff`.
- The check route `GET /wp-json/tainacan-immich/v1/check` needs the `edit_posts` capability and the REST nonce.
- EXIF coordinates and place show only in the item edit form. Public pages show no coordinates.
- Requests to Immich use a 10 second timeout, follow no redirects and read at most 5 MB.
- The update check sends no site data.

## Develop

The plugin code is in `tainacan-immich/`. The JS is built with `@wordpress/scripts`.

```
./build.sh                     # npm install + npm run build
cd tainacan-immich && npm start   # watch mode
cd tainacan-immich && npm run lint
```

Tests use fakes and need no Immich or WordPress. Run them from the repo root:

```
for f in tests/test-*.php; do php "$f" || echo "FAIL $f"; done
for f in tests/*.js; do node "$f" || echo "FAIL $f"; done
```

CI (`.github/workflows/ci.yml`) runs lint, these tests, the build and `bin/package.sh` on pushes to `main` and on pull requests.

`docs/live-checks.md` lists the manual checks for a test WordPress site. `docs/releasing.md` describes how to publish a release.

The admin script URLs carry `?tainacan_immich_v=<Version>` from the main file header, so browser and CDN caches load the new files after an update. Bump the version for every release.

## Versions

The plugin uses [semantic versioning](https://semver.org/). See the changelog in `tainacan-immich/README.txt`.

## License

GNU General Public License version 3. See `LICENSE`.
