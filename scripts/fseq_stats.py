#!/usr/bin/env python3
"""
fpp-SETIQ — per-sequence stats from a rendered .fseq.

Runs ON the FPP box, which has the render but NOT the xLights layout
(rgbeffects.xml / networks.xml live in the operator's xLights folder and
are uploaded into Studio IQ). So this only computes what the .fseq alone
supports, and hands the raw material for prop attribution up to the cloud:

    {
      "cues":   int,                 # significant per-channel changes (index)
      "colors": ["#rrggbb", ...],    # top-3 most-used colors, most-used first
      "activity": [[start, len, w]]  # run-length lit-channel ranges + weight
    }

Studio IQ reconciles `activity` against the uploaded layout's model→channel
ranges to derive props + fave prop, order-independently, once both land.

cues + colors + activity all come from the render alone. Frames are
sampled; numpy is used when present (fast on a Pi), with a pure-stdlib
fallback. none/zlib are stdlib; zstd needs the zstandard module or the
zstd CLI, else frame stats are skipped (emits cleanly, never guesses).

Usage: fseq_stats.py --fseq PATH [--samples N]
"""

import argparse
import json
import mmap
import struct
import sys
import zlib

LIT = 16          # a channel at/above this is "on"
CHANGE = 24       # per-channel delta that counts as a lighting change
DEFAULT_SAMPLES = 200
COLOR_BITS = 4    # quantize each RGB component to this many high bits
GAP = 3           # merge lit runs separated by <= this many dark channels
MAX_RUNS = 4000   # cap the activity payload (keep the most-active runs)


class FseqError(Exception):
    pass


# ── decompression ─────────────────────────────────────────────────────


def _zstd_decompress(data):
    try:
        import zstandard  # type: ignore

        return zstandard.ZstdDecompressor().decompress(data)
    except ImportError:
        pass
    except Exception:
        try:
            import io
            import zstandard  # type: ignore

            with zstandard.ZstdDecompressor().stream_reader(io.BytesIO(data)) as r:
                return r.read()
        except Exception:
            pass
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
        return zlib.decompress(data, -15)


# ── .fseq reader (v2.x, magic "PSEQ") ─────────────────────────────────


class Fseq:
    def __init__(self, path):
        # Memory-map instead of slurping the whole file: we only sample a
        # few hundred frames, so on a large render this touches just the
        # header + the blocks those samples land in (far less I/O and RAM,
        # which also keeps parallel scans memory-safe). Falls back to a
        # plain read for tiny/empty files mmap can't map.
        self._f = open(path, "rb")
        self._mm = None
        try:
            self._mm = mmap.mmap(self._f.fileno(), 0, access=mmap.ACCESS_READ)
            self.buf = self._mm
        except (ValueError, OSError):
            self.buf = self._f.read()
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
        self.comp = comp_byte & 0x0F
        num_blocks = ((comp_byte >> 4) << 8) | b[21]
        num_sparse = b[22]

        self.blocks = []
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

        self.sparse = []
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

    def close(self):
        if self._mm is not None:
            try:
                self._mm.close()
            except (BufferError, ValueError):
                pass
            self._mm = None
        try:
            self._f.close()
        except OSError:
            pass

    def _decode_block(self, bi):
        _, off, length = self.blocks[bi]
        comp = self.buf[off : off + length]
        if self.comp == 0:
            return comp
        if self.comp == 2:
            return _inflate(comp)
        if self.comp == 1:
            return _zstd_decompress(comp)
        raise FseqError("unsupported compression")

    def frame(self, index):
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
            return bytes(out)
        out = bytearray(self.dense_len)
        packed = 0
        for start, count in self.sparse:
            out[start : start + count] = stored[packed : packed + count]
            packed += count
        return bytes(out)


def sample_indices(frame_count, samples):
    if frame_count <= 0:
        return []
    n = min(samples, frame_count)
    if n <= 1:
        return [0]
    return [round(i * (frame_count - 1) / (n - 1)) for i in range(n)]


# ── shared output helpers ─────────────────────────────────────────────


def _decode_color_key(key):
    shift = 8 - COLOR_BITS
    mid = 1 << (shift - 1) if shift > 0 else 0
    qr = (key >> (2 * COLOR_BITS)) & ((1 << COLOR_BITS) - 1)
    qg = (key >> COLOR_BITS) & ((1 << COLOR_BITS) - 1)
    qb = key & ((1 << COLOR_BITS) - 1)
    rr = min((qr << shift) | mid, 255)
    gg = min((qg << shift) | mid, 255)
    bb = min((qb << shift) | mid, 255)
    return "#%02x%02x%02x" % (rr, gg, bb)


