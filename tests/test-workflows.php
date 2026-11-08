<?php
/**
 * Static checks of .github/workflows/release.yml and ci.yml. No network.
 * YAML structure checks use PyYAML through tests/workflow-yaml-dump.py when present;
 * text checks always run. This file holds no SHA values: it reads the pins (name, tag, SHA)
 * from .github/action-pins.txt at run time and compares every `uses:` line with them.
 * FAIL messages never print SHA values.
 * Run: php tests/test-workflows.php
 */

require __DIR__ . '/lib-shell-tests.php';

sh_require_tools( array( 'python3' ) );

$root  = dirname( __DIR__ );
$files = array(
	'release.yml' => $root . '/.github/workflows/release.yml',
	'ci.yml'      => $root . '/.github/workflows/ci.yml',
);
$ci_allowed = array( 'actions/checkout', 'actions/setup-node', 'shivammathur/setup-php' );
$env        = array(
	'PATH'   => (string) getenv( 'PATH' ),
	'HOME'   => sys_get_temp_dir(),
	'TMPDIR' => sys_get_temp_dir(),
	'LC_ALL' => 'C',
);

/** Count a check without printing values (pins must not appear in output). */
function wf_flag( $label, $ok, $fail_label = null ) {
	if ( $ok ) {
		$GLOBALS['sh_passes']++;
		echo "PASS  $label\n";
	} else {
		$GLOBALS['sh_fails']++;
		echo "FAIL  " . ( null === $fail_label ? $label : $fail_label ) . "\n";
	}
}

// Load the pin file: name => array( tag, sha ).
$pins      = array();
$pins_path = $root . '/.github/action-pins.txt';
$pins_text = is_readable( $pins_path ) ? file_get_contents( $pins_path ) : false;
if ( false === $pins_text ) {
	$GLOBALS['sh_fails']++;
	echo "FAIL: .github/action-pins.txt is missing; the user must create it (see README.md, Releasing)\n";
} else {
	echo "\n== action-pins.txt\n";
	foreach ( preg_split( '/\r?\n/', $pins_text ) as $i => $line ) {
		$ln = $i + 1;
		if ( '' === trim( $line ) || '#' === substr( ltrim( $line ), 0, 1 ) ) {
			continue;
		}
		$f  = explode( ' ', $line );
		$ok = 3 === count( $f )
			&& 1 === preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $f[0] )
			&& '' !== $f[1]
			&& 1 === preg_match( '/^[0-9a-f]{40}$/', $f[2] )
			&& ! isset( $pins[ $f[0] ] );
		wf_flag( "action-pins.txt line $ln is valid and not a duplicate", $ok );
		if ( $ok ) {
			$pins[ $f[0] ] = array( $f[1], $f[2] );
		}
	}
	$names        = array_keys( $pins );
	sort( $names );
	$expect_names = array( 'actions/checkout', 'actions/download-artifact', 'actions/setup-node', 'actions/upload-artifact', 'shivammathur/setup-php' );
	wf_flag( 'action-pins.txt names are exactly the five allowed actions', $names === $expect_names );
}
$used_names = array();

/** Return the text of all `run:` values (inline and block) of a workflow text. */
function wf_run_texts( $text ) {
	$lines = preg_split( '/\r?\n/', $text );
	$out   = array();
	$n     = count( $lines );
	for ( $i = 0; $i < $n; $i++ ) {
		if ( ! preg_match( '/^(\s*)(?:- )?run:\s*(.*)$/', $lines[ $i ], $m ) ) {
			continue;
		}
		$indent = strlen( $m[1] );
		$buf    = $m[2];
		if ( preg_match( '/^[|>][+-]?$/', trim( $m[2] ) ) ) {
			$buf = '';
		}
		while ( $i + 1 < $n && ( '' === trim( $lines[ $i + 1 ] ) || strlen( $lines[ $i + 1 ] ) - strlen( ltrim( $lines[ $i + 1 ] ) ) > $indent ) ) {
			$i++;
			$buf .= "\n" . $lines[ $i ];
		}
		$out[] = $buf;
	}
	return $out;
}

