#!/usr/bin/env bash
# Makes build/tainacan-immich.zip from tainacan-immich/ (single source of truth for the zip).
# Usage: bin/package.sh [vX.Y.Z]   (run from the repo root)
set -eu
set -o pipefail
set -E
export LC_ALL=C
# Safety net: any command that fails under set -e ends the script with exit 1 (never another code).
trap 'exit 1' ERR

fail() {
	echo "package.sh: error: $*" >&2
	exit 1
}

if [ "$#" -gt 1 ]; then
	echo "usage: bin/package.sh [vX.Y.Z]" >&2
	exit 1
fi

# 1. Repo root check.
for f in bin/package.sh tainacan-immich/tainacan-immich.php tainacan-immich/README.txt tainacan-immich/package.json; do
	[ -f "$f" ] || fail "run from the repo root ($f not found)"
done

# 2. Tag argument.
tag=''
if [ "$#" -eq 1 ]; then
	tag="$1"
	[[ "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "bad tag '$tag' (expected vX.Y.Z)"
fi

# 3. Read the four versions.
read_header() {
	# $1 file, $2 label
	local line
	line="$(grep -m1 "^$2:" "$1" || true)"
	line="${line#"$2:"}"
	line="$(printf '%s' "$line" | tr -d '\r' | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
	printf '%s' "$line"
}

main_file='tainacan-immich/tainacan-immich.php'
readme_file='tainacan-immich/README.txt'
pkg_file='tainacan-immich/package.json'

v_main="$(read_header "$main_file" 'Version')"
s_main="$(read_header "$main_file" 'Stable tag')"
s_readme="$(read_header "$readme_file" 'Stable tag')"
v_pkg="$(node -e 'const fs=require("fs");const j=JSON.parse(fs.readFileSync("./tainacan-immich/package.json","utf8"));process.stdout.write(typeof j.version==="string"?j.version.trim():"")' 2>/dev/null)" || fail "$pkg_file: cannot parse JSON"

[ -n "$v_main" ] || fail "$main_file: field 'Version:' missing or empty"
[ -n "$s_main" ] || fail "$main_file: field 'Stable tag:' missing or empty"
[ -n "$s_readme" ] || fail "$readme_file: field 'Stable tag:' missing or empty"
[ -n "$v_pkg" ] || fail "$pkg_file: field 'version' missing or empty"

# 4. Compare.
[[ "$v_main" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "$main_file: 'Version:' is not X.Y.Z ($v_main)"
if [ "$v_main" != "$s_main" ] || [ "$v_main" != "$s_readme" ] || [ "$v_main" != "$v_pkg" ]; then
	fail "version mismatch: Version=$v_main main-stable-tag=$s_main readme-stable-tag=$s_readme package.json=$v_pkg"
fi
version="$v_main"
if [ -n "$tag" ] && [ "${tag#v}" != "$version" ]; then
	fail "tag $tag does not match version $version"
fi

# 5. Build files.
for f in tainacan-immich/build/immich-link.js tainacan-immich/build/immich-link-form.js; do
	[ -s "$f" ] || fail "$f missing or empty, run npm run build"
done

# 6. Output dir.
if [ -e build ] && [ ! -d build ]; then
	fail "build exists and is not a directory"
fi
mkdir -p build

# 7. Temp dir.
tmp=''
cleanup() {
	if [ -n "$tmp" ] && [ -d "$tmp" ]; then
		rm -rf -- "$tmp"
	fi
}
trap cleanup EXIT
trap 'exit 1' HUP INT TERM
tmp="$(mktemp -d "${TMPDIR:-/tmp}/tainacan-immich-pkg.XXXXXX")"

# 8. Stage.
mkdir -p "$tmp/stage/tainacan-immich"
for e in tainacan-immich/*; do
	[ -e "$e" ] || continue
	case "${e#tainacan-immich/}" in
		node_modules | src | package.json | package-lock.json) continue ;;
	esac
	cp -R -- "$e" "$tmp/stage/tainacan-immich/"
done

# 9. Zip (new archive each time).
(cd "$tmp/stage" && find tainacan-immich -print | sort | zip -X -q "$tmp/tainacan-immich.zip" -@) || fail "cannot make the zip"

# 10. Verify.
zip_file="$tmp/tainacan-immich.zip"
entries="$(unzip -Z1 "$zip_file")" || fail "cannot list the zip"
[ -n "$entries" ] || fail "zip is empty"
count=0
have_main=0
have_js=0
have_form=0
while IFS= read -r name; do
	count=$((count + 1))
	case "$name" in
		tainacan-immich/*) ;;
		*) fail "zip entry outside top folder: $name" ;;
	esac
	if [[ "$name" =~ ^tainacan-immich/(node_modules|src)(/|$) ]] || [[ "$name" =~ ^tainacan-immich/package(-lock)?\.json$ ]]; then
		fail "excluded file in zip: $name"
	fi
	[ "$name" = 'tainacan-immich/tainacan-immich.php' ] && have_main=1
	[ "$name" = 'tainacan-immich/build/immich-link.js' ] && have_js=1
	[ "$name" = 'tainacan-immich/build/immich-link-form.js' ] && have_form=1
done <<EOF
$entries
EOF
[ "$have_main" -eq 1 ] || fail "zip lacks tainacan-immich/tainacan-immich.php"
[ "$have_js" -eq 1 ] || fail "zip lacks tainacan-immich/build/immich-link.js"
[ "$have_form" -eq 1 ] || fail "zip lacks tainacan-immich/build/immich-link-form.js"
zv="$(unzip -p "$zip_file" tainacan-immich/tainacan-immich.php | grep -m1 '^Version:' | tr -d '\r' | sed -e 's/^Version:[[:space:]]*//' -e 's/[[:space:]]*$//' || true)"
[ "$zv" = "$version" ] || fail "Version inside the zip ($zv) is not $version"

# 11. Publish the zip (overwrite, no rm).
mv -f "$zip_file" build/tainacan-immich.zip

# 12. Report.
sum="$(sha256sum build/tainacan-immich.zip)"
sum="${sum%% *}"
echo "package.sh: version $version"
echo "package.sh: entries $count"
echo "package.sh: sha256 $sum"
echo "package.sh: wrote build/tainacan-immich.zip"
