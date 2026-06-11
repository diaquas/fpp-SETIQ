# fpp-SETIQ — SET:IQ Playlist Importer

One-click import of [SET:IQ](https://lightsofelmridge.com)-generated playlist JSONs
into real, runnable FPP playlists. Runs entirely on the FPP host, so there are no
browser CORS / mixed-content / Private-Network-Access limitations.

## Install (no FPP store needed)

FPP UI → **Content Setup → Plugin Manager**, paste this repo's manifest URL and Install:

```
https://raw.githubusercontent.com/diaquas/fpp-SETIQ/main/pluginInfo.json
```

> If your repo's default branch is `master`, use `…/master/pluginInfo.json` and set
> `"branch": "master"` in `pluginInfo.json` to match.

## Use

1. In SET:IQ, **Send to FPP** → download the bundle and unzip.
2. FPP → **Content Setup → File Manager → Select Files** → upload the `.json` playlists.
3. FPP → **Content Setup → SET:IQ - Import Playlists** → **Import**.
4. Playlists appear under **Content Setup → Playlists**.

## How it works

The plugin page runs on the FPP host and `POST`s each playlist to the local API
`http://127.0.0.1/api/playlist/<name>` — verified against FPP 9.5.1. Re-running is
idempotent (a re-import overwrites the same-named playlist).

## Roadmap

- **Auto-pull:** fetch playlists straight from SET:IQ's cloud by show key (no manual
  upload).
- **Reconcile:** report the box's on-board sequences back to SET:IQ so its calendar
  only schedules songs that exist on FPP.

Part of the IQ Suite by Lights of Elm Ridge.
