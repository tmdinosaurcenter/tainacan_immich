<?php
/**
 * Shared helpers for the shell and workflow tests. Not a test by itself
 * (name does not match tests/test-*.php). Plain PHP CLI, no network.
 */

$GLOBALS['sh_passes'] = 0;
$GLOBALS['sh_fails']  = 0;

function sh_check( $label, $expected, $actual ) {
	if ( $expected === $actual ) {
		$GLOBALS['sh_passes']++;
		echo "PASS  $label\n";
	} else {
		$GLOBALS['sh_fails']++;
		echo "FAIL  $label: expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
	}
}

function sh_summary( $name ) {
	echo "\n== $name summary: {$GLOBALS['sh_passes']} passed, {$GLOBALS['sh_fails']} failed\n";
	exit( $GLOBALS['sh_fails'] > 0 ? 1 : 0 );
}

/** Return true when an executable is on PATH. */
function sh_have( $tool ) {
	foreach ( explode( ':', (string) getenv( 'PATH' ) ) as $dir ) {
		if ( '' !== $dir && is_file( $dir . '/' . $tool ) && is_executable( $dir . '/' . $tool ) ) {
			return true;
		}
	}
	return false;
}

/** Check tools. Missing tool: FAIL when CI is set, else SKIP and exit 0. */
function sh_require_tools( array $tools ) {
	foreach ( $tools as $tool ) {
		if ( ! sh_have( $tool ) ) {
			if ( getenv( 'CI' ) ) {
				sh_check( "tool $tool present", true, false );
				sh_summary( 'tools' );
			}
			echo "SKIP (ENV): $tool missing\n";
			exit( 0 );
		}
	}
}

/** Run a command (array form). Returns array( exit, stdout, stderr ). */
function sh_run( array $cmd, $cwd, array $env ) {
	$spec = array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
	$proc = proc_open( $cmd, $spec, $pipes, $cwd, $env );
	if ( ! is_resource( $proc ) ) {
		return array( -1, '', 'proc_open failed' );
	}
	$out = stream_get_contents( $pipes[1] );
	$err = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$code = proc_close( $proc );
	return array( $code, $out, $err );
}

/** Make a unique temp dir. */
function sh_mktemp( $prefix ) {
	$dir = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex( random_bytes( 6 ) );
	mkdir( $dir, 0700 );
	return $dir;
}

/** Recursive delete that refuses any path outside $root (the dir the test made). */
function sh_rmtree( $path, $root ) {
	$real_root = realpath( $root );
	$real      = realpath( $path );
	if ( false === $real_root || false === $real ) {
		return;
	}
	if ( $real !== $real_root && 0 !== strpos( $real, $real_root . '/' ) ) {
		return;
	}
	if ( is_link( $path ) || is_file( $path ) ) {
		unlink( $path );
		return;
	}
	foreach ( scandir( $path ) as $name ) {
		if ( '.' === $name || '..' === $name ) {
			continue;
		}
		sh_rmtree( $path . '/' . $name, $root );
	}
	rmdir( $path );
}

function sh_write( $path, $content ) {
	$dir = dirname( $path );
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0755, true );
	}
	file_put_contents( $path, $content );
}

/** Map of relative path => sha1 for every file under $dir, skipping a top-level name. */
function sh_snapshot( $dir, $skip_top = null ) {
	$map = array();
	$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		$rel = substr( $f->getPathname(), strlen( $dir ) + 1 );
		if ( null !== $skip_top && ( $rel === $skip_top || 0 === strpos( $rel, $skip_top . '/' ) ) ) {
			continue;
		}
		if ( $f->isFile() ) {
			$map[ $rel ] = sha1_file( $f->getPathname() );
		}
	}
	ksort( $map );
	return $map;
}
