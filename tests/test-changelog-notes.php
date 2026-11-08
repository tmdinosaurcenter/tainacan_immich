<?php
/**
 * Tests for bin/changelog-notes.sh with fixture files in a temp dir. No network.
 * Run: php tests/test-changelog-notes.php
 */

require __DIR__ . '/lib-shell-tests.php';

sh_require_tools( array( 'bash' ) );

$root   = dirname( __DIR__ );
$script = $root . '/bin/changelog-notes.sh';
$base   = sh_mktemp( 'cltest' );
$env    = array(
	'PATH'   => (string) getenv( 'PATH' ),
	'HOME'   => $base,
	'TMPDIR' => $base,
	'LC_ALL' => 'C',
);

function cl_run( $script, array $args, $base, array $env ) {
	return sh_run( array_merge( array( 'bash', $script ), $args ), $base, $env );
}

$real = "== Changelog ==\n\n= 0.2.0 =\n* One.\n* Two.\n* Three.\n\n= 0.1.0 =\n* Old.\n\n== Copyright ==\n\nText.\n";
sh_write( $base . '/real.txt', $real );
$r = cl_run( $script, array( 'v0.2.0', $base . '/real.txt' ), $base, $env );
sh_check( 'found: exit 0', 0, $r[0] );
sh_check( 'found: exact 3 lines', "* One.\n* Two.\n* Three.\n", $r[1] );

$r = cl_run( $script, array( 'v0.1.0', $base . '/real.txt' ), $base, $env );
sh_check( 'last block stops at heading: exit 0', 0, $r[0] );
sh_check( 'last block stops at heading: output', "* Old.\n", $r[1] );

$r = cl_run( $script, array( 'v0.9.0', $base . '/real.txt' ), $base, $env );
sh_check( 'missing block: exit 1', 1, $r[0] );
sh_check( 'missing block: message', 1, preg_match( '/no changelog block for 0\.9\.0/', $r[2] ) );

sh_write( $base . '/empty.txt', "= 0.4.0 =\n\n\n= 0.3.0 =\n* Three.\n" );
$r = cl_run( $script, array( 'v0.4.0', $base . '/empty.txt' ), $base, $env );
sh_check( 'empty block: exit 1', 1, $r[0] );
sh_check( 'empty block: message', 1, preg_match( '/empty changelog block for 0\.4\.0/', $r[2] ) );

sh_write( $base . '/collide.txt', "= 0.2.10 =\n* Ten.\n\n= 0.2.0 =\n* Zero.\n" );
$r = cl_run( $script, array( 'v0.2.0', $base . '/collide.txt' ), $base, $env );
sh_check( '0.2.0 vs 0.2.10: only the 0.2.0 block', "* Zero.\n", $r[1] );
$r = cl_run( $script, array( 'v0.2.1', $base . '/collide.txt' ), $base, $env );
sh_check( '0.2.1 not found next to 0.2.10: exit 1', 1, $r[0] );

sh_write( $base . '/dots.txt', "= 0x2x0 =\n* Wrong.\n" );
$r = cl_run( $script, array( 'v0.2.0', $base . '/dots.txt' ), $base, $env );
sh_check( 'dots are literal: exit 1', 1, $r[0] );

sh_write( $base . '/crlf.txt', "= 0.2.0 =\r\n* One.  \r\n* Two.\r\n\r\n= 0.1.0 =\r\n* Old.\r\n" );
$r = cl_run( $script, array( 'v0.2.0', $base . '/crlf.txt' ), $base, $env );
sh_check( 'CRLF: exit 0', 0, $r[0] );
sh_check( 'CRLF: no carriage return in output', "* One.\n* Two.\n", $r[1] );

