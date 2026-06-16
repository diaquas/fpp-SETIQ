#!/usr/bin/env python3
"""
fpp-SETIQ — per-sequence stats from a rendered .fseq (+ optional layout).

Runs ON the FPP box. Given one .fseq (and, when available, the xLights
rgbeffects.xml + networks.xml), it SAMPLES frames and emits:

    {"cues": int, "props": int, "faveProp": str, "colors": ["#rrggbb", ...]}

- cues   : significant per-channel changes across the show (the honest
           ".fseq" analog to xLights effect count — there is no effect
           metadata in a render, so this is a change/activity index).
- colors : the top-3 most-used colors (RGB), most-used first.
- props  : distinct props lit (needs the layout channel map).
- faveProp: the prop lit across the most frames ("fave prop").

cues + colors come from the .fseq alone and are always emitted. props +
faveProp need the layout and are best-effort: omitted when the layout
isn't present or too few models resolve to a channel, so the cloud never
stores a misleading value (it coalesces — missing keys keep prior data).

Pure stdlib for none/zlib .fseq; zstd is decoded via the `zstandard`
module if present, else the `zstd` CLI, else that sequence's frame stats
are skipped (still emits nothing rather than guessing).

Usage:
    fseq_stats.py --fseq PATH [--rgb PATH] [--net PATH] [--samples N]
"""

import argparse
import json
import struct
import sys
import zlib

LIT = 16          # a channel at/above this is "on"
CHANGE = 24       # per-channel delta that counts as a lighting change
DEFAULT_SAMPLES = 240
COLOR_BITS = 4    # quantize each RGB component to this many high bits


# ── .fseq reader (v2.x, magic "PSEQ") ─────────────────────────────────


class FseqError(Exception):
    pass


def _zstd_decompress(data):
    """Best-effort zstd: zstandard module first, then the zstd CLI."""
    try:
        import zstandard  # type: ignore

        return zstandard.ZstdDecompressor().decompress(data)
    except ImportError:
        pass
    except Exception:
        # Streaming fallback for frames without a stored content size.
        try:
            import zstandard  # type: ignore
            import io

            dctx = zstandard.ZstdDecompressor()
            with dctx.stream_reader(io.BytesIO(data)) as r:
                return r.read()
        except Exception:
            pass
    # CLI fallback.
    try:
        import subprocess

        p = subprocess.run(
            ["zstd", "-d", "-c"], input=data, stdout=subprocess.PIPE, check=True
        )
        return p.stdout
    except Exception as e:
        raise FseqError("no zstd decoder available") from e


def _inflate(data):
    try:
        return zlib.decompress(data)
    except Exception:
        return zlib.decompress(data, -15)  # raw deflate


class Fseq:
    def __init__(self, path):
        with open(path, "rb") as f:
            self.buf = f.read()
        b = self.buf
        if len(b) < 8 or b[0:4] != b"PSEQ":
            raise FseqError("not a v2 PSEQ file")
        if len(b) < 32:
            raise FseqError("short header")
        self.data_off = struct.unpack_from("<H", b, 4)[0]
        self.channel_count = struct.unpack_from("<I", b, 10)[0]
        self.frame_count = struct.unpack_from("<I", b, 14)[0]
        self.step_ms = b[18] or 50
        comp_byte = b[20]
        self.comp = comp_byte & 0x0F  # 0 none, 1 zstd, 2 zlib
        num_blocks = ((comp_byte >> 4) << 8) | b[21]
        num_sparse = b[22]

        self.blocks = []  # (first_frame, file_offset, length)
        cursor = self.data_off
        for i in range(num_blocks):
            pos = 32 + i * 8
            if pos + 8 > len(b):
                break
            first = struct.unpack_from("<I", b, pos)[0]
            length = struct.unpack_from("<I", b, pos + 4)[0]
            if length == 0:
                continue
            self.blocks.append((first, cursor, length))
            cursor += length

        self.sparse = []  # (start, count)
        sp = 32 + num_blocks * 8
        for i in range(num_sparse):
            p = sp + i * 6
            if p + 6 > len(b):
                break
            start = b[p] | (b[p + 1] << 8) | (b[p + 2] << 16)
            count = b[p + 3] | (b[p + 4] << 8) | (b[p + 5] << 16)
            if count > 0:
                self.sparse.append((start, count))

        if self.sparse:
            self.stored_size = sum(c for _, c in self.sparse)
            self.dense_len = max(s + c for s, c in self.sparse)
        else:
            self.stored_size = self.channel_count
            self.dense_len = self.channel_count

        self._cache_bi = -1
        self._cache_raw = None

    def _decode_block(self, bi):
        first, off, length = self.blocks[bi]
        comp = self.buf[off : off + length]
        if self.comp == 0:
            return comp
        if self.comp == 2:
            return _inflate(comp)
        if self.comp == 1:
            return _zstd_decompress(comp)
        raise FseqError("unsupported compression")

    def frame(self, index):
        """Dense (absolute-channel-indexed) bytearray for a frame."""
        if self.blocks:
            bi = len(self.blocks) - 1
            for i in range(len(self.blocks)):
                nxt = self.blocks[i + 1][0] if i + 1 < len(self.blocks) else None
                if nxt is None or index < nxt:
                    bi = i
                    break
            if bi != self._cache_bi:
                self._cache_raw = self._decode_block(bi)
                self._cache_bi = bi
            within = index - self.blocks[bi][0]
            stored = self._cache_raw[
                within * self.stored_size : (within + 1) * self.stored_size
            ]
        else:
            off = self.data_off + index * self.stored_size
            stored = self.buf[off : off + self.stored_size]

        if not self.sparse:
            out = bytearray(self.dense_len)
            out[: len(stored)] = stored[: self.dense_len]
            return out
        out = bytearray(self.dense_len)
        packed = 0
        for start, count in self.sparse:
            out[start : start + count] = stored[packed : packed + count]
            packed += count
        return out


