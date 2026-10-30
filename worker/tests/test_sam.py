"""Unit tests for services.sam baseline and retry (SAM calls mocked)."""

import asyncio
import itertools
import json
from pathlib import Path

import pytest

from services import sam


def _box(number: int, phrase: str) -> dict:
    step = number * 200
    return {
        "box": [step, 0, step + 100, 100],
        "confidence": 0.9,
        "phrase": phrase,
    }


def _scan_dir(tmp_path: Path, kept: int, candidates: int) -> Path:
    """Fake scan layout: keyframes/ holds `kept` frames, candidates/ all."""
    scan = tmp_path / "scans" / "t1"
    (scan / "keyframes").mkdir(parents=True)
    (scan / "keyframes_full").mkdir(parents=True)
    (scan / "candidates").mkdir(parents=True)
    (scan / "candidates_full").mkdir(parents=True)
    for i in range(candidates):
        (scan / "candidates" / f"kf_{i}.jpg").write_bytes(b"fake")
        (scan / "candidates_full" / f"kf_{i}.jpg").write_bytes(b"fake")
    keep = list(range(kept))
    (scan / "candidates" / "kept.json").write_text(json.dumps({"kept": keep}))
    for new, pos in enumerate(keep):
        (scan / "keyframes" / f"kf_{new}.jpg").write_bytes(b"fake")
        (scan / "keyframes_full" / f"kf_{new}.jpg").write_bytes(b"fake")
    return scan


def _pair_factory(boxes_per_call: int, seen: list):
    counter = itertools.count()

    async def fake_pair(client, sem, kf_index, phrase, url, sam_dir):
        seen.append((kf_index, phrase))
        return {
            "keyframe": kf_index,
            "phrase": phrase,
            "seconds": 0.1,
            "boxes": [
                _box(next(counter), phrase) for _ in range(boxes_per_call)
            ],
            "error": None,
        }

    return fake_pair


async def _fake_video(client, clip_path, phrase):
    class Result:
        raw_output = "overlay"

    return Result(), 0.1


def test_with_baseline_appends_missing_types_only():
    assert sam.with_baseline(["plastic jar", "Box"]) == [
        "plastic jar",
        "Box",
        "jar",
        "packet",
        "bottle",
    ]
    assert sam.with_baseline([]) == ["jar", "box", "packet", "bottle"]


def test_no_retry_when_pass_1_reaches_ten(tmp_path, monkeypatch):
    scan = _scan_dir(tmp_path, kept=1, candidates=3)
    seen: list = []
    monkeypatch.setattr(sam, "_image_pair", _pair_factory(2, seen))
    monkeypatch.setattr(sam, "_video_result", _fake_video)

    result = asyncio.run(sam.segment_scan(tmp_path, "t1", ["tin can"]))

    assert result["retried"] is False
    assert result["retry"] is None
    assert result["total_detections"] == 10  # 5 phrases x 2 boxes
    assert result["phrases"] == ["tin can", "jar", "box", "packet", "bottle"]
    assert result["picked_phrases"] == ["tin can"]
    assert sorted((scan / "keyframes").glob("kf_*.jpg")) == [
        scan / "keyframes" / "kf_0.jpg"
    ]


def test_retry_expands_to_candidates_below_ten(tmp_path, monkeypatch):
    scan = _scan_dir(tmp_path, kept=1, candidates=3)
    seen: list = []
    monkeypatch.setattr(sam, "_image_pair", _pair_factory(1, seen))
    monkeypatch.setattr(sam, "_video_result", _fake_video)

    result = asyncio.run(sam.segment_scan(tmp_path, "t1", ["tin can"]))

    assert result["retried"] is True
    assert result["retry"]["candidate_count"] == 3
    assert result["retry"]["kept_count"] == 1
    # pass-1 boxes remap to candidate 0 and union with the retry boxes
    assert result["keyframe_indices"] == [0, 1, 2]
    assert result["total_detections"] == 5 + 12  # pass 1 + baseline x 3
    assert {p.name for p in (scan / "keyframes").glob("kf_*.jpg")} == {
        "kf_0.jpg",
        "kf_1.jpg",
        "kf_2.jpg",
    }
    assert {p.name for p in (scan / "keyframes_full").glob("kf_*.jpg")} == {
        "kf_0.jpg",
        "kf_1.jpg",
        "kf_2.jpg",
    }
    retry_calls = seen[5:]  # pass 1 runs 1 kf x 5 phrases first
    assert len(retry_calls) == 12
    assert {kf for kf, _ in retry_calls} == {0, 1, 2}
    assert {ph for _, ph in retry_calls} == set(sam.BASELINE_PHRASES)
    saved = json.loads((scan / "detections.json").read_text())
    assert saved["retried"] is True
    assert all(
        (scan / "keyframes_full" / f"kf_{d['keyframe']}.jpg").is_file()
        for d in saved["detections"]
    )


def test_retry_skipped_without_candidates(tmp_path, monkeypatch):
    scan = _scan_dir(tmp_path, kept=1, candidates=1)
    seen: list = []
    monkeypatch.setattr(sam, "_image_pair", _pair_factory(1, seen))
    monkeypatch.setattr(sam, "_video_result", _fake_video)

    result = asyncio.run(sam.segment_scan(tmp_path, "t1", ["tin can"]))

    assert result["retried"] is False
    assert result["total_detections"] == 5


def test_retry_failure_keeps_pass_1_results(tmp_path, monkeypatch):
    scan = _scan_dir(tmp_path, kept=1, candidates=3)
    (scan / "candidates" / "kept.json").write_text("not json")
    seen: list = []
    monkeypatch.setattr(sam, "_image_pair", _pair_factory(1, seen))
    monkeypatch.setattr(sam, "_video_result", _fake_video)

    result = asyncio.run(sam.segment_scan(tmp_path, "t1", ["tin can"]))

    assert result["retried"] is False
    assert result["total_detections"] == 5
    assert result["keyframe_indices"] == [0]


if __name__ == "__main__":
    pytest.main([__file__])
