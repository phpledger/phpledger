# Brand assets

The PHP Ledger logo, supplied by the owner from a Google Drive brand folder on
21 September 2026. The artwork was made in Canva; its embedded metadata credits
S. Rida under the Team BixiSoft brand.

| File | What it is | Use it for |
|---|---|---|
| `phpledger-icon-512.png` | Square app icon, mark only, transparent, 512×512 | Catalogue and app-store icons |
| `phpledger-icon-192.png` | The same at 192×192 | Smaller icon slots |
| `phpledger-mark.png` | The mark alone, trimmed, 389×456 | When you need the mark at its own aspect ratio |
| `phpledger-lockup-colour.png` | Mark above the wordmark, navy and blue, transparent | Light backgrounds |
| `phpledger-lockup-navy.png` | The same lockup, navy treatment | Light backgrounds |
| `phpledger-lockup-black.png` | The same lockup in black | Single-colour use |
| `phpledger-lockup-colour.svg` | The colour lockup as supplied | See the caveat below |

The mark is an open book whose pages form a P, in the site's navy `#0c2052` and
blue `#4656e8`. The wordmark reads "PHP Ledger".

## There is no true vector version

**The `.svg` file here is not vector artwork.** It is a PNG wrapped in an SVG
container: two embedded `<image>` elements holding base64 raster data, and no drawn
paths. Every file in the supplied Drive folder is built the same way. Scaling one up
blurs exactly as a PNG would, so it buys nothing over the PNGs beside it. It is kept
only because some catalogues insist on a file with an `.svg` extension.

**A real vector logo is still wanted.** Two catalogue submissions are waiting on it:
CasaOS requires both `icon.png` and `icon.svg` committed inside its app folder, and
Coolify's contribution guide states SVG is preferred and PNG accepted only when no
SVG exists. Exporting genuine SVG from the original Canva design is a short job for
whoever holds it. See [issue #101](https://github.com/phpledger/phpledger/issues/101).

## How the PNGs here were produced

Each supplied `.svg` carries two embedded rasters: a greyscale alpha mask and an RGB
colour layer. Extracting either alone gives artwork flattened onto black. The files
here recombine the two, so they carry real transparency. The icon was then cropped to
the mark above the wordmark, trimmed to its content, centred on a square canvas with
about eight per cent padding, and resampled with Lanczos.

The existing `www/website/public/assets/brand/` icons predate these and are unchanged.
