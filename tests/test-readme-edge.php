<?php
/**
 * Checks for the root README.md: heading order, protected text, the edge cases in the
 * README spec, and facts cross-checked against the plugin code. Plain PHP, no network.
 * Run: php tests/test-readme-edge.php
 */

$GLOBALS['re_passes'] = 0;
$GLOBALS['re_fails']  = 0;

function re_check( $label, $expected, $actual ) {
	if ( $expected === $actual ) {
		$GLOBALS['re_passes']++;
		echo "PASS  $label\n";
	} else {
		$GLOBALS['re_fails']++;
		echo "FAIL  $label: expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
	}
}

function re_has( $label, $needle, $haystack ) {
	re_check( $label, true, false !== strpos( $haystack, $needle ) );
}

function re_lacks( $label, $needle, $haystack ) {
	re_check( $label, true, false === strpos( $haystack, $needle ) );
}

function re_read( $rel ) {
	$path = dirname( __DIR__ ) . '/' . $rel;
	$text = @file_get_contents( $path );
	if ( false === $text ) {
		$GLOBALS['re_fails']++;
		echo "FAIL  cannot read $rel\n";
		return '';
	}
	return $text;
}

$md = re_read( 'README.md' );

echo "\n== Happy path: structure\n";
re_check( 'README is valid UTF-8', 1, preg_match( '//u', $md ) );
re_check( 'README has no CR characters', 0, substr_count( $md, "\r" ) );
re_check( 'first line is the title', "# Tainacan Immich\n", substr( $md, 0, strlen( "# Tainacan Immich\n" ) ) );
preg_match_all( '/^# (.+)$/m', $md, $h1 );
re_check( 'exactly one level-1 heading', array( 'Tainacan Immich' ), $h1[1] );
preg_match_all( '/^## (.+)$/m', $md, $h2 );
re_check(
	'level-2 headings in spec order',
	array( 'Features', 'Requirements', 'Installation', 'Configuration', 'Usage', 'Security and privacy', 'Develop', 'Versions', 'License' ),
	$h2[1]
);
re_check( 'no level-4 or deeper headings', 0, preg_match( '/^#{4,} /m', $md ) );
re_check( 'every level-2 heading has a blank line before it', 0, preg_match( '/(?<!\n)\n## /', $md ) );
re_check( 'no three blank lines in a row', 0, preg_match( '/\n\n\n/', $md ) );

$features_start = strpos( $md, "\n## Features\n" );
$features_end   = strpos( $md, "\n## Requirements\n" );
$features       = ( false === $features_start || false === $features_end ) ? '' : substr( $md, $features_start, $features_end - $features_start );
re_check( 'Features section located', true, '' !== $features );
re_check( 'Features has the protected update-notices bullet', 1, substr_count( $features, '- **Update notices.**' ) );
re_check( 'update-notices string appears exactly once in the file', 1, substr_count( $md, '**Update notices.**' ) );

echo "\n== Happy path: Installation and Configuration\n";
re_has( 'installation links the latest release (exact Markdown link)', '[latest release](https://github.com/tmdinosaurcenter/tainacan_immich/releases/latest)', $md );
re_has( 'installation names the zip', '`tainacan-immich.zip`', $md );
re_has( 'installation upload path', 'Plugins > Add New > Upload Plugin', $md );
re_has( 'installation no-Tainacan notice text', 'Tainacan Immich plugin requires Tainacan plugin to be installed and activated.', $md );
re_has( 'settings page URL', '`wp-admin/admin.php?page=tainacan_immich`', $md );
re_has( 'manage_options capability named', '`manage_options`', $md );
re_has( 'option row: URL', '`tainacan_immich_url`', $md );
re_has( 'option row: API key', '`tainacan_immich_api_key`', $md );
re_has( 'option row: update check', '`tainacan_immich_update_check`', $md );
re_has( 'update filter named', '`tainacan_immich_enable_update_check`', $md );
re_has( 'repo constant named', '`TAINACAN_IMMICH_UPDATE_REPO`', $md );
re_has( 'config PHP example: constant', "define( 'TAINACAN_IMMICH_UPDATE_REPO', '' );", $md );
re_has( 'config PHP example: filter', "add_filter( 'tainacan_immich_enable_update_check', '__return_false' );", $md );
re_has( 'kind option named', 'Kind of Immich link', $md );
re_has( 'kind option default is Asset', 'The default is Asset.', $md );

