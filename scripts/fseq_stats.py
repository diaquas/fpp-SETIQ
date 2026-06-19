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
      "activity": [[start, len, w]], # run-length lit-channel ranges + weight
      "moments": [                   # top key windows for the "hype moment"
        {"t_ms": int, "colors": ["#rrggbb", ...],
         "activity": [[start, len, w], ...]}
      ]
    }

Studio IQ reconciles `activity` against the uploaded layout's model→channel
ranges to derive props + fave prop, order-independently, once both land. Each
moment's per-window `activity` reconciles the same way into that window's
props_lit, so the cloud can build real sequence-moment anchors (timestamp +
colors + prop count) for the AI "watch for this" teaser without the .fseq
ever leaving the box.

cues + colors + activity all come from the render alone. Frames are
sampled; numpy is used when present (fast on a Pi), with a pure-stdlib
fallback. none/zlib are stdlib; zstd needs the zstandard module or the
zstd CLI. If a render can't be decoded (commonly a zstd file on a box with
no decoder), the tool emits an explicit {"error": "..."} marker instead of
a silent {} — so the caller can surface "stats unavailable" and retry the
file later (e.g. once a decoder is installed) rather than caching a blank.

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
MOMENT_BUCKET_MS = 1000  # window size for "key moments"
MAX_MOMENTS = 3          # how many key moments to emit
MOMENT_SPREAD = 3        # keep picked moments >= this many buckets apart
MOMENT_COLORS = 3        # top colors per moment
# Mega-display palette exclusion. The box has no xLights layout, so it can't
# tell which channels are a matrix; it detects dominant whole-canvas blocks by
# SIZE and drops their pixels from the palette, so a matrix running (say) a
# black-and-white video can't define the whole show's colors.
MEGA_COLOR_FRAC = 0.10   # a lit block >= this fraction of the channel space …
MEGA_COLOR_ABS = 6000    # … AND >= this many channels is treated as a mega display
MEGA_BLOCK_GAP = 64      # bridge small dark gaps when measuring a block's extent
COLOR_SAMPLES = 80       # frames re-scanned for the mega-excluded palette


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


def _runs_from_litcount(litcount, dense_len, gap=GAP):
    """RLE contiguous lit channels (merging <=gap dark gaps) -> [start,len,w]."""
    runs = []
    i = 0
    while i < dense_len:
        if litcount[i] > 0:
            start = i
            last_lit = i
            weight = 0
            while i < dense_len and (litcount[i] > 0 or i - last_lit <= gap):
                if litcount[i] > 0:
                    last_lit = i
                    weight += int(litcount[i])
                i += 1
            runs.append([start, last_lit - start + 1, weight])
        else:
            i += 1
    return runs


def _bounded_runs(litcount, dense_len):
    """Lit-channel runs kept under MAX_RUNS by COARSENING (a larger merge gap),
    never by dropping runs.

    The old cap kept the top-MAX_RUNS runs by weight and discarded the rest. But
    one huge model — a P5 matrix can be ~75% of the channel space — fragments
    into thousands of high-weight runs that crowd out every discrete prop's
    lower-weight run, so the cloud saw only the matrix lit and reported ~5 props.
    Coarsening instead grows the dark-gap tolerance until the run count fits:
    every lit channel stays inside some run, so every lit model is still
    represented. A few unlit channels swallowed into a merged span is a far
    better error than silently losing 145 props."""
    gap = GAP
    runs = _runs_from_litcount(litcount, dense_len, gap)
    while len(runs) > MAX_RUNS and gap < dense_len:
        gap *= 8
        runs = _runs_from_litcount(litcount, dense_len, gap)
    if len(runs) > MAX_RUNS:  # pathological fallback — uniform trim by position
        step = len(runs) / MAX_RUNS
        runs = [runs[int(k * step)] for k in range(MAX_RUNS)]
    return runs


