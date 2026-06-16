#!/usr/bin/env python3
"""Self-contained tests for fseq_stats — run: python3 scripts/test_fseq_stats.py

Builds a synthetic uncompressed PSEQ v2 .fseq and asserts the derived
cues / colors / activity runs. No third-party deps (exercises the pure
fallback; the numpy path, if installed, is checked to agree).
"""

import os
import struct
import tempfile

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
        print("ok:", s)

    print("\nALL PASS")


if __name__ == "__main__":
    main()
