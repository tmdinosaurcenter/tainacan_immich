<?php
/**
 * Tests for bin/package.sh, run only inside a temp fake repo (never in the real repo root).
 * Plain PHP CLI, no network. Run: php tests/test-package-sh.php
 */

require __DIR__ . '/lib-shell-tests.php';

sh_require_tools( array( 'bash', 'zip', 'unzip', 'node', 'sha256sum' ) );

$base = sh_mktemp( 'pkgtest' );
$home = $base . '/home';
mkdir( $home );

function pk_env( $tmpdir, $home ) {
	return array(
		'PATH'   => (string) getenv( 'PATH' ),
		'HOME'   => $home,
		'TMPDIR' => $tmpdir,
		'LC_ALL' => 'C',
	);
}

/** Env for unzip and zip helper calls (no test data in the command). */
function pk_plain_env() {
	return array(
		'PATH'   => (string) getenv( 'PATH' ),
		'HOME'   => sys_get_temp_dir(),
		'TMPDIR' => sys_get_temp_dir(),
		'LC_ALL' => 'C',
	);
}

/** Build a fake repo. $o may override versions and extras. A null value removes that line. */
function pk_make_repo( $dir, array $o = array() ) {
	$v = array_merge(
		array(
			'main'   => '1.2.3',
			'stable' => '1.2.3',
			'readme' => '1.2.3',
			'pkg'    => '1.2.3',
			'eol'    => "\n",
		),
		$o
	);
	$e = $v['eol'];
	mkdir( $dir . '/bin', 0755, true );
	copy( dirname( __DIR__ ) . '/bin/package.sh', $dir . '/bin/package.sh' );
	chmod( $dir . '/bin/package.sh', 0755 );
	$p    = $dir . '/tainacan-immich';
	$main = '<?php' . $e . '/*' . $e . 'Plugin Name: Fake' . $e;
	if ( null !== $v['main'] ) {
		$main .= 'Version: ' . $v['main'] . $e;
	}
	$main .= ( null === $v['stable'] ? '' : 'Stable tag: ' . $v['stable'] . $e ) . '*/' . $e;
	sh_write( $p . '/tainacan-immich.php', $main );
	sh_write( $p . '/README.txt', '=== Fake ===' . $e . ( null === $v['readme'] ? '' : 'Stable tag: ' . $v['readme'] . $e ) . $e );
	if ( isset( $o['pkg_raw'] ) ) {
		sh_write( $p . '/package.json', $o['pkg_raw'] );
	} else {
		sh_write( $p . '/package.json', null === $v['pkg'] ? "{\"name\":\"x\"}\n" : "{\"name\":\"x\",\"version\":\"" . $v['pkg'] . "\"}\n" );
	}
	sh_write( $p . '/package-lock.json', '{}' );
	sh_write( $p . '/page.php', '<?php // page' );
	sh_write( $p . '/src/index.js', 'src' );
	sh_write( $p . '/src.txt', 'keep me' );
	sh_write( $p . '/node_modules/x/index.js', 'nm' );
	sh_write( $p . '/metadata-types/build/src/keep.txt', 'keep' );
	if ( empty( $o['no_js'] ) ) {
		sh_write( $p . '/build/immich-link.js', empty( $o['empty_js'] ) ? 'js' : '' );
	}
	sh_write( $p . '/build/immich-link-form.js', empty( $o['empty_form'] ) ? 'js' : '' );
	sh_write( $dir . '/other.txt', 'outside' );
}

/** Entry list of a zip, via unzip -Z1 (array command, no shell). */
function pk_zip_list( $zip ) {
	$res = sh_run( array( 'unzip', '-Z1', $zip ), dirname( $zip ), pk_plain_env() );
	return array_values( array_filter( explode( "\n", $res[1] ), 'strlen' ) );
}

/** One member of a zip, via unzip -p (array command, no shell). */
function pk_zip_text( $zip, $member ) {
	$res = sh_run( array( 'unzip', '-p', $zip, $member ), dirname( $zip ), pk_plain_env() );
	return $res[1];
}

