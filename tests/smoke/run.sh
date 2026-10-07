#!/usr/bin/env bash
#
# End to end smoke tests of the isotool command, run as separate processes on every CI operating system.
# The PHPUnit suite already covers the CLI in-process; this checks what it cannot see: the real entry
# points (bin/isotool, bin/isotool.bat), exit codes, standard input / output / error, binary safe output,
# extraction on the real file system and paths with spaces or non ASCII characters.
#
# Usage:
#   tests/smoke/run.sh                       # run with "php bin/isotool"
#   ISOTOOL=bin/isotool.bat tests/smoke/run.sh
#   UPDATE=1 tests/smoke/run.sh              # regenerate the expected outputs in tests/smoke/expected
#
# Text outputs are compared after removing "\r" (the CLI ends lines with PHP_EOL, "\r\n" on Windows);
# binary outputs (--cat, --extract, --extract-boot) are compared byte for byte through SHA-256.

set -u

cd "$(dirname "$0")/../.." || exit 1

# Git Bash on Windows rewrites arguments that look like paths ("/A.TXT" -> "C:/Program Files/Git/A.TXT")
export MSYS_NO_PATHCONV=1
export MSYS2_ARG_CONV_EXCL='*'

ISOTOOL=${ISOTOOL:-php bin/isotool}
UPDATE=${UPDATE:-0}
EXPECTED=tests/smoke/expected
WORK=$(mktemp -d 2>/dev/null || mktemp -d -t isotool-smoke)
trap 'rm -rf "$WORK"' EXIT

# with the path conversion off, PHP on Windows must receive a native path ("C:/Users/..." instead of "/tmp/...")
if command -v cygpath >/dev/null 2>&1; then
    WORK=$(cygpath -m "$WORK")
fi

failures=0
checks=0

fail() {
    failures=$((failures + 1))
    echo "FAIL: $*" >&2
}

pass() {
    checks=$((checks + 1))
}

# run <stdout file> <stderr file> <args...>: runs isotool, returns its exit code
run() {
    local out=$1 err=$2
    shift 2
    # shellcheck disable=SC2086 # ISOTOOL may be "php bin/isotool"
    $ISOTOOL "$@" >"$out" 2>"$err"
}

hashes() {
    php tests/smoke/hashes.php "$1"
}

sha256() {
    # shellcheck disable=SC2016 # PHP code, not shell
    php -r 'echo hash_file("sha256", $argv[1]), "\n";' "$1"
}

# compare_text <name> <actual file>: the actual output must match tests/smoke/expected/<name> (line endings ignored)
compare_text() {
    local name=$1 actual=$2
    tr -d '\r' <"$actual" >"$actual.lf"

    if [ "$UPDATE" = "1" ]; then
        cp "$actual.lf" "$EXPECTED/$name"
        pass
        return
    fi

    if [ ! -f "$EXPECTED/$name" ]; then
        fail "$name: missing expected output (run with UPDATE=1)"
    elif ! diff -u "$EXPECTED/$name" "$actual.lf" >"$WORK/diff" 2>&1; then
        fail "$name: output differs from $EXPECTED/$name"
        head -40 "$WORK/diff" >&2
    else
        pass
    fi
}

# expect_exit <code> <description> <args...>: checks the exit code, an error on stderr and nothing on stdout for failures
expect_exit() {
    local expected=$1 description=$2
    shift 2
    run "$WORK/out" "$WORK/err" "$@"
    local code=$?

    if [ "$code" != "$expected" ]; then
        fail "$description: exit code $code, expected $expected ($(head -1 "$WORK/err"))"
        return
    fi

    if [ "$expected" != "0" ] && [ "$expected" != "1" ] && [ -s "$WORK/out" ]; then
        fail "$description: printed on stdout while failing"
        return
    fi

    if [ "$expected" != "0" ] && ! grep -q '^ERROR: ' "$WORK/err"; then
        fail "$description: no ERROR line on stderr"
        return
    fi

    pass
}

mkdir -p "$EXPECTED"