$have_yaml = true;
$parsed    = array();
foreach ( $files as $name => $path ) {
	if ( ! is_file( $path ) ) {
		sh_check( "$name exists", true, false );
		continue;
	}
	$r = sh_run( array( 'python3', '-I', __DIR__ . '/workflow-yaml-dump.py', $path ), $root, $env );
	if ( 2 === $r[0] ) {
		$have_yaml = false;
		break;
	}
	sh_check( "$name parses", 0, $r[0] );
	$parsed[ $name ] = json_decode( $r[1], true );
}
if ( ! $have_yaml ) {
	echo "ENV: PyYAML missing, text checks only\n";
}

foreach ( $files as $name => $path ) {
	if ( ! is_file( $path ) ) {
		continue;
	}
	echo "\n== $name (text)\n";
	$text = file_get_contents( $path );
	preg_match_all( '/^\s*(?:- )?uses:\s*(\S+)(.*)$/m', $text, $uses, PREG_SET_ORDER );
	$seen = array();
	foreach ( $uses as $u ) {
		$ok = 1 === preg_match( '#^([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)@([0-9a-f]{40})$#', $u[1], $m );
		wf_flag( "$name: a uses value is <name>@<40-hex sha>", $ok );
		if ( ! $ok ) {
			continue;
		}
		$act    = $m[1];
		$seen[] = $act;
		if ( ! isset( $pins[ $act ] ) ) {
			wf_flag( "$name: $act is in action-pins.txt", false );
			continue;
		}
		wf_flag( "$name: $act sha matches action-pins.txt (no sha differs)", $pins[ $act ][1] === $m[2], "$name: $act sha differs from action-pins.txt" );
		wf_flag( "$name: $act tag comment matches action-pins.txt (no tag differs)", 1 === preg_match( '/^\s*#\s(\S+)\s*$/', $u[2], $c ) && $c[1] === $pins[ $act ][0], "$name: $act tag differs from action-pins.txt" );
	}
	$used_names[ $name ] = array_values( array_unique( $seen ) );
	sort( $used_names[ $name ] );
	if ( 'ci.yml' === $name ) {
		$want = $ci_allowed;
		sort( $want );
		wf_flag( 'ci.yml uses exactly checkout, setup-node, setup-php', $used_names[ $name ] === $want );
	} elseif ( false !== $pins_text ) {
		$want = array_keys( $pins );
		sort( $want );
		wf_flag( 'release.yml uses every action in action-pins.txt', $used_names[ $name ] === $want );
	}
	sh_check( 'every checkout has persist-credentials false', substr_count( $text, 'actions/checkout@' ), substr_count( $text, 'persist-credentials: false' ) );
	sh_check( 'no pull_request_target', 0, substr_count( $text, 'pull_request_target' ) );
	sh_check( 'no secrets. reference', 0, substr_count( $text, 'secrets.' ) );
	sh_check( 'top-level permissions contents read', 1, preg_match( '/^permissions:\n  contents: read\n/m', $text ) );
	$jobs = preg_match_all( '/^    runs-on:/m', $text );
	sh_check( 'every job has timeout-minutes 15', $jobs, preg_match_all( '/^    timeout-minutes: 15$/m', $text ) );
	$bad = 0;
	foreach ( wf_run_texts( $text ) as $run ) {
		if ( false !== strpos( $run, '${{' ) ) {
			$bad++;
		}
	}
	sh_check( 'no expression inside run text', 0, $bad );
}

