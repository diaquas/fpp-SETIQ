#!/usr/bin/env python3
"""Self-contained tests for fseq_stats — run: python3 scripts/test_fseq_stats.py

Builds synthetic PSEQ v2 .fseq files and asserts the derived
cues / colors / activity runs. Covers the uncompressed, zlib and zstd
compression paths (real renders are compressed — usually zstd), checks that
the numpy fast path agrees with the pure fallback, and that an undecodable
render emits an explicit error marker rather than a silent empty result.
zlib is stdlib; the zstd round-trip is skipped when no zstd encoder is on
the box, but the "no decoder" error path is still exercised.
"""

import os
import shutil
import struct
import subprocess
import tempfile
import zlib

import fseq_stats as fs


def build_fseq(path, channel_count, frames):
    hdr = bytearray(32)
    hdr[0:4] = b"PSEQ"
    struct.pack_into("<H", hdr, 4, 32)
    hdr[6] = 0
    hdr[7] = 2
    struct.pack_into("<H", hdr, 8, 32)
    struct.pack_into("<I", hdr, 10, channel_count)
    struct.pack_into("<I", hdr, 14, len(frames))
    hdr[18] = 50
    hdr[20] = 0
    hdr[22] = 0
    with open(path, "wb") as f:
        f.write(hdr)
        for fr in frames:
            assert len(fr) == channel_count
            f.write(bytes(fr))


def _zstd_encode(raw):
    """zstd-compress, or None if no encoder is available on this box."""
    try:
        import zstandard  # type: ignore

        return zstandard.ZstdCompressor().compress(raw)
    except ImportError:
        pass
    if shutil.which("zstd"):
        return subprocess.run(
            ["zstd", "-q", "-c"], input=raw, stdout=subprocess.PIPE, check=True
        ).stdout
    return None


def build_blocked(path, channel_count, frames, comp, body=None):
    """Single-block PSEQ v2. comp: 0 none, 1 zstd, 2 zlib. `body` overrides the
    compressed payload (used to inject an undecodable block for the error path)."""
    raw = b"".join(bytes(fr) for fr in frames)
    if body is None:
        if comp == 2:
            body = zlib.compress(raw)
        elif comp == 1:
            body = _zstd_encode(raw)
            if body is None:
                return False  # no encoder — caller skips the round-trip
        else:
            body = raw
    data_off = 32 + 8  # one 8-byte block index entry, no sparse ranges
    hdr = bytearray(data_off)
    hdr[0:4] = b"PSEQ"
    struct.pack_into("<H", hdr, 4, data_off)
    hdr[6] = 0
    hdr[7] = 2
    struct.pack_into("<H", hdr, 8, data_off)
    struct.pack_into("<I", hdr, 10, channel_count)
    struct.pack_into("<I", hdr, 14, len(frames))
    hdr[18] = 50
    hdr[20] = comp & 0x0F
    hdr[21] = 1  # one block (low byte of the block count)
    hdr[22] = 0
    struct.pack_into("<I", hdr, 32, 0)  # block starts at frame 0
    struct.pack_into("<I", hdr, 36, len(body))  # block length
    with open(path, "wb") as f:
        f.write(hdr)
        f.write(body)
    return True


def check(name, cond):
    assert cond, f"FAILED: {name}"


