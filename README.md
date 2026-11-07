# Tainacan Immich

A WordPress plugin that links [Tainacan](https://tainacan.org/) items to photos, albums and people on an [Immich](https://immich.app/) server. The plugin only reads from Immich. It never changes anything there.

## Features

- **Settings page.** The admin sets the Immich URL and an API key. The key is never shown again after it is saved.
- **"Immich link" metadata type.** Add it to any Tainacan collection. Choose one kind per metadatum:
  - **Asset** (many allowed). Shows a thumbnail.
  - **Album** (one). Shows a link to the album in Immich.
  - **Person** (one). Shows the face thumbnail and a link.
- **EXIF block in the item edit form.** For an asset, the editor sees the place (city, state, country), the date taken and an OpenStreetMap link. The block is read-only. The plugin shows no coordinates on public pages.
- **Safe thumbnails.** Thumbnails go through a signed proxy on your WordPress site. The API key never goes to the browser.

## Requirements

- WordPress 6.5 or newer.
- PHP 7.1 or newer.
- Tainacan 1.4.1 or newer, active. Without Tainacan, the plugin shows an admin notice and stays inactive.
- An Immich server (tested against the 3.3 API) and an API key with read scopes only.

## Install

1. Download `tainacan-immich.zip` from the latest release.
2. In WordPress admin, go to Plugins > Add New > Upload Plugin. Pick the zip and activate it.
3. Open the plugin settings page. Enter the Immich URL and the API key. Save.
4. In Tainacan, open a collection > Metadata > add a metadatum of type **Immich link**. Set "Kind of Immich link". Save.
5. In an item, paste an Immich ID or URL:
   - Asset: `https://<immich>/photos/<UUID>`
   - Album: `https://<immich>/albums/<UUID>`
   - Person: `https://<immich>/people/<UUID>`

Share links (`/s/<name>`) are not supported yet. Use the normal photo, album or person address.

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

`docs/live-checks.md` lists the manual checks for a test WordPress site.

## Versions

The plugin uses [semantic versioning](https://semver.org/). See the changelog in `tainacan-immich/README.txt`.

## License

GNU General Public License. See `LICENSE`.
