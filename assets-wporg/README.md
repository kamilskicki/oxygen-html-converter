# WordPress.org Asset Sources

This directory contains editable SVG sources, generated PNG exports, and reproducible verification evidence for the WordPress.org plugin directory artwork.

## Source Artwork

- `icon.svg` uses a bold code-to-Oxygen conversion mark designed to remain clear at 128 x 128.
- `banner.svg` pairs the same mark with the `HTML Converter` wordmark on a clean blue gradient.
- Both SVGs include accessible titles, descriptions, and license metadata.

## Required PNG Exports

WordPress.org SVN assets should include:

- `icon-128x128.png` at 128 x 128
- `icon-256x256.png` at 256 x 256
- `banner-772x250.png` at 772 x 250
- `banner-1544x500.png` at 1544 x 500

Optional retina screenshots should be exported separately and referenced by the `readme.txt` screenshots section.

## Render and Verify

From the plugin `core` directory, use the repository's Playwright installation and Pillow:

```powershell
node assets-wporg/verification/render-assets.cjs
python assets-wporg/verification/verify-assets.py
```

The renderer opens each source SVG in headless Chromium at device scale factor 1 and captures the exact target dimensions. It also opens the 772 x 250 PNG in a browser verification page and writes `verification/banner-772x250-browser-proof.png`.

The verifier checks PNG file magic, Pillow decoding, exact dimensions, file size over 3 KB, pixel luminance variance, luminance range, and sampled color count. Machine-readable results are written to `verification/verification-results.json`.

## Licensing

The SVG artwork and PNG derivatives in this directory are original artwork created for HTML Converter. They are licensed under the GNU General Public License, version 2 or any later version (`GPL-2.0-or-later`), consistent with the plugin.

Oxygen and Oxygen Builder names and trademarks belong to their respective owner. This independent plugin artwork is not an official Oxygen brand asset.

## Publication Notes

- Keep exported PNG filenames exactly as WordPress.org expects.
- Keep screenshot captions in `readme.txt` synchronized with the final screenshot files.