echo "\n== Happy path: Requirements and Usage facts\n";
re_has( 'WordPress 6.5 requirement', 'WordPress 6.5 or newer', $md );
re_has( 'PHP 7.1 requirement', 'PHP 7.1 or newer', $md );
re_has( 'Tainacan 1.4.1 requirement', 'Tainacan 1.4.1 or newer', $md );
re_has( 'Immich 3.3 API note', 'tested against the 3.3 API', $md );
re_has( 'four read scopes listed', '`asset.read`, `asset.view`, `album.read` and `person.read`', $md );
re_has( 'usage: metadata type name', '**Immich link**', $md );
re_has( 'usage: asset URL example', '`https://<immich>/photos/<UUID>`', $md );
re_has( 'usage: album URL example', '`https://<immich>/albums/<UUID>`', $md );
re_has( 'usage: person URL example', '`https://<immich>/people/<UUID>`', $md );
re_has( 'usage: lowercase storage', 'The plugin stores it in lowercase.', $md );
re_has( 'usage: last match wins example', '`/albums/<A>/photos/<B>` gives asset `<B>`', $md );
re_has( 'usage: EXIF block for asset', 'For an asset, the field also shows the EXIF block', $md );
re_has( 'usage: OpenStreetMap link', 'Open in OpenStreetMap', $md );
re_has( 'usage: no GPS note', 'no GPS location', $md );
re_has( 'rules: trash refusal', 'an asset that is in the Immich trash', $md );
re_has( 'rules: unknown ID refusal', 'an ID that Immich reports as not found', $md );
re_has( 'rules: other errors do not block', 'Other Immich errors, such as a server that is down, do not block the save.', $md );

echo "\n== Happy path: Security and update check facts\n";
re_has( 'GET-only statement', 'It sends GET requests only', $md );
re_has( 'x-api-key header named', '`x-api-key` header', $md );
re_has( 'thumbnail route path', '`GET /wp-json/tainacan-immich/v1/thumbnail?kind=&id=&sig=`', $md );
re_has( 'check route path and capability', '`GET /wp-json/tainacan-immich/v1/check` needs the `edit_posts` capability', $md );
re_has( 'HMAC description', 'HMAC-SHA256 of `kind|id` made with the WordPress `auth` salt', $md );
re_has( 'bad signature gives 403', 'A bad signature gives 403.', $md );
re_has( 'cache header', 'Cache-Control: public, max-age=86400', $md );
re_has( 'nosniff header', 'X-Content-Type-Options: nosniff', $md );
re_has( 'request limits: timeout', '10 second timeout', $md );
re_has( 'request limits: redirects', 'follow no redirects', $md );
re_has( 'request limits: 5 MB', 'read at most 5 MB', $md );
re_has( 'update cache 12 hours', 'cached for 12 hours', $md );
re_has( 'update check sends no site data', 'The update check sends no site data.', $md );

echo "\n== Edge 1: fences open and close at line start, no tilde fences\n";
$lines       = explode( "\n", $md );
$bad_fence   = 0;
$fence_count = 0;
$indented    = 0;
foreach ( $lines as $line ) {
	if ( false !== strpos( $line, '```' ) ) {
		$fence_count++;
		if ( ! preg_match( '/^```[A-Za-z]*$/', $line ) ) {
			$bad_fence++;
		}
	}
	if ( preg_match( '/^\s+```/', $line ) ) {
		$indented++;
	}
}
re_check( 'every ``` line is a bare fence (optional language)', 0, $bad_fence );
re_check( 'fence count is even (every block closes)', 0, $fence_count % 2 );
re_check( 'no indented fence', 0, $indented );
re_check( 'no tilde fence', 0, substr_count( $md, '~~~' ) );
re_check( 'fence-strip regex removes all blocks (no ``` left in prose)', 0, substr_count( preg_replace( '/^```.*?^```[^\n]*$/ms', '', $md ), '```' ) );

echo "\n== Edge 2 and 3: banned words, whole file, case-insensitive\n";
re_check( 'no "not yet", "not supported" or "removed" anywhere', 0, preg_match( '/not yet|not supported|removed/i', $md ) );
re_check( 'no "not yet" or "not supported" inside inline code or links', 0, preg_match( '/`[^`\n]*(not yet|not supported)[^`\n]*`|\[[^\]\n]*(not yet|not supported)[^\]\n]*\]/i', $md ) );
$rel = re_read( 'docs/releasing.md' );
re_check( 'releasing.md has no banned word', 0, preg_match( '/not yet|not supported|removed/i', $rel ) );

