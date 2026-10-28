"""Probe: how many instances per frame qualify at >= 0.7, off-edge (no model call)."""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import cv2

from read_crops import load_result, pick_slots

FRAME_DIR = (
    Path(__file__).resolve().parent.parent
    / "spikes"
    / "out"
    / "VID_20261027_181256_1280_product_package_frames"
)

for name in ("frame_0.jpg", "frame_30.jpg", "frame_61.jpg"):
    img = cv2.imread(str(FRAME_DIR / name), cv2.IMREAD_COLOR)
    height, width = img.shape[:2]
    result = load_result(FRAME_DIR / (Path(name).stem + "_raw.txt"))
    try:
        slots = pick_slots(result, width, height, 10000)
        print(f"{name}: {len(slots)} qualify")
    except RuntimeError as error:
        print(f"{name}: {error}")
