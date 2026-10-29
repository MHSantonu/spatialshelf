"""Unit tests for single-product crop helpers (no network, no torch)."""

from services.sam import largest_box, padded_box


def test_largest_box_picks_biggest_area():
    boxes = [
        {"box": [0, 0, 10, 10], "confidence": 0.99},
        {"box": [0, 0, 40, 30], "confidence": 0.61},
        {"box": [0, 0, 20, 20], "confidence": 0.9},
    ]

    assert largest_box(boxes) == {"box": [0, 0, 40, 30], "confidence": 0.61}


def test_largest_box_empty_returns_none():
    assert largest_box([]) is None


def test_padded_box_grows_25_percent_per_side():
    assert padded_box([100, 100, 200, 220], 1000, 1000) == [75, 70, 225, 250]


def test_padded_box_clamps_to_image_edges():
    assert padded_box([10, 10, 110, 60], 120, 70) == [0, 0, 120, 70]
