# Live checks (human steps)

The automated tests use fakes. These steps prove the plugin in a real WordPress. Do them on a **test** site only.

## Rules

- Use a test WordPress site with Tainacan 1.4.1. Do not use a production site.
- Use a test Immich server, or a production server with a **new API key made only for this test**. Delete the key when you finish.
- Give the key the read scopes only: `asset.read`, `album.read`, `person.read`, `asset.view`. If a check fails with "forbidden", add the missing read scope and write it in the result.
- Never paste the key in a ticket, a chat, or a commit.
- Immich data must not change. Watch the Immich audit view after the checks.

## Setup

1. Run `./build.sh /path/to/wp-content/plugins/`.
2. In WordPress admin, activate **Tainacan Immich**.
3. Make a test collection. Add one metadatum of type **Immich link** for each kind: asset, album, person.
4. Pick test IDs from Immich: one asset with GPS, one asset without GPS, one asset with a blank or odd date, one album, one person with a face thumbnail.

## Checks

Mark each line PASS or FAIL. Add the exact error text for any FAIL.

### A. Boot and settings

- [ ] Deactivate Tainacan. Reload admin. An error notice says the plugin needs Tainacan. No PHP error shows.
- [ ] Activate Tainacan again. The notice goes away.
- [ ] Open the plugin settings page. Save a URL with no key. The URL saves.
- [ ] Save the test key. Reload. The key field is empty (the key is never shown).
- [ ] Save again with the key field empty. Test connection still works (the stored key stays).
- [ ] Enter a URL that is not valid. The form shows an error.

### B. Test connection

- [ ] Right URL and key: success message.
- [ ] Wrong key: message for 401 (key not accepted).
- [ ] Key without the needed scope: message for 403 (not allowed). Write the exact message here: ______
- [ ] Wrong host or closed port: message for no connection, within about 10 seconds.
- [ ] Log out and call the `check` REST route with no nonce. It is refused.

### C. Link metadata (asset, album, person)

- [ ] Item edit form shows the **Immich link** field with a text box for the ID.
- [ ] Enter a good asset ID. The thumbnail and file name show.
- [ ] Enter a good album ID. The album name and count show.
- [ ] Enter a good person ID. The name and face thumbnail show.
- [ ] Enter an ID that does not exist: message for 404.
- [ ] Enter text that is not an ID: message for bad input. No request goes to Immich (check the Immich log).
- [ ] Save the item. Reload. The ID is still there.
- [ ] Public item page: the link shows as expected and shows no API key in the page source or in the thumbnail URL.
- [ ] Thumbnail URL has a signature. Change one character of the signature: the proxy refuses it.

### C2. Share links

Use TEST share links made only for this test. Delete them in Immich when done.

- [ ] Album field: paste a `/s/<test-slug>` album link. The album name shows. The field holds a UUID, not the link. Save, reload: the UUID stays.
- [ ] Asset field: paste a `/share/<test-key>` link with one photo. The thumbnail shows. The field holds a UUID.
- [ ] Asset field: paste a link with 2 photos. The message says "has 2 photos".
- [ ] Asset field: paste an album link. The message says to use an album field.
- [ ] Album field: paste a link with single photos. The message says to use a photo field.
- [ ] Link with a password: the message says the link has a password or cannot be used.
- [ ] Expired or deleted link: the message says the link cannot be used.
- [ ] Paste a share link and save at once, before the check ends. The save is refused with "A share link cannot be saved".
- [ ] Page source and saved data hold no share key or slug.
- [ ] The Immich log shows only GET requests for the share check.
- [ ] Write the Immich version used.

### D. EXIF block (item edit form only)

- [ ] Asset with GPS: shows place (city, state, country), date taken, and an OpenStreetMap link.
- [ ] Open the OpenStreetMap link. The map pin is at the right place.
- [ ] Coordinates show at most 10 decimals, with no trailing zeros and no `-0`.
- [ ] Asset without GPS: no coordinates and no map link. No error.
- [ ] Asset with blank or spaces-only date: no "Date taken:" line.
- [ ] Asset at latitude 0 and longitude 0: the null-island note shows.
- [ ] Set the WordPress site language to one that uses a decimal comma (for example German). Repeat the GPS check. Coordinates still use a decimal point and the map link is correct. (This closes the comma-decimal gap that the automated test cannot run.)
- [ ] Public item page: no coordinates and no EXIF show anywhere.

### E. Read-only proof

- [ ] After all checks, the Immich audit or activity view shows no new asset, album, or person change.
- [ ] In the web server log of Immich, all requests from the plugin are `GET`.

### F. PHP version

- [ ] If the site runs PHP 7.1 or 7.2, activate the plugin and open the settings page. No parse error. (Tests ran on PHP 8.3 only.)
- [ ] Repeat on the newest PHP that the site supports.

### G. Clean up

- [ ] Delete the test API key in Immich.
- [ ] Delete the test collection if you do not need it.

## Report

Send the list with PASS or FAIL for each line, the PHP version, the WordPress version, the Tainacan version, and the Immich version. A FAIL becomes a new `/ship` concept.

## Known gaps these checks close

- Vue components were never rendered in a browser (section C and D).
- No live Immich or Tainacan call was ever made (sections B to E).
- Immich 3.3.6 behavior for a key with a missing scope is unverified (section B).
- Immich 3.3.6 share-link answers (`GET /shared-links/me` for password, expired and bad links) are unverified (section C2).
- Comma-decimal locale runtime was not run (section D).
- PHP 7.1 runtime was not run (section F).
