"""Unit tests for services.positions (no network; OpenCV only)."""

import json
from pathlib import Path

import cv2
import numpy as np
import pytest

from services.positions import (
    accumulate_offsets,
    compute_positions,
    estimate_shift,
    find_same_spot,
    keyframe_fractions,
    position_detections,
    sample_offsets,
)

ROOT = Path(__file__).resolve().parent.parent.parent
STILL = ROOT / "samples" / "shelf_bottles_still.jpg"


def load_still():
    assert STILL.exists(), f"test asset missing: {STILL}"
    return cv2.imread(str(STILL), cv2.IMREAD_GRAYSCALE)


def test_estimate_shift_known_offset():
    """Two crops offset by 60 px must report ~(-60, 0)."""
    img = load_still()
    width, dx = 1000, 60
    crop_a = img[:, 0:width]
    crop_b = img[:, dx : width + dx]
    est_dx, est_dy, response = estimate_shift(crop_a, crop_b)
    assert est_dx == pytest.approx(-dx, abs=0.5)
    assert est_dy == pytest.approx(0.0, abs=0.5)
    assert response > 0.5


def test_estimate_shift_identical_is_zero():
    img = load_still()
    crop = img[:, 0:1000]
    est_dx, est_dy, response = estimate_shift(crop, crop)
    assert est_dx == pytest.approx(0.0, abs=0.01)
    assert est_dy == pytest.approx(0.0, abs=0.01)
    assert response == pytest.approx(1.0, abs=0.01)


def test_estimate_shift_rejects_mismatched_shapes():
    with pytest.raises(ValueError, match="shapes differ"):
        estimate_shift(
            np.zeros((100, 100), dtype="uint8"),
            np.zeros((100, 90), dtype="uint8"),
        )


def test_accumulate_offsets_chains_dense_steps():
    """Four 15 px steps must accumulate to 60/500 widths."""
    img = load_still()
    width, step = 500, 15
    frames = [img[:, i * step : width + i * step] for i in range(4)]
    offsets, edges, size = accumulate_offsets(frames)
    assert size == (width, img.shape[0])
    assert offsets[0] == 0.0
    assert offsets[-1] == pytest.approx(3 * step / width, abs=0.002)
    assert len(edges) == 3
    assert all(e["response"] > 0.5 for e in edges)


def test_sample_offsets_interpolates():
    offsets = [0.0, 0.1, 0.2, 0.3]
    assert sample_offsets(offsets, [0.0]) == pytest.approx([0.0])
    assert sample_offsets(offsets, [1.0]) == pytest.approx([0.3])
    assert sample_offsets(offsets, [0.5]) == pytest.approx([0.2])
    assert sample_offsets(offsets, [0.375]) == pytest.approx([0.15])


def test_keyframe_fractions_span_5_to_95_percent():
    assert keyframe_fractions(8)[0] == pytest.approx(0.05)
    assert keyframe_fractions(8)[-1] == pytest.approx(0.95)
    assert keyframe_fractions(1) == [0.5]


def test_shelf_x_stable_for_same_item():
    """Same physical point in two offset crops shares one shelf_x."""
    img = load_still()
    width, dx = 1000, 60
    offsets, _, sizes = accumulate_offsets(
        [img[:, 0:width], img[:, dx : width + dx]]
    )
    assert offsets[1] == pytest.approx(dx / width, abs=0.002)

    frame_x = 500  # same physical point, seen through both windows
    dets = [
        {
            "id": "kf0_0",
            "keyframe": 0,
            "box": [frame_x - 40, 100, frame_x + 40, 300],
            "phrase": "bottle",
        },
        {
            "id": "kf1_0",
            "keyframe": 1,
            "box": [frame_x - dx - 40, 100, frame_x - dx + 40, 300],
            "phrase": "bottle",
        },
    ]
    positioned = position_detections(
        dets, {0: sizes, 1: sizes}, {0: offsets[0], 1: offsets[1]}
    )
    assert positioned[0]["shelf_x"] == pytest.approx(
        positioned[1]["shelf_x"], abs=0.002
    )