def _runs_from_litcount(litcount, dense_len):
    """RLE contiguous lit channels (merging <=GAP dark gaps) -> [start,len,w]."""
    runs = []
    i = 0
    while i < dense_len:
        if litcount[i] > 0:
            start = i
            last_lit = i
            weight = 0
            while i < dense_len and (litcount[i] > 0 or i - last_lit <= GAP):
                if litcount[i] > 0:
                    last_lit = i
                    weight += int(litcount[i])
                i += 1
            runs.append([start, last_lit - start + 1, weight])
        else:
            i += 1
    if len(runs) > MAX_RUNS:
        runs.sort(key=lambda r: r[2], reverse=True)
        runs = runs[:MAX_RUNS]
        runs.sort(key=lambda r: r[0])
    return runs


# ── numpy fast path ───────────────────────────────────────────────────


def _compute_numpy(np, fq, idxs):
    dense = fq.dense_len
    shift = 8 - COLOR_BITS
    litcount = np.zeros(dense, dtype=np.uint32)
    nkeys = 1 << (3 * COLOR_BITS)
    color_w = np.zeros(nkeys, dtype=np.float64)
    raw_changes = 0
    prev = None
    for i in idxs:
        fr = np.frombuffer(fq.frame(i), dtype=np.uint8)
        litcount[: fr.shape[0]] += (fr >= LIT).astype(np.uint32)
        m = (fr.shape[0] // 3) * 3
        if m:
            tri = fr[:m].reshape(-1, 3).astype(np.uint16)
            lit = tri.max(axis=1) >= LIT
            sel = tri[lit]
            if sel.shape[0]:
                q = (sel >> shift).astype(np.int64)
                keys = (q[:, 0] << (2 * COLOR_BITS)) | (q[:, 1] << COLOR_BITS) | q[:, 2]
                color_w += np.bincount(
                    keys, weights=sel.sum(axis=1), minlength=nkeys
                )
        if prev is not None:
            n = min(fr.shape[0], prev.shape[0])
            raw_changes += int(
                (np.abs(fr[:n].astype(np.int16) - prev[:n].astype(np.int16)) > CHANGE).sum()
            )
        prev = fr
    top = np.argsort(color_w)[::-1]
    colors = [_decode_color_key(int(k)) for k in top[:3] if color_w[int(k)] > 0]
    return litcount.tolist(), colors, raw_changes


# ── pure-stdlib fallback ──────────────────────────────────────────────


def _compute_pure(fq, idxs):
    dense = fq.dense_len
    shift = 8 - COLOR_BITS
    litcount = [0] * dense
    color_w = {}
    raw_changes = 0
    prev = None
    for i in idxs:
        fr = fq.frame(i)
        n = min(dense, len(fr))
        for c in range(n):
            if fr[c] >= LIT:
                litcount[c] += 1
        for c in range(0, n - 2, 3):
            r, g, b = fr[c], fr[c + 1], fr[c + 2]
            if r < LIT and g < LIT and b < LIT:
                continue
            key = ((r >> shift) << (2 * COLOR_BITS)) | ((g >> shift) << COLOR_BITS) | (
                b >> shift
            )
            color_w[key] = color_w.get(key, 0) + (r + g + b)
        if prev is not None:
            m = min(len(fr), len(prev))
            for c in range(m):
                if abs(fr[c] - prev[c]) > CHANGE:
                    raw_changes += 1
        prev = fr
    top = sorted(color_w.items(), key=lambda kv: kv[1], reverse=True)[:3]
    colors = [_decode_color_key(k) for k, _ in top]
    return litcount, colors, raw_changes


def compute(fseq_path, samples=DEFAULT_SAMPLES):
    fq = Fseq(fseq_path)
    try:
        idxs = sample_indices(fq.frame_count, samples)
        if not idxs:
            return {}
        try:
            try:
                import numpy as np  # type: ignore

                litcount, colors, raw_changes = _compute_numpy(np, fq, idxs)
            except ImportError:
                litcount, colors, raw_changes = _compute_pure(fq, idxs)
        except FseqError:
            return {}  # couldn't decode frames (e.g. zstd) — skip cleanly

        stride = fq.frame_count / max(1, len(idxs))
        cues = int(round(raw_changes * stride))
        runs = _runs_from_litcount(litcount, fq.dense_len)
        return {"cues": cues, "colors": colors, "activity": runs}
    finally:
        fq.close()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--fseq", required=True)
    ap.add_argument("--samples", type=int, default=DEFAULT_SAMPLES)
    args = ap.parse_args()
    try:
        stats = compute(args.fseq, args.samples)
    except (FseqError, OSError):
        stats = {}
    sys.stdout.write(json.dumps(stats))


if __name__ == "__main__":
    main()
