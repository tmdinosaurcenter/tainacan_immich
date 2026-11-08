#!/usr/bin/env bash
# Prints the changelog block for one version from the plugin README.txt.
# Usage: bin/changelog-notes.sh vX.Y.Z [readme_path]
set -eu
set -o pipefail
set -E
export LC_ALL=C
# Safety net: any command that fails under set -e ends the script with exit 1 (never another code).
trap 'exit 1' ERR

fail() {
	echo "changelog-notes.sh: error: $*" >&2
	exit 1
}

if [ "$#" -lt 1 ] || [ "$#" -gt 2 ]; then
	echo "usage: bin/changelog-notes.sh vX.Y.Z [readme_path]" >&2
	exit 1
fi

tag="$1"
readme="${2:-tainacan-immich/README.txt}"
[[ "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "bad tag '$tag' (expected vX.Y.Z)"
[ -f "$readme" ] && [ -r "$readme" ] || fail "cannot read $readme"
ver="${tag#v}"

out="$(awk -v ver="$ver" '
	{ sub(/\r$/, ""); sub(/[ \t]+$/, "") }
	state == 0 {
		if ($0 == "= " ver " =") { state = 1; found = 1 }
		next
	}
	state == 1 {
		if ($0 ~ /^= .* =$/ || $0 ~ /^== /) { state = 2; next }
		lines[++n] = $0
	}
	END {
		if (!found) { exit 3 }
		first = 1
		while (first <= n && lines[first] == "") first++
		last = n
		while (last >= first && lines[last] == "") last--
		if (first > last) { exit 4 }
		for (i = first; i <= last; i++) print lines[i]
	}
' "$readme")" && rc=0 || rc=$?

case "$rc" in
	0) printf '%s\n' "$out" ;;
	3) fail "no changelog block for $ver" ;;
	4) fail "empty changelog block for $ver" ;;
	*) fail "awk failed" ;;
esac