echo "\n== Edge 4: share links are documented, no old versions, no change history\n";
re_has( 'share link path /s/ is documented', '/s/<slug>', $md );
re_check( 'no 0.x.y version number', 0, preg_match( '/\b0\.\d+\.\d+\b/', $md ) );
re_check( 'no history words', 0, preg_match( '/\b(previous|earlier|older|used to|no longer|changed from|new in|since version|prior version|was added|now supports)\b/i', $md ) );

echo "\n== Edge 5: no invented features\n";
re_check( 'no "batch"', 0, preg_match( '/\bbatch\b/i', $md ) );
re_check( 'no "import"', 0, preg_match( '/\bimport(s|ed|ing)?\b/i', $md ) );
re_check( 'no "search" feature', 0, preg_match( '/\bsearch(es|ed|ing)?\b/i', $md ) );
re_check( 'no "sync with/from/to" feature', 0, preg_match( '/\bsync(s|ed|ing)?\s+(with|from|to)\b/i', $md ) );
re_check( 'no HTTP write verbs in prose', 0, preg_match( '/\b(POST|PUT|PATCH|DELETE)\b/', $md ) );
$public_exif_claim = 0;
foreach ( $lines as $line ) {
	if ( preg_match( '/public/i', $line ) && preg_match( '/EXIF|coordinates|GPS/i', $line ) && ! preg_match( '/\bno\b|\bnot\b|\bonly\b/i', $line ) ) {
		$public_exif_claim++;
	}
}
re_check( 'no line says public pages show EXIF or coordinates', 0, $public_exif_claim );

echo "\n== Edge 6 and 7: update-notices bullet, License ending\n";
$license_sentence = "GNU General Public License version 3. See `LICENSE`.";
re_check( 'License block: one blank line then exact sentence', 1, substr_count( $md, "\n## License\n\n" . $license_sentence ) );
re_check( 'file ends with the License sentence', $license_sentence, substr( rtrim( $md, "\n" ), -strlen( $license_sentence ) ) );
re_check( 'License is the last level-2 heading', 'License', end( $h2[1] ) );
re_check( 'README has no Releasing heading', false, strpos( $md, "\n## Releasing\n" ) );
re_check( 'Installation comes before Configuration', true, strpos( $md, "\n## Installation\n" ) < strpos( $md, "\n## Configuration\n" ) );

echo "\n== Edge 8: no real host, no key or token\n";
preg_match_all( '#https?://[^\s)`"<>]+#', $md, $urls );
$allowed_prefixes = array(
	'https://github.com/tmdinosaurcenter/tainacan_immich',
	'https://tainacan.org/',
	'https://immich.app/',
	'https://immich.example.org',
	'https://semver.org/',
);
$unknown_urls = array();
foreach ( array_unique( $urls[0] ) as $url ) {
	$ok = false;
	foreach ( $allowed_prefixes as $prefix ) {
		if ( 0 === strpos( $url, $prefix ) ) {
			$ok = true;
			break;
		}
	}
	if ( ! $ok ) {
		$unknown_urls[] = $url;
	}
}
re_check( 'every URL is a public or placeholder host', array(), $unknown_urls );
re_check( 'no private or local host names', 0, preg_match( '/localhost|\.local\b|192\.168\.|10\.\d+\.\d+\.\d+|127\.0\.0\.1/', $md ) );
re_check( 'no long hex run (key or token shape)', 0, preg_match( '/[0-9a-f]{32,}/i', $md ) );
re_check( 'no key value after the x-api-key name', 0, preg_match( '/x-api-key\s*[:=]\s*\S/i', $md ) );
re_check( 'no bearer or token value', 0, preg_match( '/bearer\s+\S|token\s*[:=]\s*\S/i', $md ) );

echo "\n== Edge 9: no emojis, plain text\n";
re_check( 'no emoji or pictograph code points', 0, preg_match( '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}]/u', $md ) );