def sample_indices(frame_count, samples):
    if frame_count <= 0:
        return []
    n = min(samples, frame_count)
    if n <= 1:
        return [0]
    return [round(i * (frame_count - 1) / (n - 1)) for i in range(n)]


# ── layout channel map (best-effort port of resolveModelChannelRanges) ─


def _controller_bases(net_xml):
    """name -> absolute base channel, in document order."""
    import xml.etree.ElementTree as ET

    try:
        root = ET.fromstring(net_xml)
    except Exception:
        return {}, []
    bases = {}
    controllers = []
    acc = 0
    # xLights stores controllers as <Controller> (newer) or <network> (older).
    nodes = root.findall(".//Controller")
    if not nodes:
        nodes = root.findall(".//network")
    for c in nodes:
        name = c.get("Name") or c.get("name") or c.get("ControllerName") or ""
        chans = _controller_channels(c)
        if name:
            bases[name] = acc
            controllers.append((name, acc, chans))
        acc += chans
    return bases, controllers


def _controller_channels(c):
    # Direct attribute forms.
    for attr in ("Channels", "MaxChannels", "channels"):
        v = c.get(attr)
        if v and v.isdigit():
            return int(v)
    # Universe-based: count × channels-per-universe.
    univ = c.get("NumUniverses") or c.get("Universes")
    cpu = c.get("ChannelsPerUniverse") or c.get("UniverseSize")
    if univ and univ.isdigit() and cpu and cpu.isdigit():
        return int(univ) * int(cpu)
    # Sum child <network>/<universe> MaxChannels.
    total = 0
    for ch in list(c):
        for attr in ("MaxChannels", "Channels"):
            v = ch.get(attr)
            if v and v.isdigit():
                total += int(v)
                break
    return total


def _resolve_start(start, bases):
    if not start:
        return None
    s = start.strip()
    if s.startswith("!"):
        # !Controller:channel  (1-based within controller)
        body = s[1:]
        if body.count(":") == 1:
            name, _, ch = body.partition(":")
            if ch.isdigit() and name in bases:
                return bases[name] + int(ch) - 1
        return None
    if s.isdigit():  # bare absolute, 1-based
        return int(s) - 1
    return None


