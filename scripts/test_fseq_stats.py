#!/usr/bin/env python3
"""Self-contained tests for fseq_stats — run: python3 scripts/test_fseq_stats.py

Builds a synthetic uncompressed PSEQ v2 .fseq + matching xLights layout and
asserts the derived cues / colors / props / faveProp. No third-party deps.
"""

import os
import struct
import tempfile

import fseq_stats as fs


def build_fseq(path, channel_count, frames):
    """frames: list of bytes/bytearray, each `channel_count` long."""
    hdr = bytearray(32)
    hdr[0:4] = b"PSEQ"
    struct.pack_into("<H", hdr, 4, 32)  # channel-data offset
    hdr[6] = 0  # minor
    hdr[7] = 2  # major
    struct.pack_into("<H", hdr, 8, 32)  # fixed header length
    struct.pack_into("<I", hdr, 10, channel_count)
    struct.pack_into("<I", hdr, 14, len(frames))
    hdr[18] = 50  # step ms
    hdr[20] = 0  # compression none, 0 blocks
    hdr[22] = 0  # 0 sparse ranges
    with open(path, "wb") as f:
        f.write(hdr)
        for fr in frames:
            assert len(fr) == channel_count
            f.write(bytes(fr))


def approx(name, got, want):
    assert got == want, f"{name}: got {got!r}, want {want!r}"


def main():
    C = 6  # Tree = ch0-2 (RGB), Arch = ch3-5 (RGB)
    frames = []
    for i in range(10):
        fr = bytearray(C)
        # Tree red, toggling intensity each frame → lit every frame + changes.
        fr[0] = 200 if i % 2 == 0 else 160
        # Arch blue, only first 3 frames.
        if i < 3:
            fr[5] = 200
        frames.append(fr)

    with tempfile.TemporaryDirectory() as d:
        fseq = os.path.join(d, "Song.fseq")
        build_fseq(fseq, C, frames)

        # ── .fseq-only (cues + colors) ──────────────────────────────
        s = fs.compute(fseq)
        assert s.get("cues", 0) >= 9, f"cues too low: {s}"
        assert s.get("colors"), f"no colors: {s}"
        # Dominant color is red (Tree); first swatch is red-heavy.
        top = s["colors"][0]
        r = int(top[1:3], 16)
        g = int(top[3:5], 16)
        b = int(top[5:7], 16)
        assert r > 150 and g < 40 and b < 40, f"top color not red: {top}"
        assert "props" not in s, "props should be absent without layout"
        print("ok: cues+colors from .fseq alone ->", s)

        # ── with layout (props + faveProp) ──────────────────────────
        net = '<Networks><Controller Name="Main" Channels="6"/></Networks>'
        rgb = (
            "<xrgb><models>"
            '<model name="Tree" StartChannel="1" StringType="RGB Nodes"/>'
            '<model name="Arch" StartChannel="4" StringType="RGB Nodes"/>'
            "</models></xrgb>"
        )
        s2 = fs.compute(fseq, rgb, net)
        approx("props", s2.get("props"), 2)
        approx("faveProp", s2.get("faveProp"), "Tree")  # lit every frame
        print("ok: props+faveProp from .fseq+layout ->", s2)

        # ── resolver units ──────────────────────────────────────────
        bases, _ = fs._controller_bases(net)
        approx("base Main", bases.get("Main"), 0)
        approx("resolve bare '4'", fs._resolve_start("4", bases), 3)
        approx("resolve !Main:4", fs._resolve_start("!Main:4", bases), 3)
        approx("resolve junk", fs._resolve_start("!Nope:port:1", bases), None)
        print("ok: channel resolver")

    print("\nALL PASS")


if __name__ == "__main__":
    main()
