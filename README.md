# Tainacan Immich

A WordPress plugin that links [Tainacan](https://tainacan.org/) items to photos, albums and people on an [Immich](https://immich.app/) server. The plugin only reads from Immich. It never changes anything there.

## Features

- **Settings page.** The admin sets the Immich URL and an API key. The key is never shown again after it is saved.
- **"Immich link" metadata type.** Add it to any Tainacan collection. Choose one kind per metadatum:
  - **Asset** (many allowed). Shows a thumbnail.
  - **Album** (one). Shows a link to the album in Immich.
  - **Person** (one). Shows the face thumbnail and a link.
- **EXIF block in the item edit form.** For an asset, the editor sees the place (city, state, country), the date taken and an OpenStreetMap link. The block is read-only. The plugin shows no coordinates on public pages.
- **Update notices.** WordPress shows new releases from GitHub on the Plugins page. Turn off with the "Check for plugin updates" setting, the `tainacan_immich_enable_update_check` filter, or `define( 'TAINACAN_IMMICH_UPDATE_REPO', '' );` in wp-config.php. The check sends no site data.
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

## Releasing

1. Set the new version `X.Y.Z` (semantic versioning) in the four places: `Version:` and `Stable tag:` in `tainacan-immich/tainacan-immich.php`, `Stable tag:` in `tainacan-immich/README.txt`, `version` in `tainacan-immich/package.json`. Add a `= X.Y.Z =` entry to `== Changelog ==` in README.txt. Commit.
2. `mkdir -p build && ./build.sh build` (makes a clean copy in `build/tainacan-immich/`; `/build` is in .gitignore).
3. `(cd build && rm -f tainacan-immich.zip && zip -r tainacan-immich.zip tainacan-immich)`
4. Check the top folder: `unzip -l build/tainacan-immich.zip | head` must show `tainacan-immich/tainacan-immich.php`.
5. `git tag vX.Y.Z` and push the branch and the tag to the GitHub remote.
6. `gh release create vX.Y.Z build/tainacan-immich.zip --title vX.Y.Z --notes "<changelog lines>"`. Do not mark it draft or pre-release when sites must get it.

Rules:

- The asset name must stay `tainacan-immich.zip`.
- The zip top folder must be `tainacan-immich/`.
- Tags are `vX.Y.Z`.
- The release notes become the "View details" changelog (plain lines and `* ` bullets).

## License

GNU General Public License. See `LICENSE`.