function pk_case( $name, array $o, array $args, $base, $home, $expect_code, &$result ) {
	$repo = $base . '/repo-' . bin2hex( random_bytes( 4 ) );
	$tmpd = $base . '/tmp-' . bin2hex( random_bytes( 4 ) );
	mkdir( $tmpd );
	pk_make_repo( $repo, $o );
	$before = sh_snapshot( $repo, 'build' );
	$cmd    = array_merge( array( 'bash', 'bin/package.sh' ), $args );
	$res    = sh_run( $cmd, $repo, pk_env( $tmpd, $home ) );
	$after  = sh_snapshot( $repo, 'build' );
	sh_check( "$name: exit code", $expect_code, $res[0] );
	sh_check( "$name: TMPDIR empty after run", array(), array_values( array_diff( scandir( $tmpd ), array( '.', '..' ) ) ) );
	sh_check( "$name: no file outside build/ changed", $before, $after );
	$result = array( $res, $repo, $tmpd );
}

echo "== Good case\n";
pk_case( 'good', array(), array( 'v1.2.3' ), $base, $home, 0, $r );
list( $res, $good_repo ) = $r;
$zip   = $good_repo . '/build/tainacan-immich.zip';
$lines = explode( "\n", trim( $res[1] ) );
sh_check( 'good: 4 output lines', 4, count( $lines ) );
sh_check( 'good: stderr empty', '', $res[2] );
sh_check( 'good: version line', 'package.sh: version 1.2.3', $lines[0] );
sh_check( 'good: sha256 line format', 1, preg_match( '/^package\.sh: sha256 [0-9a-f]{64}$/', $lines[2] ) );
sh_check( 'good: sha256 equals the zip file', 'package.sh: sha256 ' . hash_file( 'sha256', $zip ), $lines[2] );
sh_check( 'good: wrote line', 'package.sh: wrote build/tainacan-immich.zip', $lines[3] );
sh_check( 'good: zip exists', true, is_file( $zip ) );
$list = pk_zip_list( $zip );
$bad  = 0;
foreach ( $list as $n ) {
	if ( 0 !== strpos( $n, 'tainacan-immich/' ) ) {
		$bad++;
	}
}
sh_check( 'good: single top folder', 0, $bad );
sh_check( 'good: main file in zip', true, in_array( 'tainacan-immich/tainacan-immich.php', $list, true ) );
sh_check( 'good: build js in zip', true, in_array( 'tainacan-immich/build/immich-link.js', $list, true ) );
sh_check( 'good: entries count matches line', 'package.sh: entries ' . count( $list ), $lines[1] );
$excluded = preg_grep( '#^tainacan-immich/(node_modules|src)(/|$)|^tainacan-immich/package(-lock)?\.json$#', $list );
sh_check( 'good: no excluded entries', array(), array_values( $excluded ) );
sh_check( 'good: src.txt kept', true, in_array( 'tainacan-immich/src.txt', $list, true ) );
sh_check( 'good: nested build/src dir kept', true, in_array( 'tainacan-immich/metadata-types/build/src/keep.txt', $list, true ) );
$sorted = $list;
sort( $sorted, SORT_STRING );
sh_check( 'good: entries sorted', $sorted, $list );
sh_check( 'good: Version inside zip', 1, preg_match( '/^Version: 1\.2\.3$/m', pk_zip_text( $zip, 'tainacan-immich/tainacan-immich.php' ) ) );

echo "\n== Second run, old zip with a stale extra file, no tag\n";
$stale_root = $base . '/stale-root';
sh_write( $stale_root . '/tainacan-immich/stale-extra.txt', 'old' );
$zr = sh_run( array( 'zip', '-q', '-r', $base . '/stale.zip', 'tainacan-immich' ), $stale_root, pk_plain_env() );
sh_check( 'stale zip made', 0, $zr[0] );
rename( $base . '/stale.zip', $zip );
sh_check( 'stale zip has the stale entry before the run', true, in_array( 'tainacan-immich/stale-extra.txt', pk_zip_list( $zip ), true ) );
$tmpd = $base . '/tmp-second';
mkdir( $tmpd );
$res2 = sh_run( array( 'bash', 'bin/package.sh' ), $good_repo, pk_env( $tmpd, $home ) );
sh_check( 'second run: exit 0', 0, $res2[0] );
sh_check( 'second run: same entry list', $list, pk_zip_list( $zip ) );
sh_check( 'second run: no stale entry', array(), array_values( preg_grep( '#stale#', pk_zip_list( $zip ) ) ) );
sh_check( 'second run: entries line', 'package.sh: entries ' . count( $list ), explode( "\n", trim( $res2[1] ) )[1] );
sh_check( 'second run: TMPDIR empty', array(), array_values( array_diff( scandir( $tmpd ), array( '.', '..' ) ) ) );

