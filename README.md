# fpp-SETIQ — SET:IQ Playlist Importer

A [Falcon Player (FPP)](https://github.com/FalconChristmas/fpp) plugin that connects
your show player to [SET:IQ](https://lightsofelmridge.com), the free season-setlist
curator from the IQ Suite. SET:IQ builds a unique, runtime-targeted playlist for
every night of your season; this plugin gets those playlists **onto the box in one
click** — and reports back what's actually on the box so the calendar never
schedules a sequence you don't have.

It runs entirely **on the FPP host**, which is the whole trick: a cloud web app
can't reach a LAN-only, HTTP-only FPP (CORS, mixed-content, Private Network
Access all forbid it), but a plugin on the box can talk to both `127.0.0.1`
and the internet.

## Features

| Feature | What it does |
|---|---|
| **Pull from SET:IQ** | Fetches every night's generated playlist + the season schedule straight from SET:IQ's cloud by show key and creates them locally. No download/upload dance. Manual and on-demand — nothing runs unless you click Pull. |
| **Sequence reconcile** | On every Pull (or via **Sync sequence list only**), reports the box's on-board `.fseq` list back to SET:IQ. Its calendar then locks songs that aren't on FPP yet ("not on FPP yet") and only schedules what will really play. Matching is casing/punctuation-tolerant. |
| **Pull status** | Self-reports the box's hostname with each pull, so SET:IQ's Send-to-FPP dialog can confirm the loop closed: *"Last pulled by fpp.local today at 4:12 PM · 23 playlists in sync."* |
| **Manual import** | Fallback for bridge-less setups: upload SET:IQ's exported `.json` playlists via FPP's File Manager, then one-click convert them into real playlists. |
| **Idempotent** | Re-running either path overwrites same-named playlists cleanly — re-pull whenever you change the season. |

## Install

No FPP store entry needed. FPP UI → **Content Setup → Plugin Manager**, paste this
repo's manifest URL, and Install:

```
https://raw.githubusercontent.com/diaquas/fpp-SETIQ/main/pluginInfo.json
```

**Requirements:** FPP 8.x or newer (verified against 9.5.1), internet access from
the FPP for the Pull/Sync features. `php-curl` ships with FPP — nothing to build,
no daemon, no FPPD restart.

## Use

### One-click — Pull from SET:IQ (recommended)

1. In SET:IQ, open **Send to FPP** (Automatic tab) → copy your **show key**.
2. FPP → **Content Setup → SET:IQ - Pull from SET:IQ** → paste the key → **Pull from SET:IQ**.
3. Every night's playlist is fetched and created locally, and the box's sequence
   list is reported back for reconcile. Re-pull whenever you change the season.

Use **Sync sequence list only** to refresh the reconcile data without re-importing
playlists (e.g. right after copying new `.fseq` files to the box).

### Manual — import uploaded files

1. In SET:IQ, **Send to FPP → Manual download** → download the bundle and unzip.
2. FPP → **Content Setup → File Manager → Select Files** → upload the `.json` playlists.
3. FPP → **Content Setup → SET:IQ - Import Playlists** → **Import**.
4. Playlists appear under **Content Setup → Playlists**.

## How it works

The plugin is two PHP pages rendered inside the FPP UI (registered via
`menu.inc` under **Content Setup**) — no background service, no schema, no
JavaScript framework. Everything happens server-side on the box via `curl`:

```
┌─ SET:IQ cloud (HTTPS) ─────────────────────────────────────────┐
│  GET  /api/setiq/fpp/playlists?key=…   playlists + schedule    │
│  POST /api/setiq/fpp/sync              {key, sequences[]}      │
└────────────────────────────────────────────────────────────────┘
            ▲ fetch / report                       │
            │                                      ▼
┌─ this plugin (PHP on the FPP host) ────────────────────────────┐
│  pull.php     Pull + sequence sync, key storage                │
│  content.php  import uploaded .json files                      │
└────────────────────────────────────────────────────────────────┘
            │ create / read                        ▲
            ▼                                      │
┌─ FPP local REST API (http://127.0.0.1) ────────────────────────┐
│  POST /api/playlist/<name>     create/overwrite a playlist     │
│  GET  /api/files/Sequences     list on-board .fseq files       │
│  GET  /api/files/Uploads       list File Manager uploads       │
└────────────────────────────────────────────────────────────────┘
```

**Trust model.** The show key is a per-show bearer secret minted by SET:IQ. It's
stored **only on this box** (`config/fpp-SETIQ.key` under FPP's config
directory) and sent only to `lightsofelmridge.com` over HTTPS. Cloud-side, the
key scopes every read and write to exactly one show — an invalid key fetches
nothing and writes nothing. Treat it like a password; SET:IQ can regenerate it
at any time (the old key dies immediately).

**What leaves the box.** Two things only: the show key you pasted, and the list
of `.fseq` filenames (for reconcile, plus the hostname for the status line). No
media, no sequence data, no credentials.

### Repo layout

```
pluginInfo.json          FPP Plugin Manager manifest (install/update source)
menu.inc                 menu entries under Content Setup
pull.php                 "Pull from SET:IQ" — fetch by key, create locally, sync sequences
content.php              "Import Playlists" — convert uploaded .json files
scripts/fpp_install.sh   install hook (nothing to build)
scripts/fpp_uninstall.sh uninstall hook
```

The canonical source lives in the
[Lights-of-Elm-Ridge monorepo](https://github.com/diaquas/Lights-of-Elm-Ridge)
under `fpp-plugin/fpp-SETIQ/` and is mirrored here; FPP's Plugin Manager
installs and updates from this repo's `main`.

## Troubleshooting

- **"Couldn't reach SET:IQ or the key is invalid (HTTP 404)"** — the key doesn't
  match any show (typo, or it was regenerated in SET:IQ). Copy a fresh key from
  **Send to FPP**.
- **"Couldn't reach SET:IQ … (HTTP 0)"** — the FPP has no route to the internet;
  check DNS/gateway, or use the Manual path.
- **Playlists import but FPP flags `Invalid mediaName`** — the audio filename
  differs from the sequence name (SET:IQ currently assumes `Song.fseq` ↔
  `Song.mp3`). Remap the media on the playlist entry; exact media names from the
  FSEQ header are on the roadmap.
- **Updating the plugin** — Plugin Manager → fpp-SETIQ → Update (pulls this
  repo's `main`).

## Roadmap

- **Exact media names** from the FSEQ `mf` header tag (kills the `Invalid mediaName` remaps).
- **Scheduled auto-pull** — opt-in nightly pull so season edits land without touching the box.

---

Part of the **IQ Suite** by [Lights of Elm Ridge](https://lightsofelmridge.com) —
SET:IQ (season setlists) · MAP:IQ (sequence mapping) · TRK:IQ (audio analysis) ·
REQ:IQ (song requests).
