from __future__ import annotations

import hashlib
import json
from datetime import datetime, timezone
from pathlib import Path

from PIL import Image, ImageStat


ASSETS_DIR = Path(__file__).resolve().parent.parent
RESULTS_PATH = Path(__file__).resolve().parent / "verification-results.json"
PNG_MAGIC = b"\x89PNG\r\n\x1a\n"
EXPECTED = {
    "icon-128x128.png": (128, 128),
    "icon-256x256.png": (256, 256),
    "banner-772x250.png": (772, 250),
    "banner-1544x500.png": (1544, 500),
}


def verify_asset(name: str, expected_size: tuple[int, int]) -> dict[str, object]:
    path = ASSETS_DIR / name
    raw = path.read_bytes()
    checks = {
        "png_magic": raw[:8] == PNG_MAGIC,
        "file_size_gt_3kb": len(raw) > 3 * 1024,
    }

    with Image.open(path) as image:
        image.load()
        rgb = image.convert("RGB")
        luminance = rgb.convert("L")
        channel_variance = ImageStat.Stat(rgb).var
        luminance_variance = ImageStat.Stat(luminance).var[0]
        luminance_extrema = luminance.getextrema()
        sample = rgb.resize((64, 64))
        sampled_unique_colors = len(set(sample.get_flattened_data()))

        checks.update(
            {
                "pillow_decodes_as_png": image.format == "PNG",
                "exact_dimensions": image.size == expected_size,
                "non_blank_luminance_variance": luminance_variance > 100,
                "non_blank_luminance_range": luminance_extrema[1] - luminance_extrema[0] > 30,
                "non_trivial_sampled_color_count": sampled_unique_colors > 16,
            }
        )

        result = {
            "file": name,
            "bytes": len(raw),
            "sha256": hashlib.sha256(raw).hexdigest(),
            "format": image.format,
            "mode": image.mode,
            "dimensions": list(image.size),
            "expected_dimensions": list(expected_size),
            "channel_variance": [round(value, 2) for value in channel_variance],
            "luminance_variance": round(luminance_variance, 2),
            "luminance_extrema": list(luminance_extrema),
            "sampled_unique_colors_64x64": sampled_unique_colors,
            "checks": checks,
            "passed": all(checks.values()),
        }

    if not result["passed"]:
        failed = [check for check, passed in checks.items() if not passed]
        raise AssertionError(f"{name} failed: {', '.join(failed)}")

    return result


def main() -> None:
    results = [verify_asset(name, dimensions) for name, dimensions in EXPECTED.items()]
    report = {
        "verified_at_utc": datetime.now(timezone.utc).isoformat(),
        "requirements": {
            "png_magic": PNG_MAGIC.hex(),
            "minimum_bytes_exclusive": 3 * 1024,
            "exact_dimensions": True,
            "pillow_decode": True,
            "minimum_luminance_variance_exclusive": 100,
            "minimum_luminance_range_exclusive": 30,
            "minimum_sampled_unique_colors_exclusive": 16,
        },
        "assets": results,
        "passed": all(result["passed"] for result in results),
    }
    RESULTS_PATH.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")

    for result in results:
        dimensions = "x".join(str(value) for value in result["dimensions"])
        print(
            f"PASS {result['file']}: {dimensions}, {result['bytes']} bytes, "
            f"luma variance {result['luminance_variance']}, "
            f"sampled colors {result['sampled_unique_colors_64x64']}, "
            f"sha256 {result['sha256']}"
        )
    print(f"PASS all {len(results)} PNG assets; wrote {RESULTS_PATH}")


if __name__ == "__main__":
    main()
