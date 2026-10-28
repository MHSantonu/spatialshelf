"""Unit tests for services.crops (no network; OpenCV only)."""

import cv2
import numpy as np

from services.crops import (
    assign_shelf_positions,
    make_crop,
    scale_box,
    select_detections,
    touches_edge,
)


def test_scale_box_matches_spike_truncation():
    assert scale_box([10, 20, 110, 220], 1.5, 1.5) == (15, 30, 165, 330)
    assert scale_box([1, 1, 2, 2], 1.5, 1.5) == (1, 1, 3, 3)


def test_touches_edge_uses_inclusive_bounds():
    assert touches_edge([0, 10, 50, 50], 1280, 720)
    assert touches_edge([10, 10, 1280, 50], 1280, 720)
    assert touches_edge([10, 0, 50, 50], 1280, 720)
    assert touches_edge([10, 10, 50, 720], 1280, 720)
    assert not touches_edge([1, 1, 1279, 719], 1280, 720)


def test_make_crop_pads_and_clamps():
    img = np.zeros((200, 300, 3), dtype="uint8")
    crop = make_crop(img, (240, 150, 290, 190))
    # 25% of 50x40 pads to 12/10 px, clamped to 72x60, then 2x upscale.
    assert crop.shape[:2] == (120, 144)


def test_make_crop_upscales_small_only():
    img = np.zeros((800, 800, 3), dtype="uint8")
    small = make_crop(img, (100, 100, 200, 200))
    assert small.shape[:2] == (300, 300)  # 150 px side padded, then 2x
    big = make_crop(img, (100, 100, 500, 400))
    assert big.shape[:2] == (450, 600)  # no upscale, no cap


def test_make_crop_caps_long_side_at_1024():
    img = np.zeros((1600, 2000, 3), dtype="uint8")
    crop = make_crop(img, (100, 100, 1500, 900))
    h, w = crop.shape[:2]
    assert max(h, w) == 1024
    assert (w, h) == (1024, 609)


def test_select_detections_prefers_clean_paired_view():
    dets = [
        {"id": "kf0_0", "keyframe": 0, "box": [0, 10, 100, 100]},
        {"id": "kf1_0", "keyframe": 1, "box": [50, 10, 150, 100]},
        {"id": "kf1_1", "keyframe": 1, "box": [0, 200, 100, 300]},
        {"id": "kf2_0", "keyframe": 2, "box": [1180, 200, 1280, 300]},
    ]
    sizes = {0: (1280, 720), 1: (1280, 720), 2: (1280, 720)}
    pairs = [
        {"a": "kf0_0", "b": "kf1_0", "dx": 0.0, "dy": 0.0},
        {"a": "kf1_1", "b": "kf2_0", "dx": 0.0, "dy": 0.0},
    ]
    kept, skipped = select_detections(dets, sizes, pairs)
    assert skipped == ["kf0_0"]  # truncated but paired with a clean view
    assert [d["id"] for d in kept] == ["kf1_0", "kf1_1", "kf2_0"]
    # kf1_1/kf2_0 pair up while both truncated: kept, item not lost.


def test_assign_shelf_positions_rows_and_order():
    items = [
        {"id": "a", "cy": 0.20, "h": 0.10, "shelf_x": 0.60},
        {"id": "b", "cy": 0.21, "h": 0.10, "shelf_x": 0.10},
        {"id": "c", "cy": 0.70, "h": 0.10, "shelf_x": 0.30},
    ]
    ordered = assign_shelf_positions(items)
    assert [(d["id"], d["shelf_row"], d["shelf_order"]) for d in ordered] == [
        ("b", 1, 1),
        ("a", 1, 2),
        ("c", 2, 1),
    ]


def test_assign_shelf_positions_caps_at_eight_rows():
    items = [
        {"id": f"r{i}", "cy": 0.05 * i, "h": 0.02, "shelf_x": 0.5}
        for i in range(10)
    ]
    ordered = assign_shelf_positions(items)
    assert max(d["shelf_row"] for d in ordered) == 8


def test_assign_shelf_positions_empty():
    assert assign_shelf_positions([]) == []