def _frame_top_colors(fr, top=MOMENT_COLORS, exclude=()):
    """Top hex colors of a single frame, most-USED first.

    Ranked by how many lit pixels carry each (quantized) color — frequency, not
    brightness. Summing r+g+b instead made white/near-white win every time
    (765 per pixel) over the show's actual saturated colors; counting pixels
    surfaces the colors that genuinely cover the most of the display.

    `exclude` is a list of [start, end) channel ranges (mega-displays) whose
    pixels are skipped, so a matrix's video can't define the moment's colors."""
    shift = 8 - COLOR_BITS
    color_w = {}
    n = (len(fr) // 3) * 3
    keep = None
    if exclude:
        keep = bytearray([1]) * len(fr)
        for s, e in exclude:
            for c in range(max(0, s), min(len(fr), e)):
                keep[c] = 0
    for c in range(0, n, 3):
        r, g, b = fr[c], fr[c + 1], fr[c + 2]
        if r < LIT and g < LIT and b < LIT:
            continue
        if keep is not None and not (keep[c] or keep[c + 1] or keep[c + 2]):
            continue
        key = ((r >> shift) << (2 * COLOR_BITS)) | ((g >> shift) << COLOR_BITS) | (
            b >> shift
        )
        color_w[key] = color_w.get(key, 0) + 1
    ranked = sorted(color_w.items(), key=lambda kv: kv[1], reverse=True)[:top]
    return [_decode_color_key(k) for k, _ in ranked]


def _frame_runs(fr):
    """Lit-channel runs of a single frame, same [start, len, w] shape as the
    aggregate activity so the cloud reconciles a moment's window the same way.
    Bounded the same way too, so a busy matrix frame can't crowd out the props
    in a moment's per-window props_lit."""
    dense = len(fr)
    litcount = [1 if fr[c] >= LIT else 0 for c in range(dense)]
    return _bounded_runs(litcount, dense)


def _pick_moments(fq, frame_lits, step_ms, exclude=()):
    """Pick the top key windows for the hype moment from the sampled frames.

    Buckets sampled frames into MOMENT_BUCKET_MS windows, scores each window by
    its busiest sampled frame (most lit channels), keeps the top MAX_MOMENTS
    spread >= MOMENT_SPREAD buckets apart, then emits each window's timestamp,
    dominant colors and per-window lit-channel runs (for the cloud to reconcile
    into props_lit). Returns [] when nothing is lit."""
    if step_ms <= 0:
        return []
    buckets = {}  # bucket -> (lit, frame index of the busiest sampled frame)
    for idx, lit in frame_lits:
        if lit <= 0:
            continue
        b = (idx * step_ms) // MOMENT_BUCKET_MS
        cur = buckets.get(b)
        if cur is None or lit > cur[0]:
            buckets[b] = (lit, idx)
    if not buckets:
        return []

    scored = sorted(buckets.items(), key=lambda kv: (-kv[1][0], kv[0]))
    picked = []  # (bucket, frame index)
    for b, (lit, idx) in scored:
        if len(picked) >= MAX_MOMENTS:
            break
        if any(abs(pb - b) < MOMENT_SPREAD for pb, _ in picked):
            continue
        picked.append((b, idx))
    picked.sort(key=lambda p: p[0])

    moments = []
    for b, idx in picked:
        fr = fq.frame(idx)
        moments.append(
            {
                # Timestamp the ACTUAL busiest frame the colors + activity come
                # from (idx * step_ms), not the bucket's start second. The cloud
                # carries this t_ms straight into the "watch for this" teaser, so
                # the timecode must land exactly when these colors hit — stamping
                # the bucket start put the callout up to a second early.
                "t_ms": int(idx * step_ms),
                "colors": _frame_top_colors(fr, exclude=exclude),
                "activity": _frame_runs(fr),
            }
        )
    return moments


# ── numpy fast path ───────────────────────────────────────────────────


def _compute_numpy(np, fq, idxs):
    dense = fq.dense_len
    shift = 8 - COLOR_BITS
    litcount = np.zeros(dense, dtype=np.uint32)
    nkeys = 1 << (3 * COLOR_BITS)
    color_w = np.zeros(nkeys, dtype=np.float64)
    raw_changes = 0
    frame_lits = []  # (frame index, lit-channel count) per sampled frame
    prev = None
    for i in idxs:
        fr = np.frombuffer(fq.frame(i), dtype=np.uint8)
        lit_mask = fr >= LIT
        litcount[: fr.shape[0]] += lit_mask.astype(np.uint32)
        frame_lits.append((i, int(lit_mask.sum())))
        m = (fr.shape[0] // 3) * 3
        if m:
            tri = fr[:m].reshape(-1, 3).astype(np.uint16)
            lit = tri.max(axis=1) >= LIT
            sel = tri[lit]
            if sel.shape[0]:
                q = (sel >> shift).astype(np.int64)
                keys = (q[:, 0] << (2 * COLOR_BITS)) | (q[:, 1] << COLOR_BITS) | q[:, 2]
                # Frequency (count of lit pixels), not summed brightness — rank
                # by the colors that cover the most of the display, so the show's
                # actual hues win instead of white/near-white (see _frame_top_colors).
                color_w += np.bincount(keys, minlength=nkeys)
        if prev is not None:
            n = min(fr.shape[0], prev.shape[0])
            raw_changes += int(
                (np.abs(fr[:n].astype(np.int16) - prev[:n].astype(np.int16)) > CHANGE).sum()
            )
        prev = fr
    top = np.argsort(color_w)[::-1]
    colors = [_decode_color_key(int(k)) for k in top[:3] if color_w[int(k)] > 0]
    return litcount.tolist(), colors, raw_changes, frame_lits


# ── pure-stdlib fallback ──────────────────────────────────────────────


def _compute_pure(fq, idxs):
    dense = fq.dense_len
    shift = 8 - COLOR_BITS
    litcount = [0] * dense
    color_w = {}
    raw_changes = 0
    frame_lits = []  # (frame index, lit-channel count) per sampled frame
    prev = None
    for i in idxs:
        fr = fq.frame(i)
        n = min(dense, len(fr))
        fl = 0
        for c in range(n):
            if fr[c] >= LIT:
                litcount[c] += 1
                fl += 1
        frame_lits.append((i, fl))
        for c in range(0, n - 2, 3):
            r, g, b = fr[c], fr[c + 1], fr[c + 2]
            if r < LIT and g < LIT and b < LIT:
                continue
            key = ((r >> shift) << (2 * COLOR_BITS)) | ((g >> shift) << COLOR_BITS) | (
                b >> shift
            )
            # Frequency (lit-pixel count), not summed brightness — see
            # _frame_top_colors for why white/near-white must not auto-win.
            color_w[key] = color_w.get(key, 0) + 1
        if prev is not None:
            m = min(len(fr), len(prev))
            for c in range(m):
                if abs(fr[c] - prev[c]) > CHANGE:
                    raw_changes += 1
        prev = fr
    top = sorted(color_w.items(), key=lambda kv: kv[1], reverse=True)[:3]
    colors = [_decode_color_key(k) for k, _ in top]
    return litcount, colors, raw_changes, frame_lits


# ── mega-display palette exclusion (layout-free, by size) ─────────────


def _dominant_blocks(litcount, dense_len):
    """Channel ranges [(start, end), ...] of dominant whole-canvas elements
    (typically a P5/P10 matrix), detected by SIZE alone — the box has no layout.
    Their pixels are dropped from the palette so a matrix running, say, a
    black-and-white video can't define the whole show's colors. Empty when
    nothing is big enough to dominate."""
    if dense_len <= 0:
        return []
    thresh = max(MEGA_COLOR_ABS, int(dense_len * MEGA_COLOR_FRAC))
    blocks = []
    for start, length, _w in _runs_from_litcount(litcount, dense_len, MEGA_BLOCK_GAP):
        if length >= thresh:
            blocks.append((start, start + length))
    return blocks


def _colors_excluding_numpy(np, fq, idxs, exclude):
    """Top-3 colors over the sampled frames, skipping pixels in the excluded
    (mega-display) channel ranges. Same frequency ranking as _compute_numpy."""
    shift = 8 - COLOR_BITS
    nkeys = 1 << (3 * COLOR_BITS)
    keep = np.ones(fq.dense_len, dtype=bool)
    for s, e in exclude:
        keep[max(0, s):min(fq.dense_len, e)] = False
    color_w = np.zeros(nkeys, dtype=np.float64)
    for i in idxs:
        fr = np.frombuffer(fq.frame(i), dtype=np.uint8)
        m = (fr.shape[0] // 3) * 3
        if not m:
            continue
        tri = fr[:m].reshape(-1, 3).astype(np.uint16)
        kp = keep[:m].reshape(-1, 3).any(axis=1)
        lit = (tri.max(axis=1) >= LIT) & kp
        sel = tri[lit]
        if sel.shape[0]:
            q = (sel >> shift).astype(np.int64)
            keys = (q[:, 0] << (2 * COLOR_BITS)) | (q[:, 1] << COLOR_BITS) | q[:, 2]
            color_w += np.bincount(keys, minlength=nkeys)
    top = np.argsort(color_w)[::-1]
    return [_decode_color_key(int(k)) for k in top[:3] if color_w[int(k)] > 0]


def _colors_excluding_pure(fq, idxs, exclude):
    """Pure-stdlib twin of _colors_excluding_numpy."""
    shift = 8 - COLOR_BITS
    dense = fq.dense_len
    keep = bytearray([1]) * dense
    for s, e in exclude:
        for c in range(max(0, s), min(dense, e)):
            keep[c] = 0
    color_w = {}
    for i in idxs:
        fr = fq.frame(i)
        n = (min(dense, len(fr)) // 3) * 3
        for c in range(0, n, 3):
            r, g, b = fr[c], fr[c + 1], fr[c + 2]
            if r < LIT and g < LIT and b < LIT:
                continue
            if not (keep[c] or keep[c + 1] or keep[c + 2]):
                continue
            key = ((r >> shift) << (2 * COLOR_BITS)) | ((g >> shift) << COLOR_BITS) | (
                b >> shift
            )
            color_w[key] = color_w.get(key, 0) + 1
    top = sorted(color_w.items(), key=lambda kv: kv[1], reverse=True)[:3]
    return [_decode_color_key(k) for k, _ in top]


def compute(fseq_path, samples=DEFAULT_SAMPLES):
    # A scan either succeeds with real stats or returns an explicit
    # {"error": ...} marker — never a silent empty {}. The caller uses the
    # marker to surface "stats unavailable" and to retry (rather than cache)
    # the file, so a box that later gains a zstd decoder self-heals. The most
    # common failure is a zstd-compressed render on a box with no decoder.
    try:
        fq = Fseq(fseq_path)
    except FseqError as e:
        return {"error": str(e) or "not a readable .fseq"}
    try:
        idxs = sample_indices(fq.frame_count, samples)
        if not idxs:
            return {"error": "no frames"}
        try:
            import numpy as np  # type: ignore
        except ImportError:
            np = None
        try:
            if np is not None:
                litcount, colors, raw_changes, frame_lits = _compute_numpy(
                    np, fq, idxs
                )
            else:
                litcount, colors, raw_changes, frame_lits = _compute_pure(fq, idxs)
        except FseqError as e:
            # No decoder for this compression (e.g. zstd), or a bad block.
            return {"error": str(e) or "could not decode frames"}
        except Exception as e:  # corrupt stream, bad compression payload, etc.
            return {"error": "could not decode frames: %s" % type(e).__name__}

        # Mega-display palette exclusion: a giant element (e.g. a matrix running
        # a black-and-white video) would otherwise dominate the frequency-ranked
        # palette. The box has no layout, so detect dominant whole-canvas blocks
        # by size and drop their pixels from the colors. Only re-scans frames
        # when such a block exists; falls back to the all-channel colors on any
        # trouble so colors are never lost.
        blocks = _dominant_blocks(litcount, fq.dense_len)
        if blocks:
            cidxs = sample_indices(fq.frame_count, min(len(idxs), COLOR_SAMPLES))
            try:
                if np is not None:
                    colors = _colors_excluding_numpy(np, fq, cidxs, blocks)
                else:
                    colors = _colors_excluding_pure(fq, cidxs, blocks)
            except Exception:
                pass

        stride = fq.frame_count / max(1, len(idxs))
        cues = int(round(raw_changes * stride))
        runs = _bounded_runs(litcount, fq.dense_len)
        moments = _pick_moments(fq, frame_lits, fq.step_ms, blocks)
        return {
            "cues": cues,
            "colors": colors,
            "activity": runs,
            "moments": moments,
        }
    finally:
        fq.close()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--fseq", required=True)
    ap.add_argument("--samples", type=int, default=DEFAULT_SAMPLES)
    args = ap.parse_args()
    try:
        stats = compute(args.fseq, args.samples)
    except OSError as e:
        stats = {"error": "could not read file: %s" % type(e).__name__}
    sys.stdout.write(json.dumps(stats))


if __name__ == "__main__":
    main()
