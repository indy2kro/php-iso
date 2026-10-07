#!/usr/bin/env bash
# Regenerates the real-world fixtures and their manifests.
#
# Every image is built from a source tree with an external tool (xorriso, genisoimage, mkudffs + the Linux
# UDF driver, pycdlib). fixtures/manifests/<image>.<volume>.sha256 lists the SHA-256 of every regular file
# of that source tree, so the smoke tests (tests/smoke/run.sh) can check our extraction against data that
# does not come from this library. <volume> is the isotool --volume value (default: no --volume), "boot"
# is the El Torito default boot image.
#
# Usage: fixtures/build-fixtures.sh [image...]            (through Docker, debian:stable-slim, privileged
#                                                           for the UDF loop mount; no image = all of them)
#        fixtures/build-fixtures.sh --inside [image...]   (inside a container with the tools installed)
#
# Rebuilding an image changes its dates (and the content of the random binary files), so only rebuild the
# images you need and regenerate the smoke expected outputs afterwards (UPDATE=1 tests/smoke/run.sh).
# MANIFESTS_ONLY="image..." refreshes the manifests of those images without replacing the .iso files.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ALL="rockridge iso1999 joliet_cjk no_extension rr_joliet rr_relocated eltorito udf201 udf_bridge pycdlib_udf260"

if [ "${1:-}" != "--inside" ]; then
    IMAGES="${*:-$ALL}"
    # No bind mount (Docker Desktop file sharing may be restricted): copy in / out with docker cp.
    NAME="php-iso-fixtures-$$"
    export MSYS_NO_PATHCONV=1
    docker create --privileged --name "$NAME" debian:stable-slim sleep 1200 >/dev/null
    trap 'docker rm -f "$NAME" >/dev/null 2>&1 || true' EXIT
    docker start "$NAME" >/dev/null
    docker exec "$NAME" mkdir -p /work
    cd "$HERE"   # relative paths: a Windows drive letter would be parsed as host:container by docker cp
    docker cp build-fixtures.sh "$NAME:/work/build-fixtures.sh"
    # shellcheck disable=SC2086 # IMAGES is a list
    docker exec -e DEBIAN_FRONTEND=noninteractive "$NAME" bash -c "apt-get update -qq && apt-get install -y -qq xorriso genisoimage udftools python3-pycdlib >/dev/null && bash /work/build-fixtures.sh --inside $IMAGES"
    mkdir -p manifests
    for f in $IMAGES; do
        # MANIFESTS_ONLY="a b": rebuild those to refresh their manifests but keep the committed images
        case " ${MANIFESTS_ONLY:-} " in
            *" $f "*) ;;
            *) docker cp "$NAME:/work/$f.iso" "./$f.iso" ;;
        esac
        docker exec "$NAME" bash -c "cd /work/manifests && ls $f.*.sha256" | while read -r m; do
            docker cp "$NAME:/work/manifests/$m" "./manifests/$m"
        done
    done
    exit 0
fi

shift
IMAGES="${*:-$ALL}"
cd /work
mkdir -p manifests
export SOURCE_DATE_EPOCH=1700000000
export LANG=C.UTF-8 LC_ALL=C.UTF-8
T="$(mktemp -d)"

wanted() {
    case " $IMAGES " in *" $1 "*) return 0 ;; *) return 1 ;; esac
}

# manifest <source dir> <manifest name> [upper]: "sha256  path" of every regular file, sorted by path bytes
manifest() {
    python3 - "$1" "manifests/$2.sha256" "${3:-}" <<'PY'
import hashlib, os, sys
root, out, mode = sys.argv[1], sys.argv[2], sys.argv[3]
lines = []
for d, _, files in os.walk(root):
    for f in files:
        p = os.path.join(d, f)
        if os.path.islink(p) or not os.path.isfile(p):
            continue
        rel = os.path.relpath(p, root).replace(os.sep, '/')
        if mode == 'upper':
            rel = rel.upper()
        lines.append((rel.encode(), hashlib.sha256(open(p, 'rb').read()).hexdigest() + '  ' + rel))
with open(out, 'w', encoding='utf-8', newline='\n') as fh:
    fh.write(''.join(l + '\n' for _, l in sorted(lines)))
PY
}

# deterministic pseudo random bytes: binary_file <path> <bytes> <seed>
binary_file() {
    python3 -c "import random,sys; random.seed(int(sys.argv[3])); open(sys.argv[1],'wb').write(bytes(random.getrandbits(8) for _ in range(int(sys.argv[2]))))" "$1" "$2" "$3"
}

