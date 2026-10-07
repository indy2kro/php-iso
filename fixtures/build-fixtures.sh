#!/usr/bin/env bash
# Regenerates the small real-world fixtures (rockridge, iso1999, joliet_cjk, no_extension).
# Usage: fixtures/build-fixtures.sh           (runs through Docker, debian:stable-slim)
#        fixtures/build-fixtures.sh --inside  (already inside a container/host with xorriso + genisoimage)
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [ "${1:-}" != "--inside" ]; then
    # No bind mount (Docker Desktop file sharing may be restricted): copy in / out with docker cp.
    NAME="php-iso-fixtures-$$"
    export MSYS_NO_PATHCONV=1
    docker create --name "$NAME" debian:stable-slim sleep 600 >/dev/null
    trap 'docker rm -f "$NAME" >/dev/null 2>&1 || true' EXIT
    docker start "$NAME" >/dev/null
    docker exec "$NAME" mkdir -p /work
    cd "$HERE"   # relative paths: a Windows drive letter would be parsed as host:container by docker cp
    docker cp build-fixtures.sh "$NAME:/work/build-fixtures.sh"
    docker exec -e DEBIAN_FRONTEND=noninteractive "$NAME" bash -c "apt-get update -qq && apt-get install -y -qq xorriso genisoimage >/dev/null && bash /work/build-fixtures.sh --inside"
    for f in rockridge iso1999 joliet_cjk no_extension; do
        docker cp "$NAME:/work/$f.iso" "./$f.iso"
    done
    exit 0
fi

cd /work
export SOURCE_DATE_EPOCH=1700000000
T="$(mktemp -d)"

# (a) Rock Ridge: symlink, 210 char name (forces a CE continuation area), deep directory
mkdir -p "$T/rr/dir1/dir2/dir3/dir4/dir5"
printf 'hello rock ridge\n' > "$T/rr/hello.txt"
LONG="$(printf 'long_name_%.0s' $(seq 1 21))"   # 210 chars
printf 'long\n' > "$T/rr/${LONG}.txt"
printf 'deep\n' > "$T/rr/dir1/dir2/dir3/dir4/dir5/deep.txt"
ln -s hello.txt "$T/rr/link_to_hello"
rm -f rockridge.iso
xorriso -as mkisofs -R -V ROCKRIDGE -o rockridge.iso "$T/rr" 2>/dev/null

# (b) ISO 9660:1999 Enhanced Volume Descriptor, no Joliet
mkdir -p "$T/l4/subdir"
printf 'This is a file with a long name\n' > "$T/l4/a_rather_long_file_name_for_level_four.txt"
printf 'nested\n' > "$T/l4/subdir/nested.dat"
rm -f iso1999.iso
genisoimage -quiet -iso-level 4 -V ISO1999 -o iso1999.iso "$T/l4"

# (c) Joliet with CJK volume id and names
mkdir -p "$T/cjk/ディレクトリ"
printf 'chinese\n' > "$T/cjk/中文文件.txt"
printf 'japanese\n' > "$T/cjk/日本語ファイル.txt"
printf 'nested\n' > "$T/cjk/ディレクトリ/ネスト.txt"
rm -f joliet_cjk.iso
genisoimage -quiet -J -joliet-long -input-charset utf-8 -V 'ボリューム中文' -o joliet_cjk.iso "$T/cjk"

# (d) plain ISO 9660 level 1, files without extension
mkdir -p "$T/noext"
printf 'readme\n' > "$T/noext/README"
printf 'license text\n' > "$T/noext/LICENSE"
printf 'data\n' > "$T/noext/data.txt"
rm -f no_extension.iso
genisoimage -quiet -iso-level 1 -V NOEXT -o no_extension.iso "$T/noext"

rm -rf "$T"
ls -l ./*.iso