foreach ( array( '0.2.0', 'v0.2', 'v0.2.0-rc1', 'v0.2.0x', '' ) as $bad ) {
	$r = cl_run( $script, array( $bad, $base . '/real.txt' ), $base, $env );
	sh_check( 'bad tag "' . $bad . '": exit 1', 1, $r[0] );
}
$r = cl_run( $script, array(), $base, $env );
sh_check( 'no arguments: exit 1', 1, $r[0] );
$r = cl_run( $script, array( 'v0.2.0', $base . '/real.txt', 'extra' ), $base, $env );
sh_check( 'three arguments: exit 1', 1, $r[0] );
$r = cl_run( $script, array( 'v0.2.0', $base . '/nope.txt' ), $base, $env );
sh_check( 'missing file: exit 1', 1, $r[0] );

// Real README.txt: the 0.2.1 block is exactly one bullet.
$r = sh_run( array( 'bash', $script, 'v0.2.1' ), $root, $env );
sh_check( 'real README: exit 0', 0, $r[0] );
sh_check( 'real README: one bullet', "* Release tooling: GitHub Actions builds and publishes the zip when a version tag is pushed.\n", $r[1] );
$r = sh_run( array( 'bash', $script, 'v0.2.1' ), $root, $env );
sh_check( 'real README: exact stdout is one line', 1, substr_count( $r[1], "\n" ) );
sh_check( 'real README: stderr empty', '', $r[2] );

echo "\n== Added edge cases\n";
sh_write( $base . '/trail.txt', "= 0.2.0 =   \n* A.\n" );
$r = cl_run( $script, array( 'v0.2.0', $base . '/trail.txt' ), $base, $env );
sh_check( 'heading with trailing spaces matches: exit 0', 0, $r[0] );
sh_check( 'heading with trailing spaces matches: output', "* A.\n", $r[1] );

sh_write( $base . '/blank.txt', "= 0.2.0 =\n\n\n* A.\n\n\n= 0.1.0 =\n* B.\n" );
$r = cl_run( $script, array( 'v0.2.0', $base . '/blank.txt' ), $base, $env );
sh_check( 'leading and trailing blank lines dropped', "* A.\n", $r[1] );

sh_write( $base . '/spaces.txt', "= 0.5.0 =\n   \n\t\n= 0.4.0 =\n* D.\n" );
$r = cl_run( $script, array( 'v0.5.0', $base . '/spaces.txt' ), $base, $env );
sh_check( 'block of blank and space-only lines: exit 1', 1, $r[0] );
sh_check( 'block of blank and space-only lines: message', 1, preg_match( '/empty changelog block for 0\.5\.0/', $r[2] ) );

sh_write( $base . '/eof.txt', "= 0.6.0 =\n* Last.\n" );
$r = cl_run( $script, array( 'v0.6.0', $base . '/eof.txt' ), $base, $env );
sh_check( 'block at end of file: exit 0', 0, $r[0] );
sh_check( 'block at end of file: output', "* Last.\n", $r[1] );

sh_write( $base . '/copy.txt', "= 0.7.0 =\n* Seven.\n\n== Copyright ==\n\n* Not in block.\n" );
$r = cl_run( $script, array( 'v0.7.0', $base . '/copy.txt' ), $base, $env );
sh_check( 'block stops at == heading: exit 0', 0, $r[0] );
sh_check( 'block stops at == heading: output', "* Seven.\n", $r[1] );

sh_write( $base . '/short.txt', "= 0.8.0 =\n* Eight.\n" );
$r = cl_run( $script, array( 'v0.8.0', $base . '/short.txt' ), $base, $env );
sh_check( 'one-bullet block at end of file: output', "* Eight.\n", $r[1] );

$r = cl_run( $script, array( 'v0.2.0' ), $base, $env );
sh_check( 'default readme path is relative to cwd: exit 1 outside repo', 1, $r[0] );
sh_check( 'default readme path is relative to cwd: message', 1, preg_match( '/cannot read tainacan-immich\/README\.txt/', $r[2] ) );

sh_rmtree( $base, $base );
sh_summary( 'changelog-notes.sh' );