# a tree with the cases every writer should handle; used by several images
common_tree() {
    local d=$1
    mkdir -p "$d/many" "$d/empty_dir" "$d/deep/l2/l3/l4/l5/l6/l7/l8/l9/l10" "$d/names"
    printf 'top level file\n' > "$d/readme.txt"
    : > "$d/empty_file.txt"
    binary_file "$d/binary.bin" 70000 1
    for i in $(seq -w 1 300); do printf 'file %s\n' "$i" > "$d/many/f$i.txt"; done   # directory over several sectors
    printf 'deep\n' > "$d/deep/l2/l3/l4/l5/l6/l7/l8/l9/l10/deep.txt"
    printf 'spaces\n' > "$d/names/with spaces.txt"
    printf 'symbols\n' > "$d/names/a-b_c+d=e,f.txt"
    printf 'unicode\n' > "$d/names/ünïcödé ñ.txt"
    printf 'dot\n' > "$d/names/.hidden"
    printf 'no extension\n' > "$d/names/NOEXT"
}

# (a) Rock Ridge: symlink, 210 char name (forces a CE continuation area), deep directory
if wanted rockridge; then
    mkdir -p "$T/rr/dir1/dir2/dir3/dir4/dir5"
    printf 'hello rock ridge\n' > "$T/rr/hello.txt"
    LONG="$(printf 'long_name_%.0s' $(seq 1 21))"   # 210 chars
    printf 'long\n' > "$T/rr/${LONG}.txt"
    printf 'deep\n' > "$T/rr/dir1/dir2/dir3/dir4/dir5/deep.txt"
    ln -s hello.txt "$T/rr/link_to_hello"
    rm -f rockridge.iso
    xorriso -as mkisofs -R -V ROCKRIDGE -o rockridge.iso "$T/rr" 2>/dev/null
    manifest "$T/rr" rockridge.default
fi

# (b) ISO 9660:1999 Enhanced Volume Descriptor, no Joliet
if wanted iso1999; then
    mkdir -p "$T/l4/subdir"
    printf 'This is a file with a long name\n' > "$T/l4/a_rather_long_file_name_for_level_four.txt"
    printf 'nested\n' > "$T/l4/subdir/nested.dat"
    rm -f iso1999.iso
    genisoimage -quiet -iso-level 4 -V ISO1999 -o iso1999.iso "$T/l4"
    manifest "$T/l4" iso1999.default
fi

# (c) Joliet with CJK volume id and names
if wanted joliet_cjk; then
    mkdir -p "$T/cjk/ディレクトリ"
    printf 'chinese\n' > "$T/cjk/中文文件.txt"
    printf 'japanese\n' > "$T/cjk/日本語ファイル.txt"
    printf 'nested\n' > "$T/cjk/ディレクトリ/ネスト.txt"
    rm -f joliet_cjk.iso
    genisoimage -quiet -J -joliet-long -input-charset utf-8 -V 'ボリューム中文' -o joliet_cjk.iso "$T/cjk"
    manifest "$T/cjk" joliet_cjk.default
    manifest "$T/cjk" joliet_cjk.joliet
fi

# (d) plain ISO 9660 level 1, files without extension (level 1 names are upper case)
if wanted no_extension; then
    mkdir -p "$T/noext"
    printf 'readme\n' > "$T/noext/README"
    printf 'license text\n' > "$T/noext/LICENSE"
    printf 'data\n' > "$T/noext/data.txt"
    rm -f no_extension.iso
    genisoimage -quiet -iso-level 1 -V NOEXT -o no_extension.iso "$T/noext"
    manifest "$T/noext" no_extension.default upper
fi

# (e) Rock Ridge + Joliet (xorriso): large directory, empty file, binary file, deep tree, unusual names, symlink
if wanted rr_joliet; then
    common_tree "$T/rrj"
    ln -s ../readme.txt "$T/rrj/names/link_to_readme"
    rm -f rr_joliet.iso
    xorriso -as mkisofs -R -J -joliet-long -V RR_JOLIET -o rr_joliet.iso "$T/rrj" 2>/dev/null
    manifest "$T/rrj" rr_joliet.default
    manifest "$T/rrj" rr_joliet.primary
    manifest "$T/rrj" rr_joliet.joliet
fi

# (f) genisoimage Rock Ridge with directories deeper than 8 levels: relocated to rr_moved (CL / RE / PL entries)
if wanted rr_relocated; then
    mkdir -p "$T/reloc/a/b/c/d/e/f/g/h/i/j"
    printf 'relocated\n' > "$T/reloc/a/b/c/d/e/f/g/h/i/j/deep.txt"
    printf 'level eight\n' > "$T/reloc/a/b/c/d/e/f/g/h/eight.txt"
    printf 'top\n' > "$T/reloc/top.txt"
    rm -f rr_relocated.iso
    genisoimage -quiet -R -V RELOCATED -o rr_relocated.iso "$T/reloc"
    manifest "$T/reloc" rr_relocated.primary
