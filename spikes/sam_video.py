"""Spike 1.1: SAM 3.1 video segmentation on a shelf video.

Pipeline: ffmpeg clip -> Files API upload -> sam-3.1 Responses stream
(parsed with meta_sam_parser) -> overlay.mp4 + sam_raw.txt + summary.

Run from the spikes/ dir inside its venv, e.g.::

    .venv\\Scripts\\Activate.ps1
    python sam_video.py
"""

import argparse
import asyncio
import hashlib
import re
import shutil
import subprocess
import sys
import time
from collections import defaultdict
from collections.abc import Mapping
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import cv2
import numpy as np

from common import get_client
from meta_sam_parser import (
    SegmentationBoxRecord,
    SegmentationMaskRecord,
    decode_mask_to_raster,
    parse_responses_stream,
    video_segmentation_format,
)

ROOT = Path(__file__).resolve().parent.parent
ALPHA = 0.4  # mask fill opacity on the overlay


def slug(text: str) -> str:
    """Lowercase slug used for the default out folder name."""
    return re.sub(r"[^a-z0-9]+", "_", text.lower()).strip("_") or "phrase"


def color_for(object_id: str) -> tuple:
    """Deterministic distinct BGR colour per object id (never near-black)."""
    digest = hashlib.md5(object_id.encode()).hexdigest()[:6]
    value = int(digest, 16)
    return (
        (value & 0xFF) | 0x40,
        ((value >> 8) & 0xFF) | 0x40,
        ((value >> 16) & 0xFF) | 0x40,
    )


def make_clip(video: Path, clip_path: Path) -> None:
    """Transcode to 1280px-wide, 10fps, H.264, no audio, faststart."""
    ffmpeg = shutil.which("ffmpeg")
    if ffmpeg is None:
        raise RuntimeError("ffmpeg was not found on PATH")
    cmd = [
        ffmpeg,
        "-y",
        "-i",
        str(video),
        "-vf",
        "scale=1280:-2,fps=10",
        "-c:v",
        "libx264",
        "-pix_fmt",
        "yuv420p",
        "-an",
        "-movflags",
        "+faststart",
        str(clip_path),
    ]
    subprocess.run(cmd, check=True, capture_output=True, text=True)


def usage_video_frames(usage) -> object:
    """Return usage.video_frames_processed when the API reported it."""
    if usage is None:
        return None
    try:
        if isinstance(usage, Mapping):
            return usage.get("video_frames_processed")
        value = getattr(usage, "video_frames_processed", None)
        if value is not None:
            return value
        dump = getattr(usage, "model_dump", None)
        if callable(dump):
            return dump().get("video_frames_processed")
    except Exception:
        return None
    return None


async def segment(client, clip_path: Path, phrase: str):
    """Upload the clip and run sam-3.1; return (result, elapsed, usage)."""
    with open(clip_path, "rb") as handle:
        uploaded = client.files.create(file=handle, purpose="user_data")
    file_id = uploaded.id

    usage_holder = {}
    started = time.perf_counter()
    stream = client.responses.create(
        model="sam-3.1",
        stream=True,
        input=[
            {
                "role": "user",
                "content": [
                    {"type": "input_text", "text": phrase},
                    {"type": "input_video", "file_id": file_id},
                ],
            }
        ],
        metadata={"include_confidence": "true", "mask_encoding": "one_bit"},
    )

    async def event_source():
        for event in stream:
            if getattr(event, "type", None) == "response.completed":
                usage_holder["usage"] = getattr(
                    getattr(event, "response", None), "usage", None
                )
            yield event

    parsed = parse_responses_stream(event_source(), video_segmentation_format())
    result = await parsed.final_result()
    elapsed = time.perf_counter() - started
    return result, elapsed, usage_holder.get("usage")


def collect_frames(result):
    """Group box/mask records per frame, keyed by object id."""
    frames = defaultdict(dict)
    for record in result.records:
        if isinstance(record, (SegmentationBoxRecord, SegmentationMaskRecord)):
            frame_index = record.frame.frame_index if record.frame is not None else 0
            slot = frames[frame_index].setdefault(
                record.object_id, {"box": None, "mask": None}
            )
            if isinstance(record, SegmentationBoxRecord):
                slot["box"] = record
            else:
                slot["mask"] = record
    return dict(sorted(frames.items()))


