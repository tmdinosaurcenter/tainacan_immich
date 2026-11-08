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

CI (`.github/workflows/ci.yml`) runs lint, these tests, the build and `bin/package.sh` on pushes to `main` and on pull requests.

`docs/live-checks.md` lists the manual checks for a test WordPress site.

The admin script URLs carry `?tainacan_immich_v=<Version>` from the main file header, so browser and CDN caches load the new files after an update. Bump the version for every release.

## Versions

The plugin uses [semantic versioning](https://semver.org/). See the changelog in `tainacan-immich/README.txt`.

## Releasing

1. Set the new version `X.Y.Z` (semantic versioning) in the four places: `Version:` and `Stable tag:` in `tainacan-immich/tainacan-immich.php`, `Stable tag:` in `tainacan-immich/README.txt`, `version` in `tainacan-immich/package.json`. Add a `= X.Y.Z =` entry with `* ` lines to `== Changelog ==` in README.txt. Sync `package-lock.json` (`npm install` in `tainacan-immich/`). Commit.
2. `git tag vX.Y.Z`, then push the branch and the tag to Gitea (`git push origin main vX.Y.Z`). The Gitea push mirror forwards both to GitHub.
3. On GitHub, the "Release" workflow job `build` checks the notes, runs lint, tests and the build, runs `bin/package.sh vX.Y.Z` and keeps the zip as an artifact. Then job `release` (the only job with write access, no npm) publishes the release with `tainacan-immich.zip`. If the release exists, it replaces the asset and the notes.
4. Dry run: start "Release" by hand (Actions tab, "Run workflow"). It does not publish. It keeps the zip as a workflow artifact (`tainacan-immich-zip`) for 7 days.

This needs:

- Actions enabled for the GitHub repo.
- Workflow permissions that allow `contents: write` for jobs that ask for it.
- A Gitea mirror that pushes tags, not only branches.

By hand (fallback):

```
cd tainacan-immich && npm ci && npm run build && cd ..
bin/package.sh vX.Y.Z
bin/changelog-notes.sh vX.Y.Z > /tmp/notes.md
gh release create vX.Y.Z build/tainacan-immich.zip --title vX.Y.Z --notes-file /tmp/notes.md
```

Rules:

- `bin/package.sh` is the only way to make the zip.
- When you update an action, update `.github/action-pins.txt` and both workflow files (`release.yml`, `ci.yml`) in the same commit. `tests/test-workflows.php` fails if they differ. The user creates and edits `.github/action-pins.txt` by hand (agents must not write it).
- The asset name must stay `tainacan-immich.zip`.
- The zip top folder must be `tainacan-immich/`.
- Tags are `vX.Y.Z`.
- The release notes become the "View details" changelog (plain lines and `* ` bullets).

## License

GNU General Public License version 3. See `LICENSE`.
