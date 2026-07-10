# WordPress.org Asset Sources

This directory contains editable SVG sources and generated PNG exports for the WordPress.org plugin directory artwork.

## Required PNG Exports

WordPress.org SVN assets should include:

- `icon-128x128.png` at 128 x 128
- `icon-256x256.png` at 256 x 256
- `banner-772x250.png` at 772 x 250
- `banner-1544x500.png` at 1544 x 500

Optional retina screenshots should be exported separately and referenced by the `readme.txt` screenshots section.

## Export Commands

Example Inkscape commands:

```powershell
inkscape assets-wporg/icon.svg --export-type=png --export-filename=assets-wporg/icon-128x128.png -w 128 -h 128
inkscape assets-wporg/icon.svg --export-type=png --export-filename=assets-wporg/icon-256x256.png -w 256 -h 256
inkscape assets-wporg/banner.svg --export-type=png --export-filename=assets-wporg/banner-772x250.png -w 772 -h 250
inkscape assets-wporg/banner.svg --export-type=png --export-filename=assets-wporg/banner-1544x500.png -w 1544 -h 500
```

Review the generated PNGs at their target dimensions before copying them into the WordPress.org SVN `assets/` directory.

## Licensing

All artwork in this directory is original project artwork and is GPL-compatible for distribution with the Oxygen HTML Converter plugin.

## Publication Notes

- Keep exported PNG filenames exactly as WordPress.org expects.
- Keep screenshot captions in `readme.txt` synchronized with the final screenshot files.