echo "\n== Edge 10: Markdown tables have balanced pipes\n";
$tables     = array();
$current    = null;
foreach ( $lines as $line ) {
	if ( '|' === substr( $line, 0, 1 ) ) {
		if ( null === $current ) {
			$current = array();
		}
		$current[] = $line;
	} elseif ( null !== $current ) {
		$tables[]  = $current;
		$current   = null;
	}
}
if ( null !== $current ) {
	$tables[] = $current;
}
re_check( 'three tables in the README (settings, kind, update check)', 3, count( $tables ) );
$unbalanced = 0;
foreach ( $tables as $t ) {
	$cols = substr_count( $t[0], '|' );
	foreach ( $t as $row ) {
		if ( substr_count( $row, '|' ) !== $cols || '|' !== substr( rtrim( $row ), -1 ) ) {
			$unbalanced++;
		}
	}
}
re_check( 'every table row has the same pipe count as its header and ends with a pipe', 0, $unbalanced );

echo "\n== Protected text (copied from the old README)\n";
re_has( 'Develop build and lint block', "./build.sh                     # npm install + npm run build\ncd tainacan-immich && npm start   # watch mode\ncd tainacan-immich && npm run lint", $md );
re_has( 'Develop test block', "for f in tests/test-*.php; do php \"\$f\" || echo \"FAIL \$f\"; done\nfor f in tests/*.js; do node \"\$f\" || echo \"FAIL \$f\"; done", $md );
re_has( 'CI sentence', 'CI (`.github/workflows/ci.yml`) runs lint, these tests, the build and `bin/package.sh` on pushes to `main` and on pull requests.', $md );
re_has( 'live-checks line', '`docs/live-checks.md` lists the manual checks for a test WordPress site.', $md );
re_has( 'cache-version sentence', 'The admin script URLs carry `?tainacan_immich_v=<Version>` from the main file header, so browser and CDN caches load the new files after an update. Bump the version for every release.', $md );
re_has( 'Versions text', 'The plugin uses [semantic versioning](https://semver.org/). See the changelog in `tainacan-immich/README.txt`.', $md );
re_has( 'Releasing step 1 (four places)', 'Set the new version `X.Y.Z` (semantic versioning) in the four places:', $rel );
re_has( 'Releasing step 2 push command', '`git push origin main vX.Y.Z`', $rel );
re_has( 'Releasing step 2 Gitea mirror', 'The Gitea push mirror forwards both to GitHub.', $rel );
re_has( 'Releasing step 3 job build', 'job `build`', $rel );
re_has( 'Releasing step 3 job release', 'Then job `release`', $rel );
re_has( 'Releasing step 4 dry-run artifact', '`tainacan-immich-zip`', $rel );
re_has( 'Releasing step 4 retention', 'for 7 days', $rel );
re_has( 'Releasing rule: package.sh only', '`bin/package.sh` is the only way to make the zip.', $rel );
re_has( 'Releasing rule: action pins', '`.github/action-pins.txt`', $rel );
re_has( 'Releasing rule: asset name', 'The asset name must stay `tainacan-immich.zip`.', $rel );
re_has( 'Releasing rule: zip top folder', 'The zip top folder must be `tainacan-immich/`.', $rel );
re_has( 'Releasing rule: tags', 'Tags are `vX.Y.Z`.', $rel );
re_has( 'Releasing rule: notes format', 'The release notes become the "View details" changelog (plain lines and `* ` bullets).', $rel );

echo "\n== Cross-check: plugin code and README agree\n";
$main    = re_read( 'tainacan-immich/tainacan-immich.php' );
$client  = re_read( 'tainacan-immich/class-tainacan-immich-client.php' );
$settings = re_read( 'tainacan-immich/class-tainacan-immich.php' );
$rest    = re_read( 'tainacan-immich/class-tainacan-immich-rest.php' );
$updater = re_read( 'tainacan-immich/class-tainacan-immich-updater.php' );
$link    = re_read( 'tainacan-immich/metadata-types/class-tainacan-immich-link.php' );
$page    = re_read( 'tainacan-immich/page.php' );
$js      = re_read( 'tainacan-immich/src/immich-link.js' );
$formjs  = re_read( 'tainacan-immich/src/immich-link-form.js' );