echo "\n== Version places differ: each place alone\n";
$mm = array(
	'Version'           => array( array( 'main' => '1.2.4' ), 'Version=1.2.4' ),
	'main Stable tag'   => array( array( 'stable' => '1.2.4' ), 'main-stable-tag=1.2.4' ),
	'README Stable tag' => array( array( 'readme' => '1.2.4' ), 'readme-stable-tag=1.2.4' ),
	'package.json'      => array( array( 'pkg' => '1.2.4' ), 'package.json=1.2.4' ),
);
foreach ( $mm as $label => $case ) {
	pk_case( 'mismatch ' . $label, $case[0], array(), $base, $home, 1, $r );
	sh_check( "mismatch $label: message lists all four", 1, preg_match( '/version mismatch: Version=\S+ main-stable-tag=\S+ readme-stable-tag=\S+ package\.json=\S+/', $r[0][2] ) );
	sh_check( "mismatch $label: message shows the changed value", 1, substr_count( $r[0][2], $case[1] ) );
}

echo "\n== Missing, empty or bad values\n";
$field_main = "/tainacan-immich\.php: field 'Version:' missing or empty/";
$bad_vals   = array(
	'missing Version line'         => array( array( 'main' => null ), "/tainacan-immich\.php: field 'Version:' missing or empty/" ),
	'empty Version'                => array( array( 'main' => '' ), "/tainacan-immich\.php: field 'Version:' missing or empty/" ),
	'missing Stable tag in main'   => array( array( 'stable' => null ), "/tainacan-immich\.php: field 'Stable tag:' missing or empty/" ),
	'empty Stable tag in main'     => array( array( 'stable' => '' ), "/tainacan-immich\.php: field 'Stable tag:' missing or empty/" ),
	'missing Stable tag in README' => array( array( 'readme' => null ), "/README\.txt: field 'Stable tag:' missing or empty/" ),
	'empty Stable tag in README'   => array( array( 'readme' => '' ), "/README\.txt: field 'Stable tag:' missing or empty/" ),
	'package.json without version' => array( array( 'pkg' => null ), "/package\.json: field 'version' missing or empty/" ),
	'package.json empty version'   => array( array( 'pkg' => '' ), "/package\.json: field 'version' missing or empty/" ),
	'package.json invalid JSON'    => array( array( 'pkg_raw' => '{not json' ), '/package\.json: cannot parse JSON/' ),
	'package.json is an array'     => array( array( 'pkg_raw' => '[]' ), "/package\.json: field 'version' missing or empty/" ),
	'Version 1.2 (not X.Y.Z)'      => array( array( 'main' => '1.2', 'stable' => '1.2', 'readme' => '1.2', 'pkg' => '1.2' ), "/'Version:' is not X\.Y\.Z \(1\.2\)/" ),
	'Version with suffix'          => array( array( 'main' => '1.2.3-rc1', 'stable' => '1.2.3-rc1', 'readme' => '1.2.3-rc1', 'pkg' => '1.2.3-rc1' ), "/'Version:' is not X\.Y\.Z/" ),
);
foreach ( $bad_vals as $label => $case ) {
	pk_case( $label, $case[0], array(), $base, $home, 1, $r );
	sh_check( "$label: message names file and field", 1, preg_match( $case[1], $r[0][2] ) );
}

