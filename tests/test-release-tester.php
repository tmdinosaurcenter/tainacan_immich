<?php
/**
 * Extra checks for bin/package.sh and bin/changelog-notes.sh: script headers and modes,
 * command hazards, and edge cases not covered by the other two suites. Fake repos in a
 * temp dir only. No network. Run: php tests/test-release-tester.php
 */

require __DIR__ . '/lib-shell-tests.php';

sh_require_tools( array( 'bash', 'node' ) );

$root = dirname( __DIR__ );
$pkg  = $root . '/bin/package.sh';
$cl   = $root . '/bin/changelog-notes.sh';

echo "\n== script headers and modes\n";
foreach ( array( $pkg, $cl ) as $s ) {
	$name  = basename( $s );
	$text  = file_get_contents( $s );
	$first = strtok( $text, "\n" );
	sh_check( "$name: shebang", '#!/usr/bin/env bash', $first );
	sh_check( "$name: set -eu", 1, preg_match( '/^set -eu$/m', $text ) );
	sh_check( "$name: set -o pipefail", 1, preg_match( '/^set -o pipefail$/m', $text ) );
	sh_check( "$name: export LC_ALL=C", 1, preg_match( '/^export LC_ALL=C$/m', $text ) );
	sh_check( "$name: mode 755", '755', substr( sprintf( '%o', fileperms( $s ) ), -3 ) );
}

echo "\n== command hazards in bin/package.sh\n";
$pkg_lines = explode( "\n", file_get_contents( $pkg ) );
sh_check( 'no git, curl, wget or npm command', array(), array_values( preg_grep( '/^\s*(git|curl|wget|npm)\s/', $pkg_lines ) ) );
$rm_lines = array_values( preg_grep( '/^\s*rm\s/', $pkg_lines ) );
sh_check( 'exactly one rm command', 1, count( $rm_lines ) );
sh_check( 'the one rm is the temp dir trap', array( "\t\trm -rf -- \"\$tmp\"" ), $rm_lines );

$env_base = array(
	'PATH'   => (string) getenv( 'PATH' ),
	'HOME'   => sys_get_temp_dir(),
	'LC_ALL' => 'C',
);

/** Build a fake repo; $overrides maps relative path => content, or null for "no file". */
function rt_fake_repo( $repo, array $overrides, $pkg_script ) {
	$files = array(
		'tainacan-immich/tainacan-immich.php' => "<?php\n/*\nPlugin Name: Fake\nVersion: 1.2.3\nStable tag: 1.2.3\n*/\n",
		'tainacan-immich/README.txt'          => "=== Fake ===\nStable tag: 1.2.3\n",
		'tainacan-immich/package.json'        => "{\n  \"version\": \"1.2.3\"\n}\n",
		'tainacan-immich/build/immich-link.js'      => "/* js */\n",
		'tainacan-immich/build/immich-link-form.js' => "/* js form */\n",
	);
	foreach ( $overrides as $rel => $content ) {
		if ( null === $content ) {
			unset( $files[ $rel ] );
		} else {
			$files[ $rel ] = $content;
		}
	}
	foreach ( $files as $rel => $content ) {
		sh_write( $repo . '/' . $rel, $content );
	}
	sh_write( $repo . '/bin/package.sh', $pkg_script );
	chmod( $repo . '/bin/package.sh', 0755 );
}

echo "\n== package.json with a non-string version\n";
$base = sh_mktemp( 'rtest' );
$repo = $base . '/repo';
$tmpd = $base . '/tmp';
mkdir( $tmpd, 0700 );
rt_fake_repo( $repo, array( 'tainacan-immich/package.json' => "{\n  \"version\": 123\n}\n" ), file_get_contents( $pkg ) );
$env = $env_base + array( 'TMPDIR' => $tmpd );
$r   = sh_run( array( 'bash', 'bin/package.sh' ), $repo, $env );
sh_check( 'numeric package.json version: exit 1', 1, $r[0] );
sh_check( 'numeric package.json version: message', 1, preg_match( "/package\.json: field 'version' missing or empty/", $r[2] ) );
sh_check( 'numeric package.json version: no zip written', false, file_exists( $repo . '/build/tainacan-immich.zip' ) );
sh_check( 'numeric package.json version: TMPDIR empty', array(), array_values( array_diff( scandir( $tmpd ), array( '.', '..' ) ) ) );

echo "\n== changelog block at end of file without a final newline\n";
$cbase = sh_mktemp( 'rtcl' );
sh_write( $cbase . '/nonl.txt', "= 0.8.1 =\n* Nine." );
$r = sh_run( array( 'bash', $cl, 'v0.8.1', $cbase . '/nonl.txt' ), $cbase, $env_base );
sh_check( 'no final newline: exit 0', 0, $r[0] );
sh_check( 'no final newline: output', "* Nine.\n", $r[1] );

sh_rmtree( $base, $base );
sh_rmtree( $cbase, $cbase );
sh_summary( 'release-tester' );