re_has( 'code: asset.read scope', "'asset.read'", $client );
re_has( 'code: asset.view scope', "'asset.view'", $client );
re_has( 'code: album.read scope', "'album.read'", $client );
re_has( 'code: person.read scope', "'person.read'", $client );
re_has( 'code: x-api-key header', "'x-api-key'", $client );
re_check( 'code: 10 second timeout', 1, preg_match( "/'timeout'\s*=>\s*10\b/", $client ) );
re_check( 'code: redirections off', 1, preg_match( "/'redirection'\s*=>\s*0\b/", $client ) );
re_check( 'code: 5 MB response limit', 1, preg_match( "/'limit_response_size'\s*=>\s*5\s*\*\s*MB_IN_BYTES/", $client ) );
re_has( 'code: option URL name', "'tainacan_immich_url'", $settings );
re_has( 'code: option key name', "'tainacan_immich_api_key'", $settings );
re_has( 'code: option update-check name', "'tainacan_immich_update_check'", $settings );
re_check( 'code: update check default is on', 1, preg_match( "/OPTION_UPDATE_CHECK,\s*'1'\s*\)/", $updater ) );
re_has( 'code: update filter name', "'tainacan_immich_enable_update_check'", $updater );
re_has( 'code: repo constant name', "'TAINACAN_IMMICH_UPDATE_REPO'", $main );
re_has( 'code: repo constant default', "'tmdinosaurcenter/tainacan_immich'", $main );
re_has( 'code: GitHub latest release endpoint', "/releases/latest'", $updater );
re_check( 'code: 12 hour success cache', 1, preg_match( '/12 \* HOUR_IN_SECONDS/', $updater ) );
re_check( 'code: 15 minute failure cache', 1, preg_match( '/15 \* MINUTE_IN_SECONDS/', $updater ) );
re_has( 'code: Update URI header', 'Update URI: https://github.com/tmdinosaurcenter/tainacan_immich', $main );
re_has( 'code: Tainacan missing notice text', "'Tainacan Immich plugin requires Tainacan plugin to be installed and activated.'", $main );
re_has( 'code: Requires Plugins header', 'Requires Plugins: tainacan', $main );
re_has( 'code: Requires PHP header', 'Requires PHP: 7.1', $main );
re_has( 'code: Requires at least header', 'Requires at least: 6.5', $main );
re_has( 'code: settings capability message', 'You do not have permission to change the Immich settings.', $page );
re_has( 'code: manage_options check', "current_user_can( 'manage_options' )", $page );
re_has( 'code: metadata type name', 'Immich link', $link );
re_has( 'code: kind option label', 'Kind of Immich link', $link );
re_has( 'code: trash refusal text', 'This asset is in the Immich trash.', $link );
re_has( 'code: only-one refusal text', 'Only one Immich %s is allowed.', $link );
re_has( 'code: Open in Immich link on public page', "'Open in Immich'", $link );
re_has( 'code: check route needs edit_posts', "current_user_can( 'edit_posts' )", $rest );
re_has( 'code: thumbnail cache header', 'public, max-age=86400', $rest );
re_has( 'code: nosniff header', 'nosniff', $rest );
re_has( 'code: HMAC over kind|id with auth salt', "hash_hmac( 'sha256', \$kind . '|' . \$id, wp_salt( 'auth' ) )", $rest );
re_has( 'code: OpenStreetMap link text', 'Open in OpenStreetMap', $js );
re_has( 'code: no GPS text', 'This photo has no GPS location.', $js );
re_has( 'code: asset kind label', 'Asset (many allowed)', $formjs );
re_has( 'code: album kind label', 'Album (one)', $formjs );
re_has( 'code: person kind label', 'Person (one)', $formjs );

re_has( 'README: asset.read scope', '`asset.read`', $md );
re_has( 'README: 12 hours', '12 hours', $md );
re_has( 'README: GitHub releases API', 'GitHub releases API', $md );
re_has( 'code: route namespace is tainacan-immich/v1', "'tainacan-immich/v1'", $rest );
re_has( 'code: check route registered under tainacan-immich/v1', "'/check'", $rest );
re_has( 'code: thumbnail route registered under tainacan-immich/v1', "'/thumbnail'", $rest );
re_has( 'README: Tainacan 1.4.1 mention in README.txt', '1.4.1', re_read( 'tainacan-immich/README.txt' ) );

echo "\n== Summary: {$GLOBALS['re_passes']} passed, {$GLOBALS['re_fails']} failed\n";
exit( $GLOBALS['re_fails'] > 0 ? 1 : 0 );