fixtures=()
for iso in fixtures/*.iso; do
    [ "$(basename "$iso")" = "invalid.iso" ] && continue
    fixtures+=("$iso")
done

# 1. every readable fixture through every read only action, compared with the expected outputs
for iso in "${fixtures[@]}"; do
    name=$(basename "$iso" .iso)

    run "$WORK/out" "$WORK/err" -f "$iso" -l --ndjson
    code=$?
    if [ "$code" != "0" ]; then fail "$name --list --ndjson: exit code $code"; else compare_text "$name.ndjson" "$WORK/out"; fi

    run "$WORK/out" "$WORK/err" -f "$iso" -j
    code=$?
    if [ "$code" != "0" ]; then fail "$name --json: exit code $code"; else compare_text "$name.json" "$WORK/out"; fi

    run "$WORK/out" "$WORK/err" -f "$iso" --files
    code=$?
    if [ "$code" != "0" ]; then fail "$name info: exit code $code"; else
        # the info output starts with the image path as given on the command line
        compare_text "$name.info" "$WORK/out"
    fi

    for volume in primary joliet udf; do
        run "$WORK/out" "$WORK/err" -f "$iso" --volume="$volume" -l
        code=$?
        if [ "$code" = "0" ]; then
            compare_text "$name.$volume.list" "$WORK/out"
        elif [ "$code" = "3" ] && grep -q "volume was not found" "$WORK/err"; then
            pass
        else
            fail "$name --volume=$volume: exit code $code ($(head -1 "$WORK/err"))"
        fi
    done

    expect_exit 0 "$name --strict" -f "$iso" -l --strict
    expect_exit 0 "$name --find" -f "$iso" --find '*'

    # standard input gives the same listing as the file
    # shellcheck disable=SC2086
    $ISOTOOL -f - -l <"$iso" >"$WORK/stdin" 2>"$WORK/err"
    code=$?
    $ISOTOOL -f "$iso" -l >"$WORK/file" 2>/dev/null
    if [ "$code" != "0" ]; then
        fail "$name -f -: exit code $code ($(head -1 "$WORK/err"))"
    elif ! cmp -s "$WORK/stdin" "$WORK/file"; then
        fail "$name -f -: standard input listing differs from the file listing"
    else
        pass
    fi

    # extraction: the hashes of the extracted files are part of the expected outputs
    rm -rf "$WORK/x"
    run "$WORK/out" "$WORK/err" -f "$iso" -x "$WORK/x"
    code=$?
    if [ "$code" != "0" ]; then
        fail "$name --extract: exit code $code ($(head -1 "$WORK/err"))"
        continue
    fi
    hashes "$WORK/x" >"$WORK/hashes"
    compare_text "$name.sha256" "$WORK/hashes"

    # --cat writes exactly the bytes of the file (binary safe standard output): the first and last files
    # and the binary ones, the manifests already check the content of every file
    while read -r hash path; do
        [ -z "$path" ] && continue
        run "$WORK/cat" "$WORK/err" -f "$iso" -c "/$path"
        code=$?
        if [ "$code" != "0" ]; then
            fail "$name --cat /$path: exit code $code ($(head -1 "$WORK/err"))"
        elif [ "$(sha256 "$WORK/cat")" != "$hash" ]; then
            fail "$name --cat /$path: content differs from the extracted file"
        else
            pass
        fi
    done < <(awk -v n="$(wc -l <"$WORK/hashes")" 'NR <= 3 || NR > n - 3 || tolower($0) ~ /\.(bin|img|com|exe|sys)$/' "$WORK/hashes")
done

# 1b. extraction compared with the manifests of the generated fixtures: SHA-256 of the files of the source
# tree each image was built from (fixtures/build-fixtures.sh), an expectation that does not come from this library
for manifest in fixtures/manifests/*.sha256; do
    base=$(basename "$manifest" .sha256)
    image=${base%.*}
    volume=${base##*.}
    [ "$volume" = "boot" ] && continue

    options=()
    [ "$volume" != "default" ] && options=(--volume="$volume")

    rm -rf "$WORK/m"
    if ! run "$WORK/out" "$WORK/err" -f "fixtures/$image.iso" "${options[@]}" -x "$WORK/m"; then
        fail "$base: extraction failed ($(head -1 "$WORK/err"))"
        continue
    fi

    hashes "$WORK/m" | tr -d '\r' >"$WORK/hashes"
    if ! diff -u "$manifest" "$WORK/hashes" >"$WORK/diff"; then
        fail "$base: extracted files differ from the source tree manifest"
        head -20 "$WORK/diff" >&2
    else
        pass
    fi
done

for manifest in fixtures/manifests/*.boot.sha256; do
    image=$(basename "$manifest" .boot.sha256)
    if ! run "$WORK/out" "$WORK/err" -f "fixtures/$image.iso" --extract-boot="$WORK/boot-$image.img"; then
        fail "$image --extract-boot: $(head -1 "$WORK/err")"
    elif [ "$(sha256 "$WORK/boot-$image.img" | tr -d '\r')" != "$(tr -d '\r' <"$manifest")" ]; then
        fail "$image --extract-boot: boot image differs from the source manifest"
    else
        pass
    fi
done

# 2. Rock Ridge can be turned off
if run "$WORK/out" "$WORK/err" -f fixtures/rockridge.iso --no-rock-ridge -l; then compare_text "rockridge.no-rock-ridge.list" "$WORK/out"; else fail "rockridge --no-rock-ridge"; fi

# 3. El Torito boot image
if ! run "$WORK/out" "$WORK/err" -f fixtures/DOS4.01_bootdisk.iso --extract-boot="$WORK/boot.img"; then
    fail "--extract-boot: $(head -1 "$WORK/err")"
else
    sha256 "$WORK/boot.img" >"$WORK/boot.sha256"
    compare_text "DOS4.01_bootdisk.boot.sha256" "$WORK/boot.sha256"
fi

# 4. paths with spaces and non ASCII characters, for the image and the extraction directory
odd="$WORK/dir with spaces ü/中文 ünï"
mkdir -p "$odd"
cp fixtures/joliet_cjk.iso "$odd/image ü 中文.iso"
rm -rf "$WORK/x"
if ! run "$WORK/out" "$WORK/err" -f "$odd/image ü 中文.iso" -x "$odd/out dir"; then
    fail "odd paths --extract: $(head -1 "$WORK/err")"
else
    hashes "$odd/out dir" >"$WORK/hashes"
    compare_text "joliet_cjk.sha256" "$WORK/hashes"
fi

# 4b. damaged inputs generated here: a clean error or a partial result, never a PHP warning or a crash
# shellcheck disable=SC2016 # PHP code, not shell
php -r '$d = file_get_contents($argv[1]); file_put_contents($argv[2], substr($d, 0, 17 * 2048)); file_put_contents($argv[3], substr($d, 0, intdiv(strlen($d) * 6, 10)));'     fixtures/rr_joliet.iso "$WORK/truncated-descriptors.iso" "$WORK/truncated-tree.iso"
# shellcheck disable=SC2016 # PHP code, not shell
php -r 'mt_srand(42); $s = ""; for ($i = 0; $i < 65536; $i++) { $s .= chr(mt_rand(0, 255)); } file_put_contents($argv[1], $s);' "$WORK/random.iso"
: >"$WORK/empty.iso"

for damaged in truncated-descriptors truncated-tree random empty; do
    for action in -l -j --files; do
        run "$WORK/out" "$WORK/err" -f "$WORK/$damaged.iso" "$action"
        code=$?
        if [ "$code" != "0" ] && [ "$code" != "3" ]; then
            fail "$damaged $action: exit code $code"
        elif grep -qiE '(warning|notice|deprecated|fatal error|uncaught)' "$WORK/out" "$WORK/err" && ! grep -q '^WARNING: ' "$WORK/err"; then
            fail "$damaged $action: PHP error output"
            head -5 "$WORK/err" >&2
        elif [ "$code" = "3" ] && ! grep -q '^ERROR: ' "$WORK/err"; then
            fail "$damaged $action: exit code 3 without an ERROR line"
        else
            pass
        fi
    done
done
expect_exit 3 "random bytes" -f "$WORK/random.iso" -l
expect_exit 3 "empty file" -f "$WORK/empty.iso" -l

# 5. error paths: exit codes, ERROR line on stderr, nothing on stdout
expect_exit 1 "unknown option" -f fixtures/test.iso --bogus
expect_exit 1 "missing --file" -l
expect_exit 2 "empty --file" --file= -l
expect_exit 1 "conflicting actions" -f fixtures/test.iso -l -j
expect_exit 1 "unknown volume" -f fixtures/test.iso --volume=bogus -l
expect_exit 1 "--ndjson without --list" -f fixtures/test.iso --ndjson
expect_exit 3 "missing image" -f "$WORK/does-not-exist.iso" -l
expect_exit 3 "directory as image" -f fixtures -l
expect_exit 3 "invalid image" -f fixtures/invalid.iso -l
expect_exit 3 "missing file in --cat" -f fixtures/test.iso -c /does/not/exist
expect_exit 3 "no boot record" -f fixtures/test.iso --extract-boot="$WORK/none.img"
expect_exit 3 "missing udf volume" -f fixtures/test-dir.iso --volume=udf -l
expect_exit 0 "help" -h

# without arguments the help is printed and the exit code is the usage one
run "$WORK/out" "$WORK/err"
code=$?
if [ "$code" != "1" ] || ! grep -q '^Usage:' "$WORK/out"; then fail "no arguments: exit code $code, help expected"; else pass; fi

if [ "$UPDATE" = "1" ]; then
    echo "Updated $checks expected outputs in $EXPECTED"
    exit 0
fi

echo "$checks checks passed, $failures failed ($ISOTOOL)"
[ "$failures" = "0" ]