echo "\n== Tag checks\n";
pk_case( 'tag mismatch', array(), array( 'v1.2.4' ), $base, $home, 1, $r );
sh_check( 'tag mismatch: message', 'package.sh: error: tag v1.2.4 does not match version 1.2.3', trim( $r[0][2] ) );
foreach ( array( '1.2.3', 'v1.2', 'v1.2.3-rc1', 'v1.2.3x', '', 'V1.2.3' ) as $bad_tag ) {
	pk_case( 'bad tag "' . $bad_tag . '"', array(), array( $bad_tag ), $base, $home, 1, $r );
	sh_check( 'bad tag "' . $bad_tag . '": message', 1, preg_match( '/^package\.sh: error: bad tag/', $r[0][2] ) );
}
pk_case( 'two arguments', array(), array( 'v1.2.3', 'v1.2.3' ), $base, $home, 1, $r );
sh_check( 'two arguments: usage on stderr', 1, preg_match( '/^usage: bin\/package\.sh/', $r[0][2] ) );

echo "\n== Build files\n";
pk_case( 'missing immich-link.js', array( 'no_js' => true ), array( 'v1.2.3' ), $base, $home, 1, $r );
sh_check( 'missing immich-link.js: message', 1, preg_match( '/immich-link\.js missing or empty, run npm run build/', $r[0][2] ) );
pk_case( 'empty immich-link.js', array( 'empty_js' => true ), array( 'v1.2.3' ), $base, $home, 1, $r );
sh_check( 'empty immich-link.js: message', 1, preg_match( '/immich-link\.js missing or empty/', $r[0][2] ) );
pk_case( 'empty immich-link-form.js', array( 'empty_form' => true ), array( 'v1.2.3' ), $base, $home, 1, $r );
sh_check( 'empty immich-link-form.js: message', 1, preg_match( '/immich-link-form\.js missing or empty/', $r[0][2] ) );

echo "\n== CRLF line endings\n";
pk_case( 'CRLF headers', array( 'eol' => "\r\n" ), array( 'v1.2.3' ), $base, $home, 0, $r );
sh_check( 'CRLF headers: version read correctly', 'package.sh: version 1.2.3', explode( "\n", trim( $r[0][1] ) )[0] );
sh_check( 'CRLF headers: Version inside zip is the CRLF line', 1, preg_match( '/^Version: 1\.2\.3\r?$/m', pk_zip_text( $r[1] . '/build/tainacan-immich.zip', 'tainacan-immich/tainacan-immich.php' ) ) );

echo "\n== Existing zip stays on failure\n";
$keep_cases = array(
	'no immich-link.js' => array( 'no_js' => true ),
	'empty immich-link.js' => array( 'empty_js' => true ),
	'empty immich-link-form.js' => array( 'empty_form' => true ),
	'Version mismatch' => array( 'main' => '1.2.4' ),
);
foreach ( $keep_cases as $label => $o ) {
	$repo   = $base . '/repo-keep-' . bin2hex( random_bytes( 3 ) );
	$tmpd   = $base . '/tmp-keep-' . bin2hex( random_bytes( 3 ) );
	mkdir( $tmpd );
	pk_make_repo( $repo, $o );
	sh_write( $repo . '/build/tainacan-immich.zip', 'OLD-ZIP-BYTES' );
	$before = sh_snapshot( $repo, 'build' );
	$res    = sh_run( array( 'bash', 'bin/package.sh' ), $repo, pk_env( $tmpd, $home ) );
	sh_check( "failure ($label): exit 1", 1, $res[0] );
	sh_check( "failure ($label): old zip byte-identical", 'OLD-ZIP-BYTES', file_get_contents( $repo . '/build/tainacan-immich.zip' ) );
	sh_check( "failure ($label): nothing outside build changed", $before, sh_snapshot( $repo, 'build' ) );
	sh_check( "failure ($label): TMPDIR empty", array(), array_values( array_diff( scandir( $tmpd ), array( '.', '..' ) ) ) );
}

