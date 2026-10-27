"""Spike 1.2: SAM 3.1 image mode on evenly spaced shelf frames.

Pipeline: ffmpeg extracts N evenly spaced JPEGs -> concurrent sam-3.1
image requests (base64 data URL, streamed) parsed with meta_sam_parser ->
per-frame overlay PNGs + printed stats, compared against video mode.

Run from the spikes/ dir inside its venv, e.g.::

    .venv\\Scripts\\Activate.ps1
    python sam_frames.py
"""

import argparse
import asyncio
import base64
import shutil
import subprocess
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import cv2
import numpy as np

from common import get_client
from meta_sam_parser import (
    SegmentationBoxRecord,
    SegmentationMaskRecord,
    decode_mask_to_raster,
    image_segmentation_format,
    parse_responses_stream,
)
from sam_video import color_for, slug

ROOT = Path(__file__).resolve().parent.parent
ALPHA = 0.4  # mask fill opacity on the overlay
MIN_CONFIDENCE = 0.5
VIDEO_MODE_UNIQUE = 16  # Spike 1.1 result on the same clip, for comparison


def pick_indices(count: int, n: int) -> list:
    """N evenly spaced frame indices in [0, count), deduplicated."""
    if count <= 0:
        raise RuntimeError("Could not determine the video frame count")
    if n <= 0:
        raise RuntimeError("--frames must be at least 1")
    if n == 1:
        return [0]
    indices = [round(i * (count - 1) / (n - 1)) for i in range(n)]
    return list(dict.fromkeys(indices))


def extract_frames(video: Path, out: Path, indices: list) -> list:
    """Extract the given source frames with ffmpeg as JPEGs."""
    ffmpeg = shutil.which("ffmpeg")
    if ffmpeg is None:
        raise RuntimeError("ffmpeg was not found on PATH")
    # Quoteless select: argv goes to ffmpeg verbatim on Windows, and single
    # quotes inside -vf break some builds. Commas stay backslash-escaped.
    select = "+".join(f"eq(n\\,{i})" for i in indices)
    cmd = [
        ffmpeg,
        "-y",
        "-i",
        str(video),
        "-vf",
        f"select={select},scale=1280:-2",
        "-fps_mode",
        "passthrough",
        "-start_number",
        "1",
        "-q:v",
        "3",
        str(out / "extract_%02d.jpg"),
    ]
    try:
        subprocess.run(cmd, check=True, capture_output=True, text=True)
    except subprocess.CalledProcessError as error:
        tail = (error.stderr or "")[-2000:]
        raise RuntimeError(f"ffmpeg failed:\n{tail}") from error
    paths = []
    for order, index in enumerate(indices):
        src = out / f"extract_{order + 1:02d}.jpg"
        dst = out / f"frame_{index}.jpg"
        src.rename(dst)
        paths.append(dst)
    return paths


def fetch_events(client, jpg_path: Path, phrase: str):
    """Run one streamed sam-3.1 image request; return (events, seconds)."""
    data_url = "data:image/jpeg;base64," + base64.b64encode(
        jpg_path.read_bytes()
    ).decode("ascii")
    started = time.perf_counter()
    stream = client.responses.create(
        model="sam-3.1",
        stream=True,
        input=[
            {
                "role": "user",
                "content": [
                    {"type": "input_text", "text": phrase},
                    {"type": "input_image", "image_url": data_url},
                ],
            }
        ],
        metadata={"include_confidence": "true", "mask_encoding": "one_bit"},
    )
    events = list(stream)
    return events, time.perf_counter() - started


async def parse_events(events) -> object:
    """Parse materialized stream events with the image format."""

    async def event_source():
        for event in events:
            yield event

    parsed = parse_responses_stream(event_source(), image_segmentation_format())
    return await parsed.final_result()