if ( is_file( $files['release.yml'] ) ) {
	echo "\n== release.yml (text)\n";
	$text = file_get_contents( $files['release.yml'] );
	sh_check( 'tag trigger v*.*.*', 1, preg_match( "/^  push:\n    tags:\n      - 'v\*\.\*\.\*'\n/m", $text ) );
	sh_check( 'workflow_dispatch trigger', 1, preg_match( '/^  workflow_dispatch:/m', $text ) );
	sh_check( 'no pull_request trigger', 0, preg_match( '/^  pull_request/m', $text ) );
	sh_check( 'upload-artifact once', 1, substr_count( $text, 'actions/upload-artifact@' ) );
	sh_check( 'download-artifact once', 1, substr_count( $text, 'actions/download-artifact@' ) );
	sh_check( 'GH_TOKEN appears once', 1, substr_count( $text, 'GH_TOKEN' ) );
	$pos_release = strpos( $text, "\n  release:\n" );
	sh_check( 'job release found', true, false !== $pos_release );
	if ( false !== $pos_release ) {
		$build_part   = substr( $text, 0, $pos_release );
		$release_part = substr( $text, $pos_release );
		sh_check( 'build job has no permissions key', 0, preg_match( '/^    permissions:/m', $build_part ) );
		sh_check( 'upload-artifact is in build', 1, substr_count( $build_part, 'actions/upload-artifact@' ) );
		sh_check( 'download-artifact is in release', 1, substr_count( $release_part, 'actions/download-artifact@' ) );
		sh_check( 'release needs build', 1, preg_match( '/^    needs: build$/m', $release_part ) );
		sh_check( 'release has tag-push if', 1, preg_match( "/^    if: github\.event_name == 'push' && github\.ref_type == 'tag'$/m", $release_part ) );
		sh_check( 'release permissions contents write', 1, preg_match( '/^    permissions:\n      contents: write\n/m', $release_part ) );
		sh_check( 'release has no setup-node or setup-php', 0, preg_match( '#uses:\s*(actions/setup-node|shivammathur/setup-php)@#', $release_part ) );
		$bad = 0;
		foreach ( wf_run_texts( $release_part ) as $run ) {
			if ( false !== strpos( $run, 'npm' ) || false !== strpos( $run, 'package.sh' ) ) {
				$bad++;
			}
		}
		sh_check( 'release has no npm or package.sh in run', 0, $bad );
		sh_check( 'GH_TOKEN is in release job', 1, substr_count( $release_part, 'GH_TOKEN' ) );
	}
	sh_check( 'artifact name used twice', 2, substr_count( $text, 'name: tainacan-immich-zip' ) );
}

if ( $have_yaml && isset( $parsed['release.yml'], $parsed['ci.yml'] ) ) {
	echo "\n== YAML structure\n";
	foreach ( $parsed as $name => $wf ) {
		sh_check( "$name permissions", array( 'contents' => 'read' ), isset( $wf['permissions'] ) ? $wf['permissions'] : null );
		foreach ( $wf['jobs'] as $jid => $job ) {
			sh_check( "$name job $jid timeout", 15, isset( $job['timeout-minutes'] ) ? $job['timeout-minutes'] : null );
			foreach ( $job['steps'] as $step ) {
				if ( isset( $step['run'] ) ) {
					sh_check( "$name job $jid run has no expression", false, false !== strpos( $step['run'], '${{' ) );
				}
			}
		}
	}
	$rel = $parsed['release.yml'];
	$on  = isset( $rel['on'] ) ? $rel['on'] : ( isset( $rel['true'] ) ? $rel['true'] : null );
	sh_check( 'release triggers', array( 'push', 'workflow_dispatch' ), is_array( $on ) ? array_keys( $on ) : null );
	sh_check( 'release tag filter', array( 'v*.*.*' ), isset( $on['push']['tags'] ) ? $on['push']['tags'] : null );
	sh_check( 'release jobs', array( 'build', 'release' ), array_keys( $rel['jobs'] ) );
	sh_check( 'build has no permissions', false, isset( $rel['jobs']['build']['permissions'] ) );
	sh_check( 'release needs build', 'build', isset( $rel['jobs']['release']['needs'] ) ? $rel['jobs']['release']['needs'] : null );
	sh_check( 'release permissions', array( 'contents' => 'write' ), isset( $rel['jobs']['release']['permissions'] ) ? $rel['jobs']['release']['permissions'] : null );
	$ci = $parsed['ci.yml'];
	$on = isset( $ci['on'] ) ? $ci['on'] : ( isset( $ci['true'] ) ? $ci['true'] : null );
	sh_check( 'ci triggers', array( 'push', 'pull_request' ), is_array( $on ) ? array_keys( $on ) : null );
}

/** Index of the first step whose run or uses text contains $needle, or -1. */
function wf_find( array $steps, $needle ) {
	foreach ( $steps as $i => $s ) {
		$hay = ( isset( $s['run'] ) ? $s['run'] : '' ) . ' ' . ( isset( $s['uses'] ) ? $s['uses'] : '' );
		if ( false !== strpos( $hay, $needle ) ) {
			return $i;
		}
	}
	return -1;
}

/** Step array at an index from wf_find, or an empty array. */
function wf_at( array $steps, $idx ) {
	return $idx >= 0 ? $steps[ $idx ] : array();
}