echo "\n== Tool failure keeps exit code 1 (spec: no other exit code)\n";
$shim_dir = $base . '/shim-zip';
mkdir( $shim_dir );
sh_write( $shim_dir . '/zip', "#!/bin/sh\nexit 12\n" );
chmod( $shim_dir . '/zip', 0755 );
$repo = $base . '/repo-zipfail';
$tmpd = $base . '/tmp-zipfail';
mkdir( $tmpd );
pk_make_repo( $repo );
sh_write( $repo . '/build/tainacan-immich.zip', 'OLD-ZIP-BYTES' );
$env_shim          = pk_env( $tmpd, $home );
$env_shim['PATH']  = $shim_dir . ':' . (string) getenv( 'PATH' );
$res = sh_run( array( 'bash', 'bin/package.sh' ), $repo, $env_shim );
sh_check( 'zip fails with 12: script exits 1', 1, $res[0] );
sh_check( 'zip fails with 12: old zip byte-identical', 'OLD-ZIP-BYTES', file_get_contents( $repo . '/build/tainacan-immich.zip' ) );
sh_check( 'zip fails with 12: TMPDIR empty', array(), array_values( array_diff( scandir( $tmpd ), array( '.', '..' ) ) ) );

echo "\n== Other failing commands keep exit code 1 (ERR safety net)\n";
// Each shim fails on its first call. cp, mktemp and mv fail before the zip is published.
// sha256sum runs after the publish step, so the new zip stays in place (spec step 11 comes first).
$other_fail = array(
	'cp'        => array( 5, true ),
	'mktemp'    => array( 5, true ),
	'mv'        => array( 9, true ),
	'sha256sum' => array( 3, false ),
);
foreach ( $other_fail as $tool => $spec_fail ) {
	$code      = $spec_fail[0];
	$keep_old  = $spec_fail[1];
	$shim_dir  = $base . '/shim-' . $tool;
	mkdir( $shim_dir );
	sh_write( $shim_dir . '/' . $tool, "#!/bin/sh\nexit $code\n" );
	chmod( $shim_dir . '/' . $tool, 0755 );
	$repo = $base . '/repo-fail-' . $tool;
	$tmpd = $base . '/tmp-fail-' . $tool;
	mkdir( $tmpd );
	pk_make_repo( $repo );
	sh_write( $repo . '/build/tainacan-immich.zip', 'OLD-ZIP-BYTES' );
	$env_shim         = pk_env( $tmpd, $home );
	$env_shim['PATH'] = $shim_dir . ':' . (string) getenv( 'PATH' );
	$res              = sh_run( array( 'bash', 'bin/package.sh' ), $repo, $env_shim );
	sh_check( "$tool fails with $code: script exits 1", 1, $res[0] );
	if ( $keep_old ) {
		sh_check( "$tool fails with $code: old zip byte-identical", 'OLD-ZIP-BYTES', file_get_contents( $repo . '/build/tainacan-immich.zip' ) );
	}
	sh_check( "$tool fails with $code: TMPDIR empty", array(), array_values( array_diff( scandir( $tmpd ), array( '.', '..' ) ) ) );
}

echo "\n== build is a regular file\n";
$repo = $base . '/repo-buildfile';
$tmpd = $base . '/tmp-buildfile';
mkdir( $tmpd );
pk_make_repo( $repo );
sh_write( $repo . '/build', 'i am a file' );
$res = sh_run( array( 'bash', 'bin/package.sh' ), $repo, pk_env( $tmpd, $home ) );
sh_check( 'build is a file: exit 1', 1, $res[0] );
sh_check( 'build is a file: message', 1, preg_match( '/build exists and is not a directory/', $res[2] ) );
sh_check( 'build is a file: file kept', 'i am a file', file_get_contents( $repo . '/build' ) );

echo "\n== Wrong directory\n";
$repo = $base . '/repo-cwd';
$tmpd = $base . '/tmp-cwd';
mkdir( $tmpd );
pk_make_repo( $repo );
$before = sh_snapshot( $repo );
$res    = sh_run( array( 'bash', 'package.sh' ), $repo . '/bin', pk_env( $tmpd, $home ) );
sh_check( 'run from bin/: exit 1', 1, $res[0] );
sh_check( 'run from bin/: message', 1, preg_match( '/run from the repo root/', $res[2] ) );
sh_check( 'run from bin/: nothing written', false, file_exists( $repo . '/bin/build' ) );
sh_check( 'run from bin/: repo unchanged', $before, sh_snapshot( $repo ) );
sh_check( 'run from bin/: TMPDIR empty', array(), array_values( array_diff( scandir( $tmpd ), array( '.', '..' ) ) ) );

sh_rmtree( $base, $base );
sh_summary( 'package.sh' );