def main():
    # ch0-2 = "Tree" (red, lit every frame, toggling → changes);
    # ch3-5 = "Arch" (blue, lit only first 3 of 10 frames).
    C = 6
    frames = []
    for i in range(10):
        fr = bytearray(C)
        fr[0] = 200 if i % 2 == 0 else 160
        if i < 3:
            fr[5] = 200
        frames.append(fr)

    with tempfile.TemporaryDirectory() as d:
        path = os.path.join(d, "Song.fseq")
        build_fseq(path, C, frames)
        s = fs.compute(path)

        check("cues present", s.get("cues", 0) >= 9)
        check("colors present", bool(s.get("colors")))
        top = s["colors"][0]
        r, g, b = int(top[1:3], 16), int(top[3:5], 16), int(top[5:7], 16)
        check("top color is red", r > 150 and g < 40 and b < 40)

        runs = s.get("activity")
        check("activity present", isinstance(runs, list) and len(runs) >= 1)
        # Tree (ch0) lit all 10 frames; Arch (ch5) lit 3. The 4-dark-channel
        # gap (1..4) exceeds GAP=3, so they stay as two runs: [0,1,10] + [5,1,3].
        lit_channels = set()
        for start, length, w in runs:
            check("run weight positive", w > 0)
            for c in range(start, start + length):
                lit_channels.add(c)
        check("ch0 (Tree) covered", 0 in lit_channels)
        check("ch5 (Arch) covered", 5 in lit_channels)
        # Total weight ~= lit-frame-count sum: Tree 10 + Arch 3 = 13.
        total_w = sum(w for _, _, w in runs)
        check("total weight ~= lit frames", 10 <= total_w <= 16)

        # Key moments: this 10-frame fixture is one ~1s window, so one moment
        # at t=0 carrying the window's colors + its lit-channel runs.
        moments = s.get("moments")
        check("moments present", isinstance(moments, list) and len(moments) >= 1)
        m0 = moments[0]
        check("moment t_ms is 0", m0.get("t_ms") == 0)
        check("moment has colors", bool(m0.get("colors")))
        mr = m0.get("activity")
        check("moment activity present", isinstance(mr, list) and len(mr) >= 1)
        moment_channels = set()
        for start, length, w in mr:
            check("moment run weight positive", w > 0)
            for c in range(start, start + length):
                moment_channels.add(c)
        check("moment covers ch0 (Tree)", 0 in moment_channels)
        check("moment covers ch5 (Arch)", 5 in moment_channels)
        print("ok (uncompressed):", s)

        # Compression must not change the derived stats. Real renders are
        # almost always compressed (xLights/FPP default to zstd), so the
        # uncompressed-only fixture above missed the path operators actually
        # hit. zlib is stdlib; zstd round-trips only when an encoder is here.
        zpath = os.path.join(d, "Song.zlib.fseq")
        build_blocked(zpath, C, frames, comp=2)
        sz = fs.compute(zpath)
        check("zlib stats == uncompressed stats", sz == s)
        print("ok (zlib):", sz)

        spath = os.path.join(d, "Song.zstd.fseq")
        if build_blocked(spath, C, frames, comp=1):
            ss = fs.compute(spath)
            check("zstd stats == uncompressed stats", ss == s)
            print("ok (zstd):", ss)
        else:
            print("skip (zstd): no encoder on this box")

        # Undecodable render → explicit error marker, never a silent empty.
        # A zstd-tagged block holding non-zstd bytes can't be decoded whether
        # or not a decoder is installed, so this is deterministic everywhere.
        bad = os.path.join(d, "Song.bad.fseq")
        build_blocked(bad, C, frames, comp=1, body=b"not really zstd data")
        sb = fs.compute(bad)
        check("undecodable render reports an error", isinstance(sb, dict) and "error" in sb)
        check("error result carries no stats", "cues" not in sb)
        print("ok (error marker):", sb)

        # numpy fast path must agree with the pure fallback, channel-for-channel.
        try:
            import numpy as np  # type: ignore
        except ImportError:
            print("skip (numpy agreement): numpy not installed")
        else:
            fq = fs.Fseq(zpath)
            idxs = fs.sample_indices(fq.frame_count, fs.DEFAULT_SAMPLES)
            np_lit, np_colors, np_changes, np_fl = fs._compute_numpy(np, fq, idxs)
            fq.close()
            fq = fs.Fseq(zpath)
            pu_lit, pu_colors, pu_changes, pu_fl = fs._compute_pure(fq, idxs)
            fq.close()
            check("numpy/pure litcount agree", np_lit == pu_lit)
            check("numpy/pure colors agree", np_colors == pu_colors)
            check("numpy/pure change count agrees", np_changes == pu_changes)
            check("numpy/pure per-frame lit counts agree", np_fl == pu_fl)
            print("ok (numpy == pure):", np_colors)

    print("\nALL PASS")


if __name__ == "__main__":
    main()