if ( $have_yaml && isset( $parsed['release.yml'], $parsed['ci.yml'] ) ) {
	echo "\n== YAML pins, order, inputs and env\n";
	$rel    = $parsed['release.yml'];
	$ci     = $parsed['ci.yml'];
	// PyYAML reads the key "on" as boolean true; json.dumps writes it as "true".
	$rel_on = isset( $rel['on'] ) ? $rel['on'] : ( isset( $rel['true'] ) ? $rel['true'] : null );
	$ci_on  = isset( $ci['on'] ) ? $ci['on'] : ( isset( $ci['true'] ) ? $ci['true'] : null );

	sh_check( 'release concurrency group', 'release-${{ github.ref }}', isset( $rel['concurrency']['group'] ) ? $rel['concurrency']['group'] : null );
	sh_check( 'release concurrency cancel-in-progress false', false, isset( $rel['concurrency']['cancel-in-progress'] ) ? $rel['concurrency']['cancel-in-progress'] : null );
	sh_check( 'ci concurrency group', 'ci-${{ github.ref }}', isset( $ci['concurrency']['group'] ) ? $ci['concurrency']['group'] : null );
	sh_check( 'ci concurrency cancel-in-progress true', true, isset( $ci['concurrency']['cancel-in-progress'] ) ? $ci['concurrency']['cancel-in-progress'] : null );
	sh_check( 'ci push branches only main', array( 'main' ), isset( $ci_on['push']['branches'] ) ? $ci_on['push']['branches'] : null );
	sh_check( 'ci triggers include pull_request', true, is_array( $ci_on ) && array_key_exists( 'pull_request', $ci_on ) );
	sh_check( 'release workflow_dispatch has no inputs', true, is_array( $rel_on ) && array_key_exists( 'workflow_dispatch', $rel_on ) && null === $rel_on['workflow_dispatch'] );
	sh_check( 'ci job ids', array( 'check' ), array_keys( $ci['jobs'] ) );
	sh_check( 'ci job has no permissions key', false, isset( $ci['jobs']['check']['permissions'] ) );
	sh_check( 'ci job check runs-on ubuntu-latest', 'ubuntu-latest', isset( $ci['jobs']['check']['runs-on'] ) ? $ci['jobs']['check']['runs-on'] : null );
	sh_check( 'release job build runs-on ubuntu-latest', 'ubuntu-latest', isset( $rel['jobs']['build']['runs-on'] ) ? $rel['jobs']['build']['runs-on'] : null );
	sh_check( 'release job release runs-on ubuntu-latest', 'ubuntu-latest', isset( $rel['jobs']['release']['runs-on'] ) ? $rel['jobs']['release']['runs-on'] : null );

	$tag_if = "github.event_name == 'push' && github.ref_type == 'tag'";
	$bs     = $rel['jobs']['build']['steps'];
	$rs     = $rel['jobs']['release']['steps'];
	$cs     = $ci['jobs']['check']['steps'];

	// Build job: notes check is first, before npm ci, and gated to tag push.
	$i_notes = wf_find( $bs, 'changelog-notes.sh' );
	$i_npmci = wf_find( $bs, 'npm ci' );
	sh_check( 'build: notes check before npm ci', true, $i_notes >= 0 && $i_notes < $i_npmci );
	sh_check( 'build: notes check gated to tag push', $tag_if, wf_at( $bs, $i_notes )['if'] ?? null );
	sh_check( 'build: notes check env RELEASE_TAG', array( 'RELEASE_TAG' => '${{ github.ref_name }}' ), wf_at( $bs, $i_notes )['env'] ?? null );

	// Build job: check steps run in order with set -eu and shell bash.
	$check_needles = array( 'npm ci', 'npm run lint', 'fail=0', 'npm run build' );
	foreach ( array( 'release build' => $bs, 'ci check' => $cs ) as $label => $steps ) {
		$last = -1;
		$ok_order = true;
		foreach ( $check_needles as $needle ) {
			$idx = wf_find( $steps, $needle );
			if ( $idx < 0 || $idx <= $last ) {
				$ok_order = false;
			}
			$last = $idx;
		}
		sh_check( "$label: check steps in order (npm ci, lint, tests, build)", true, $ok_order );
		foreach ( $steps as $s ) {
			$run = isset( $s['run'] ) ? $s['run'] : '';
			if ( '' === $run ) {
				continue;
			}
			$is_check = false;
			foreach ( $check_needles as $needle ) {
				if ( false !== strpos( $run, $needle ) ) {
					$is_check = true;
				}
			}
			if ( $is_check ) {
				sh_check( "$label: '{$s['name']}' starts with set -eu", 'set -eu', trim( explode( "\n", ltrim( $run ) )[0] ) );
				sh_check( "$label: '{$s['name']}' shell bash", 'bash', isset( $s['shell'] ) ? $s['shell'] : null );
			}
		}
		$npm_wd_ok = true;
		foreach ( $steps as $s ) {
			$run = isset( $s['run'] ) ? $s['run'] : '';
			if ( false !== strpos( $run, 'npm ' ) ) {
				if ( ! isset( $s['working-directory'] ) || 'tainacan-immich' !== $s['working-directory'] ) {
					$npm_wd_ok = false;
				}
			}
			if ( false !== strpos( $run, 'fail=0' ) && isset( $s['working-directory'] ) ) {
				$npm_wd_ok = false;
			}
		}
		sh_check( "$label: npm steps in tainacan-immich, tests in repo root", true, $npm_wd_ok );
	}

	// Build job: package step inputs and command.
	$i_pkg = wf_find( $bs, 'bin/package.sh' );
	$pkg   = wf_at( $bs, $i_pkg );
	sh_check( 'build: package step env', array( 'EVENT_NAME' => '${{ github.event_name }}', 'REF_TYPE' => '${{ github.ref_type }}', 'REF_NAME' => '${{ github.ref_name }}' ), isset( $pkg['env'] ) ? $pkg['env'] : null );
	sh_check( 'build: package step passes the tag only on tag push', true, isset( $pkg['run'] ) && false !== strpos( $pkg['run'], 'if [ "$EVENT_NAME" = "push" ] && [ "$REF_TYPE" = "tag" ]; then' ) && false !== strpos( $pkg['run'], 'bash bin/package.sh "$REF_NAME"' ) && false !== strpos( $pkg['run'], "else\n  bash bin/package.sh\n" ) );
	sh_check( 'ci: package step has no argument', true,isset( $cs[ wf_find( $cs, 'bin/package.sh' ) ]['run'] ) && 1 === preg_match( '/^\s*bash bin\/package\.sh\s*$/m', $cs[ wf_find( $cs, 'bin/package.sh' ) ]['run'] ) );

	// Artifacts.
	$up = wf_at( $bs, wf_find( $bs, 'actions/upload-artifact@' ) );
	sh_check( 'upload-artifact is the last build step', count( $bs ) - 1, wf_find( $bs, 'actions/upload-artifact@' ) );
	sh_check( 'upload-artifact with inputs', array( 'name' => 'tainacan-immich-zip', 'path' => 'build/tainacan-immich.zip', 'if-no-files-found' => 'error', 'retention-days' => 7 ), isset( $up['with'] ) ? $up['with'] : null );
	$dl = wf_at( $rs, wf_find( $rs, 'actions/download-artifact@' ) );
	sh_check( 'download-artifact with inputs', array( 'name' => 'tainacan-immich-zip', 'path' => 'build' ), isset( $dl['with'] ) ? $dl['with'] : null );
	sh_check( 'download-artifact is after the notes check', true, wf_find( $rs, 'changelog-notes.sh' ) < wf_find( $rs, 'actions/download-artifact@' ) );

	// Release job: order, no if on notes, zip check, publish last.
	sh_check( 'release: checkout first', 'actions/checkout@', isset( $rs[0]['uses'] ) ? substr( $rs[0]['uses'], 0, strlen( 'actions/checkout@' ) ) : null );
	sh_check( 'release: notes check has no if', false, isset( wf_at( $rs, wf_find( $rs, 'changelog-notes.sh' ) )['if'] ) );
	sh_check( 'release: notes check env RELEASE_TAG', array( 'RELEASE_TAG' => '${{ github.ref_name }}' ), wf_at( $rs, wf_find( $rs, 'changelog-notes.sh' ) )['env'] ?? null );
	sh_check( 'release: zip check is test -s', 1, isset( $rs[ wf_find( $rs, 'test -s build/tainacan-immich.zip' ) ] ) ? 1 : 0 );
	sh_check( 'release: publish is the last step', count( $rs ) - 1, wf_find( $rs, 'gh release view' ) );
	sh_check( 'release: step count', 5, count( $rs ) );
	sh_check( 'release: no run text with setup or npm', 0, count( array_filter( $rs, function ( $s ) {
		return isset( $s['run'] ) && ( false !== strpos( $s['run'], 'npm' ) || false !== strpos( $s['run'], 'package.sh' ) );
	} ) ) );

	// Publish step: env and exact commands.
	$pub = wf_at( $rs, wf_find( $rs, 'gh release view' ) );
	sh_check( 'publish env keys', array( 'GH_TOKEN', 'GH_REPO', 'RELEASE_TAG' ), isset( $pub['env'] ) ? array_keys( $pub['env'] ) : null );
	sh_check( 'publish env GH_TOKEN is github.token', '${{ github.token }}', isset( $pub['env']['GH_TOKEN'] ) ? $pub['env']['GH_TOKEN'] : null );
	sh_check( 'publish env GH_REPO is github.repository', '${{ github.repository }}', isset( $pub['env']['GH_REPO'] ) ? $pub['env']['GH_REPO'] : null );
	sh_check( 'publish env RELEASE_TAG is ref_name', '${{ github.ref_name }}', isset( $pub['env']['RELEASE_TAG'] ) ? $pub['env']['RELEASE_TAG'] : null );
	$pub_run = isset( $pub['run'] ) ? $pub['run'] : '';
	foreach ( array(
		'gh release view "$RELEASE_TAG" >/dev/null 2>&1',
		'gh release upload "$RELEASE_TAG" build/tainacan-immich.zip --clobber',
		'gh release edit "$RELEASE_TAG" --title "$RELEASE_TAG" --notes-file "$RUNNER_TEMP/notes.md" --draft=false --prerelease=false',
		'gh release create "$RELEASE_TAG" build/tainacan-immich.zip --title "$RELEASE_TAG" --notes-file "$RUNNER_TEMP/notes.md" --verify-tag',
	) as $cmd ) {
		sh_check( 'publish run has: ' . $cmd, true, false !== strpos( $pub_run, $cmd ) );
	}
	$gh_token_steps = 0;
	foreach ( array_merge( $bs, $rs, $cs ) as $s ) {
		if ( isset( $s['env'] ) && is_array( $s['env'] ) && array_key_exists( 'GH_TOKEN', $s['env'] ) ) {
			$gh_token_steps++;
		}
	}
	sh_check( 'GH_TOKEN set on exactly one step', 1, $gh_token_steps );

	// Action inputs on every setup step.
	$setup = array(
		'node' => array( 'actions/setup-node@', array( 'node-version' => '22', 'cache' => 'npm', 'cache-dependency-path' => 'tainacan-immich/package-lock.json' ) ),
		'php'  => array( 'shivammathur/setup-php@', array( 'php-version' => '8.3', 'coverage' => 'none' ) ),
	);
	foreach ( array( 'release build' => $bs, 'ci check' => $cs ) as $label => $steps ) {
		foreach ( $setup as $kind => $spec ) {
			$s = wf_at( $steps, wf_find( $steps, $spec[0] ) );
			sh_check( "$label: setup-$kind inputs", $spec[1], isset( $s['with'] ) ? $s['with'] : null );
		}
		foreach ( $steps as $s ) {
			if ( isset( $s['uses'] ) && 0 === strpos( $s['uses'], 'actions/checkout@' ) ) {
				sh_check( "$label: checkout inputs", array( 'persist-credentials' => false ), isset( $s['with'] ) ? $s['with'] : null );
			}
		}
	}
	sh_check( 'ci: no upload-artifact, GH_TOKEN or contents write', 0, substr_count( file_get_contents( $files['ci.yml'] ), 'upload-artifact' ) + substr_count( file_get_contents( $files['ci.yml'] ), 'GH_TOKEN' ) + substr_count( file_get_contents( $files['ci.yml'] ), 'contents: write' ) );
}

echo "\n== actionlint\n";
if ( sh_have( 'actionlint' ) ) {
	foreach ( $files as $name => $path ) {
		$r = sh_run( array( 'actionlint', $path ), $root, $env );
		sh_check( "actionlint $name", 0, $r[0] );
	}
} else {
	echo "ENV: actionlint missing\n";
}

sh_summary( 'workflows' );