fi

# (g) El Torito: BIOS no emulation boot image (4 virtual sectors loaded) and an EFI image in a second section
if wanted eltorito; then
    mkdir -p "$T/boot/boot"
    binary_file "$T/boot/boot/bios.bin" 8192 2
    binary_file "$T/boot/boot/efi.img" 16384 3
    printf 'bootable\n' > "$T/boot/readme.txt"
    rm -f eltorito.iso
    xorriso -as mkisofs -R -J -V ELTORITO \
        -c boot/boot.cat \
        -b boot/bios.bin -no-emul-boot -boot-load-size 4 \
        -eltorito-alt-boot -e boot/efi.img -no-emul-boot \
        -o eltorito.iso "$T/boot" 2>/dev/null
    # the boot catalog is a file of the image that is not in the source tree: take it from xorriso's own extraction
    xorriso -osirrox on -indev eltorito.iso -extract /boot/boot.cat "$T/boot/boot/boot.cat" 2>/dev/null
    manifest "$T/boot" eltorito.default
    manifest "$T/boot" eltorito.primary
    manifest "$T/boot" eltorito.joliet
    # the default entry loads 4 x 512 bytes of the BIOS image
    head -c 2048 "$T/boot/boot/bios.bin" | sha256sum | cut -d' ' -f1 > manifests/eltorito.boot.sha256
fi

# (h) pure UDF 2.01 written by the Linux kernel UDF driver (mkudffs + loop mount), no ISO 9660 at all
if wanted udf201; then
    common_tree "$T/udf"
    rm -f udf201.iso
    truncate -s 3M udf201.iso
    mkudffs --media-type=hd --udfrev=0x0201 --blocksize=2048 --label=UDF201 udf201.iso >/dev/null
    mkdir -p /mnt/udf201
    mount -o loop udf201.iso /mnt/udf201
    cp -a "$T/udf/." /mnt/udf201/
    umount /mnt/udf201
    manifest "$T/udf" udf201.default
    manifest "$T/udf" udf201.udf
fi

# (i) genisoimage UDF 1.02 bridge with Joliet and Rock Ridge (three trees for the same content)
if wanted udf_bridge; then
    mkdir -p "$T/bridge/docs/sub"
    printf 'bridge readme\n' > "$T/bridge/readme.txt"
    printf 'nested doc\n' > "$T/bridge/docs/sub/doc.txt"
    binary_file "$T/bridge/docs/data.bin" 5000 4
    rm -f udf_bridge.iso
    genisoimage -quiet -udf -J -R -V BRIDGE -o udf_bridge.iso "$T/bridge"
    manifest "$T/bridge" udf_bridge.udf
    manifest "$T/bridge" udf_bridge.joliet
    manifest "$T/bridge" udf_bridge.primary
fi

# (j) pycdlib: UDF 2.60 + Joliet + Rock Ridge written by an independent implementation
if wanted pycdlib_udf260; then
    common_tree "$T/py"
    rm -f pycdlib_udf260.iso
    python3 - "$T/py" pycdlib_udf260.iso <<'PY'
import os, sys, pycdlib
root, out = sys.argv[1], sys.argv[2]
iso = pycdlib.PyCdlib()
iso.new(interchange_level=3, udf='2.60', joliet=3, rock_ridge='1.09', vol_ident='PYCDLIB')
counter = [0]
def iso_name(is_dir):
    counter[0] += 1
    return ('D%05d' % counter[0]) if is_dir else ('F%05d.;1' % counter[0])
def walk(src, iso_dir, rel):
    for name in sorted(os.listdir(src)):
        path = os.path.join(src, name)
        child = rel + '/' + name
        target = iso_dir.rstrip('/') + '/' + iso_name(os.path.isdir(path))
        if os.path.isdir(path):
            iso.add_directory(target, rr_name=name, joliet_path=child, udf_path=child)
            walk(path, target, child)
        else:
            iso.add_file(path, target, rr_name=name, joliet_path=child, udf_path=child)
walk(root, '/', '')
iso.write(out)
iso.close()
PY
    manifest "$T/py" pycdlib_udf260.udf
    manifest "$T/py" pycdlib_udf260.joliet
    manifest "$T/py" pycdlib_udf260.primary
fi

rm -rf "$T"
ls -l ./*.iso manifests