def kept_slots(result) -> dict:
    """Group box/mask records by object id, dropping confidence < 0.5."""
    slots = {}
    for record in result.records:
        if not isinstance(record, (SegmentationBoxRecord, SegmentationMaskRecord)):
            continue
        confidence = record.confidence if record.confidence is not None else 0.0
        if confidence < MIN_CONFIDENCE:
            continue
        slot = slots.setdefault(record.object_id, {"box": None, "mask": None})
        if isinstance(record, SegmentationBoxRecord):
            slot["box"] = record
        else:
            slot["mask"] = record
    return slots


def draw_overlay(jpg_path: Path, png_path: Path, slots: dict) -> None:
    """Draw masks (40% fill, colour per id) and box-corner ids onto the frame."""
    img = cv2.imread(str(jpg_path), cv2.IMREAD_COLOR)
    if img is None:
        raise RuntimeError(f"Could not read {jpg_path}")
    frame_h, frame_w = img.shape[:2]
    for object_id, slot in slots.items():
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
        blended = img.astype(float) * (1.0 - ALPHA) + colour * ALPHA
        img[full] = blended[full].astype(np.uint8)
        org = (left, top - 6 if top >= 18 else top + 16)
        cv2.putText(
            img, object_id, org, cv2.FONT_HERSHEY_SIMPLEX, 0.7, (0, 0, 0), 3
        )
        cv2.putText(
            img, object_id, org, cv2.FONT_HERSHEY_SIMPLEX, 0.7, (255, 255, 255), 1
        )
    cv2.imwrite(str(png_path), img)


async def run_all(client, jpg_paths: list, phrase: str) -> list:
    """Fetch all frames concurrently, then parse each result in order."""

    async def one(jpg_path: Path):
        try:
            events, seconds = await asyncio.to_thread(
                fetch_events, client, jpg_path, phrase
            )
        except Exception as error:
            raise RuntimeError(f"request for {jpg_path.name} failed") from error
        result = await parse_events(events)
        return result, seconds

    return list(await asyncio.gather(*(one(p) for p in jpg_paths)))


def main() -> None:
    parser = argparse.ArgumentParser(description="Spike 1.2: SAM 3.1 image mode")
    parser.add_argument("--video", default="samples/shelf_bottles.mp4")
    parser.add_argument("--phrase", default="bottle")
    parser.add_argument("--frames", type=int, default=6)
    args = parser.parse_args()

    video = Path(args.video)
    if not video.is_absolute():
        video = ROOT / video
    out = ROOT / "spikes" / "out" / f"{video.stem}_{slug(args.phrase)}_frames"
    out.mkdir(parents=True, exist_ok=True)

    capture = cv2.VideoCapture(str(video))
    count = int(capture.get(cv2.CAP_PROP_FRAME_COUNT) or 0)
    capture.release()
    indices = pick_indices(count, args.frames)
    jpg_paths = extract_frames(video, out, indices)

    client = get_client()
    results = asyncio.run(run_all(client, jpg_paths, args.phrase))

    print(f"video: {video}")
    print(f"phrase: {args.phrase}")
    totals = []
    for index, jpg_path, (result, seconds) in zip(indices, jpg_paths, results):
        (out / f"frame_{index}_raw.txt").write_text(
            result.raw_output, encoding="utf-8"
        )
        slots = kept_slots(result)
        confidences = [
            slot["box"].confidence
            for slot in slots.values()
            if slot["box"] is not None and slot["box"].confidence is not None
        ]
        totals.append(len(slots))
        mean = f"{sum(confidences) / len(confidences):.3f}" if confidences else "n/a"
        print(
            f"frame {index} ({jpg_path.name}): "
            f"instances={len(slots)} mean_confidence={mean} seconds={seconds:.1f}"
        )
        draw_overlay(jpg_path, out / f"frame_{index}_overlay.png", slots)

    print(f"total instances (all frames): {sum(totals)}")
    print(f"max on any single frame: {max(totals, default=0)}")
    print(f"video-mode unique objects: {VIDEO_MODE_UNIQUE}")
    print(
        f"image-mode max on a single frame vs video-mode unique: "
        f"{max(totals, default=0)} vs {VIDEO_MODE_UNIQUE}"
    )


if __name__ == "__main__":
    main()