def test_find_same_spot_pairs_adjacent_only():
    dets = [
        {"id": "kf0_0", "keyframe": 0, "cx": 0.5, "cy": 0.5,
         "w": 0.1, "h": 0.2, "shelf_x": 0.5, "phrase": "b"},
        {"id": "kf1_0", "keyframe": 1, "cx": 0.4, "cy": 0.51,
         "w": 0.1, "h": 0.2, "shelf_x": 0.505, "phrase": "b"},
        {"id": "kf1_1", "keyframe": 1, "cx": 0.9, "cy": 0.5,
         "w": 0.1, "h": 0.2, "shelf_x": 0.9, "phrase": "b"},
        {"id": "kf2_0", "keyframe": 2, "cx": 0.3, "cy": 0.9,
         "w": 0.1, "h": 0.2, "shelf_x": 0.5, "phrase": "b"},
    ]
    pairs = find_same_spot(dets, [0, 1, 2])
    assert [(p["a"], p["b"]) for p in pairs] == [("kf0_0", "kf1_0")]


def test_find_same_spot_empty_without_adjacent_pairs():
    assert find_same_spot([], [0, 1]) == []
    solo = [
        {"id": "kf0_0", "keyframe": 0, "cx": 0.5, "cy": 0.5,
         "w": 0.1, "h": 0.2, "shelf_x": 0.5, "phrase": "b"}
    ]
    assert find_same_spot(solo, [0]) == []


def write_clip(path: Path, frames: list) -> None:
    height, width = frames[0].shape[:2]
    writer = cv2.VideoWriter(
        str(path), cv2.VideoWriter_fourcc(*"mp4v"), 10, (width, height)
    )
    assert writer.isOpened(), "test needs an mp4-writing OpenCV build"
    for frame in frames:
        writer.write(frame)
    writer.release()


def test_compute_positions_writes_files(tmp_path):
    # 20-frame clip stepping 10 px: 2 keyframes land exactly on clip
    # frames 1 and 19 (fractions 0.05/0.95), so a same-spot pair must
    # survive the full video-file round trip.
    gray = load_still()
    color = cv2.imread(str(STILL), cv2.IMREAD_COLOR)
    width, step, frames = 500, 10, 20
    windows = [color[:, i * step : width + i * step] for i in range(frames)]
    scan = tmp_path / "scans" / "t1"
    kf_dir = scan / "keyframes"
    kf_dir.mkdir(parents=True)
    cv2.imwrite(str(kf_dir / "kf_0.jpg"), gray[:, 10 : width + 10])
    cv2.imwrite(str(kf_dir / "kf_1.jpg"), gray[:, 190 : width + 190])
    write_clip(scan / "clip.mp4", windows)
    # Same physical point (frame-x 400) seen in both keyframe windows.
    frame_x = 400
    dets = [
        {"id": "kf0_0", "keyframe": 0,
         "box": [frame_x - 10 - 40, 100, frame_x - 10 + 40, 300],
         "confidence": 0.9, "phrase": "bottle"},
        {"id": "kf1_0", "keyframe": 1,
         "box": [frame_x - 190 - 40, 100, frame_x - 190 + 40, 300],
         "confidence": 0.9, "phrase": "bottle"},
    ]
    (scan / "detections.json").write_text(
        json.dumps(
            {"scan_id": "t1", "phrases": ["bottle"], "total": 2,
             "detections": dets}
        ),
        encoding="utf-8",
    )
    summary = compute_positions(tmp_path, "t1")
    assert summary["detection_count"] == 2
    assert summary["pair_count"] == 1
    assert summary["offsets"][1] > summary["offsets"][0]
    same = json.loads((scan / "same_spot.json").read_text(encoding="utf-8"))
    assert same["total_pairs"] == 1
    assert (same["pairs"][0]["a"], same["pairs"][0]["b"]) == ("kf0_0", "kf1_0")
    pos = json.loads((scan / "positions.json").read_text(encoding="utf-8"))
    assert pos["total_detections"] == 2
    assert pos["clip_frames"] == frames
    assert [k["index"] for k in pos["keyframes"]] == [0, 1]
    assert len(pos["edges"]) == 1


def test_compute_positions_missing_scan(tmp_path):
    with pytest.raises(FileNotFoundError, match="Scan not found"):
        compute_positions(tmp_path, "nope")


def test_compute_positions_needs_clip(tmp_path):
    scan = tmp_path / "scans" / "t1"
    (scan / "keyframes").mkdir(parents=True)
    cv2.imwrite(
        str(scan / "keyframes" / "kf_0.jpg"), load_still()[:, 0:500]
    )
    (scan / "detections.json").write_text(
        json.dumps({"scan_id": "t1", "phrases": [], "total": 0,
                    "detections": []}),
        encoding="utf-8",
    )
    with pytest.raises(FileNotFoundError, match="clip.mp4"):
        compute_positions(tmp_path, "t1")