def model_ranges(rgb_xml, net_xml):
    """[(name, start, end_exclusive)] via contiguous bounding by next start.

    Avoids needing per-model pixel geometry: sort resolved starts and let
    each model own channels up to the next model's start. Approximate, but
    good enough to attribute a lit channel to a prop. Returns ([], total)
    when too little resolves to trust.
    """
    import xml.etree.ElementTree as ET

    bases, _ = _controller_bases(net_xml)
    try:
        root = ET.fromstring(rgb_xml)
    except Exception:
        return [], 0
    models = root.findall(".//models/model")
    if not models:
        models = root.findall(".//model")
    resolved = []
    total_models = 0
    for m in models:
        name = m.get("name")
        if not name:
            continue
        total_models += 1
        start = _resolve_start(m.get("StartChannel"), bases)
        if start is not None and start >= 0:
            resolved.append((name, start))
    if total_models == 0 or len(resolved) < max(1, total_models // 2):
        return [], total_models  # too little resolved — don't guess props
    resolved.sort(key=lambda x: x[1])
    ranges = []
    for i, (name, start) in enumerate(resolved):
        end = resolved[i + 1][1] if i + 1 < len(resolved) else start + 3
        if end <= start:
            end = start + 3
        ranges.append((name, start, end))
    return ranges, total_models


# ── stats ─────────────────────────────────────────────────────────────


def compute(fseq_path, rgb_xml=None, net_xml=None, samples=DEFAULT_SAMPLES):
    fq = Fseq(fseq_path)
    idxs = sample_indices(fq.frame_count, samples)
    if not idxs:
        return {}

    frames = []
    try:
        for i in idxs:
            frames.append(fq.frame(i))
    except FseqError:
        # Couldn't decode frames (e.g. zstd without a decoder) — no frame
        # stats, but we still return cleanly so the sync proceeds.
        return {}

    dense = fq.dense_len
    # Colors: histogram quantized RGB triplets weighted by intensity.
    shift = 8 - COLOR_BITS
    color_w = {}
    for fr in frames:
        n = min(dense, len(fr))
        for c in range(0, n - 2, 3):
            r, g, b = fr[c], fr[c + 1], fr[c + 2]
            if r < LIT and g < LIT and b < LIT:
                continue
            key = ((r >> shift), (g >> shift), (b >> shift))
            color_w[key] = color_w.get(key, 0) + (r + g + b)
    top = sorted(color_w.items(), key=lambda kv: kv[1], reverse=True)[:3]
    colors = []
    mid = 1 << (shift - 1) if shift > 0 else 0
    for (qr, qg, qb), _ in top:
        rr = (qr << shift) | mid
        gg = (qg << shift) | mid
        bb = (qb << shift) | mid
        colors.append("#%02x%02x%02x" % (min(rr, 255), min(gg, 255), min(bb, 255)))

    # Cues: per-channel changes between consecutive sampled frames, scaled
    # to the full frame count (each sample stands in for a stride of frames).
    raw_changes = 0
    for a, b in zip(frames, frames[1:]):
        n = min(len(a), len(b))
        for c in range(n):
            if abs(a[c] - b[c]) > CHANGE:
                raw_changes += 1
    transitions = max(1, len(frames) - 1)
    stride = fq.frame_count / max(1, len(frames))
    # raw_changes is summed over `transitions` sampled gaps; each sampled
    # frame stands in for ~stride real frames, so scale to a full-show index.
    cues = int(round(raw_changes * stride))

    out = {"cues": cues, "colors": colors}

    # Props + fave prop (best-effort, needs the layout).
    if rgb_xml and net_xml:
        ranges, _ = model_ranges(rgb_xml, net_xml)
        if ranges:
            lit_frames = {name: 0 for name, _, _ in ranges}
            for fr in frames:
                n = len(fr)
                for name, start, end in ranges:
                    e = min(end, n)
                    on = False
                    for c in range(start, e):
                        if fr[c] >= LIT:
                            on = True
                            break
                    if on:
                        lit_frames[name] += 1
            used = [(nm, ct) for nm, ct in lit_frames.items() if ct > 0]
            if used:
                used.sort(key=lambda x: x[1], reverse=True)
                out["props"] = len(used)
                out["faveProp"] = used[0][0]
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--fseq", required=True)
    ap.add_argument("--rgb")
    ap.add_argument("--net")
    ap.add_argument("--samples", type=int, default=DEFAULT_SAMPLES)
    args = ap.parse_args()

    rgb = net = None
    try:
        if args.rgb:
            with open(args.rgb, "r", encoding="utf-8", errors="replace") as f:
                rgb = f.read()
        if args.net:
            with open(args.net, "r", encoding="utf-8", errors="replace") as f:
                net = f.read()
    except OSError:
        rgb = net = None

    try:
        stats = compute(args.fseq, rgb, net, args.samples)
    except (FseqError, OSError):
        stats = {}
    sys.stdout.write(json.dumps(stats))


if __name__ == "__main__":
    main()
