"""Unit tests for services.video sampling (no ffmpeg; OpenCV only)."""

from pathlib import Path

import cv2
import pytest

from services.video import dedupe_views, keyframe_count

ROOT = Path(__file__).resolve().parent.parent.parent
STILL = ROOT / "samples" / "shelf_bottles_still.jpg"


def load_still():
    assert STILL.exists(), f"test asset missing: {STILL}"
    return cv2.imread(str(STILL), cv2.IMREAD_GRAYSCALE)


@pytest.mark.parametrize(
    "duration,expected",
    [(1.5, 3), (1.9, 4), (2.0, 5), (2.3, 5), (3.1, 7), (3.2, 8), (12.0, 8), (60.0, 8)],
)
def test_keyframe_count_scales_with_duration(duration, expected):
    assert keyframe_count(duration) == expected


def test_dedupe_views_collapses_identical_frames(tmp_path):
    img = load_still()[:, 0:1000]
    paths = []
    for name in ("kf_0.jpg", "kf_1.jpg", "kf_2.jpg"):
        path = tmp_path / name
        cv2.imwrite(str(path), img)
        paths.append(path)

    assert dedupe_views(paths) == [0]


def test_dedupe_views_keeps_distinct_views(tmp_path):
    img = load_still()
    paths = []
    for pos, x0 in enumerate((0, 400, 800)):
        path = tmp_path / f"kf_{pos}.jpg"
        cv2.imwrite(str(path), img[:, x0 : x0 + 400])
        paths.append(path)

    assert dedupe_views(paths) == [0, 1, 2]


def test_dedupe_views_keeps_sharpest_of_same_view(tmp_path):
    img = load_still()[:, 0:1000]
    blurred = cv2.GaussianBlur(img, (21, 21), 0)
    soft = tmp_path / "kf_0.jpg"
    sharp = tmp_path / "kf_1.jpg"
    cv2.imwrite(str(soft), blurred)
    cv2.imwrite(str(sharp), img)

    assert dedupe_views([soft, sharp]) == [1]
    assert dedupe_views([sharp, soft]) == [0]


def test_dedupe_views_empty():
    assert dedupe_views([]) == []