def write_overlay(clip_path: Path, overlay_path: Path, frames) -> None:
    """Draw each frame's masks (40% fill, colour per id) and box-corner ids."""
    capture = cv2.VideoCapture(str(clip_path))
    if not capture.isOpened():
        raise RuntimeError(f"Could not open {clip_path}")
    frame_w = int(capture.get(cv2.CAP_PROP_FRAME_WIDTH))
    frame_h = int(capture.get(cv2.CAP_PROP_FRAME_HEIGHT))
    fps = capture.get(cv2.CAP_PROP_FPS) or 10.0
    writer = cv2.VideoWriter(
        str(overlay_path), cv2.VideoWriter_fourcc(*"mp4v"), fps, (frame_w, frame_h)
    )
    try:
        index = 0
        while True:
            ok, frame = capture.read()
            if not ok:
                break
            for object_id, slot in frames.get(index, {}).items():
                box, mask_rec = slot["box"], slot["mask"]
                if box is None or mask_rec is None:
                    continue
                left = max(0, int(box.left))
                top = max(0, int(box.top))
                right = min(frame_w, int(box.right))
                bottom = min(frame_h, int(box.bottom))
                if right <= left or bottom <= top:
                    continue
                mask = mask_rec.mask
                raster = (
                    np.frombuffer(decode_mask_to_raster(mask), dtype=np.uint8)
                    .reshape(mask.height, mask.width)
                    .astype(bool)
                )
                if (mask.width, mask.height) == (frame_w, frame_h):
                    ox, oy = 0, 0
                elif (mask.width, mask.height) == (right - left, bottom - top):
                    ox, oy = left, top
                else:  # unexpected raster size: fit to the box
                    raster = cv2.resize(
                        raster.astype(np.uint8),
                        (right - left, bottom - top),
                        interpolation=cv2.INTER_NEAREST,
                    ).astype(bool)
                    ox, oy = left, top
                full = np.zeros((frame_h, frame_w), dtype=bool)
                rh, rw = raster.shape
                full[oy : oy + rh, ox : ox + rw] |= raster
                colour = np.array(color_for(object_id), dtype=float)
                blended = frame.astype(float) * (1.0 - ALPHA) + colour * ALPHA
                frame[full] = blended[full].astype(np.uint8)
                org = (left, top - 6 if top >= 18 else top + 16)
                cv2.putText(
                    frame, object_id, org, cv2.FONT_HERSHEY_SIMPLEX, 0.7, (0, 0, 0), 3
                )
                cv2.putText(
                    frame,
                    object_id,
                    org,
                    cv2.FONT_HERSHEY_SIMPLEX,
                    0.7,
                    (255, 255, 255),
                    1,
                )
            writer.write(frame)
            index += 1
    finally:
        capture.release()
        writer.release()


def main() -> None:
    parser = argparse.ArgumentParser(description="Spike 1.1: SAM 3.1 on a shelf video")
    parser.add_argument("--video", default="samples/shelf_bottles.mp4")
    parser.add_argument("--phrase", default="bottle")
    parser.add_argument("--out", default=None)
    args = parser.parse_args()

    video = Path(args.video)
    if not video.is_absolute():
        video = ROOT / video
    out = (
        Path(args.out)
        if args.out
        else ROOT / "spikes" / "out" / f"{video.stem}_{slug(args.phrase)}"
    )
    out.mkdir(parents=True, exist_ok=True)

    clip_path = out / "clip.mp4"
    make_clip(video, clip_path)

    client = get_client()
    result, elapsed, usage = asyncio.run(segment(client, clip_path, args.phrase))

    frames = collect_frames(result)
    object_ids = {
        object_id for slots in frames.values() for object_id in slots
    }
    per_frame_counts = [len(slots) for slots in frames.values()]
    confidences = [
        slot["box"].confidence
        for slots in frames.values()
        for slot in slots.values()
        if slot["box"] is not None and slot["box"].confidence is not None
    ]
    if not confidences:
        confidences = [
            slot["mask"].confidence
            for slots in frames.values()
            for slot in slots.values()
            if slot["mask"] is not None and slot["mask"].confidence is not None
        ]

    (out / "sam_raw.txt").write_text(result.raw_output, encoding="utf-8")
    write_overlay(clip_path, out / "overlay.mp4", frames)

    print(f"video: {video}")
    print(f"phrase: {args.phrase}")
    print(f"clip: {clip_path}")
    print(f"frames with output: {len(frames)}")
    print(f"unique object ids: {len(object_ids)}")
    print(f"max objects on a single frame: {max(per_frame_counts, default=0)}")
    if confidences:
        print(f"mean confidence: {sum(confidences) / len(confidences):.3f}")
    else:
        print("mean confidence: n/a (API returned no confidences)")
    print(f"elapsed seconds: {elapsed:.1f}")
    frames_processed = usage_video_frames(usage)
    if frames_processed is not None:
        print(f"video_frames_processed: {frames_processed}")


if __name__ == "__main__":
    main()
