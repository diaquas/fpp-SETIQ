# fpp-SETIQ — SET:IQ + REQ:IQ for FPP

Lights of Elm Ridge IQ tools, running entirely on the FPP host (no browser
CORS / mixed-content / Private-Network-Access limitations):

- **SET:IQ** — one-click import of [SET:IQ](https://lightsofelmridge.com)-generated
  playlists, straight from the cloud or from uploaded JSON.
- **REQ:IQ** — viewer song requests: a background listener reports what's
  playing to your REQ:IQ viewer page and inserts requested songs into playback.

## Install (no FPP store needed)

FPP UI → **Content Setup → Plugin Manager**, paste this repo's manifest URL and Install:

```
https://raw.githubusercontent.com/diaquas/fpp-SETIQ/main/pluginInfo.json
```

> If your repo's default branch is `master`, use `…/master/pluginInfo.json` and set
> `"branch": "master"` in `pluginInfo.json` to match.

## SET:IQ — playlists

### One-click — Pull from SET:IQ (recommended)

1. In SET:IQ, **Send to FPP** → copy your **show key**.
2. FPP → **Content Setup → SET:IQ - Pull from SET:IQ** → paste the key → **Pull from SET:IQ**.
3. Every night's playlist is fetched from SET:IQ and created locally. Re-pull
   whenever you change the season. (Manual — nothing runs unless you click it.)

Pull also reports the box's `.fseq` list back to SET:IQ so its calendar can
flag songs that aren't on FPP yet (**Sync with FPP** reconcile).

### Manual — import uploaded files

1. In SET:IQ, **Send to FPP** → download the bundle and unzip.
2. FPP → **Content Setup → File Manager → Select Files** → upload the `.json` playlists.
3. FPP → **Content Setup → SET:IQ - Import Playlists** → **Import**.
4. Playlists appear under **Content Setup → Playlists**.

## REQ:IQ — viewer requests

1. Set your show key on the **Pull from SET:IQ** page (shared key).
2. FPP → **Content Setup → REQ:IQ - Viewer Requests** → **Enable REQ:IQ**.
3. Done. Viewers on your REQ:IQ page see what's playing live and can request
   songs; requests are inserted right after the current song.

While enabled, a background listener (started with fppd, ~5 s loop):

- reports playback status to `lightsofelmridge.com/api/reqiq/fpp/heartbeat`
  (now playing, live/offline — drives the viewer page in real time),
- maintains a **REQIQ Requests** playlist built from your catalog (only songs
  whose `.fseq` is actually on the box),
- executes the cloud's directives via FPP's
  `Insert Playlist After Current` / `Insert Playlist Immediate` commands
  (viewer requests and admin force-plays), then confirms back so the queue
  advances pending → playing → played.

Disable any time from the same page — the listener stops and nothing phones home.

## How it works

Plugin pages and the listener run on the FPP host and use the local API
(`http://127.0.0.1/api/...`, verified against FPP 9.5.1) plus key-scoped
endpoints on lightsofelmridge.com. The show key is stored only on this FPP
(`/home/fpp/media/config/fpp-SETIQ.key`). Re-running imports is idempotent.

## Roadmap

- **Auto-pull:** scheduled playlist refresh from SET:IQ's cloud (the REQ:IQ
  listener already refreshes the requests playlist automatically).
- **Exact media names:** emit the FSEQ's embedded media filename instead of
  the `.fseq → .mp3` guess.

Part of the IQ Suite by Lights of Elm Ridge.
