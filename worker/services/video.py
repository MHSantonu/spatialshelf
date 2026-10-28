"""Video prep: downsample and extract frames with ffmpeg/ffprobe.

All paths in and out are relative to SHARED_STORAGE_PATH. Phone clips
with a rotation flag are baked upright into the pixels (ffmpeg
autorotate); outputs carry no rotate tag.

Transcode and keyframe logic is ported from spikes/sam_video.py
(make_clip) and spikes/sam_frames.py (extract_frames, pick_indices).
"""

from __future__ import annotations

import json
import math
import shutil
import subprocess
from pathlib import Path

import cv2

from services.positions import estimate_shift

LONG_SIDE = 1280
CLIP_FPS = 10
KEYFRAME_COUNT = 8
KEYFRAME_QUALITY = 3  # -q:v, same as the spikes
MIN_DURATION = 1.5
MAX_DURATION = 60.0
EDGE_SKIP = 0.05  # keyframes skip the first/last 5% of the clip
KEYFRAME_SECONDS = 0.4  # one candidate keyframe per ~0.4 s of clip
MIN_KEYFRAMES = 3  # ...but never fewer than 3 candidates
SAME_VIEW_SHIFT = 0.03  # max phase shift (fraction of width) for one view
SAME_VIEW_RESPONSE = 0.5  # min correlation to trust that shift


class VideoError(ValueError):
    """Unreadable or out-of-range clip. The API maps this to HTTP 422."""


def _ffmpeg() -> str:
    path = shutil.which("ffmpeg")
    if path is None:
        raise RuntimeError("ffmpeg was not found on PATH")
    return path


def _ffprobe() -> str:
    path = shutil.which("ffprobe")
    if path is None:
        raise RuntimeError("ffprobe was not found on PATH")
    return path


def _run(cmd: list[str]) -> subprocess.CompletedProcess[str]:
    try:
        return subprocess.run(cmd, check=True, capture_output=True, text=True)
    except subprocess.CalledProcessError as error:
        tail = (error.stderr or "")[-2000:]
        raise RuntimeError(f"{Path(cmd[0]).name} failed:\n{tail}") from error


def _parse_float(value: object) -> float | None:
    try:
        result = float(value)  # type: ignore[arg-type]
    except (TypeError, ValueError):
        return None
    return result if result > 0 else None


def _parse_rate(value: object) -> float | None:
    if not isinstance(value, str) or "/" not in value:
        return None
    num, _, den = value.partition("/")
    try:
        fps = float(num) / float(den)
    except (ValueError, ZeroDivisionError):
        return None
    return fps if fps > 0 else None


def _rotation_of(stream: dict) -> int:
    """Rotation flag in degrees, normalized to {0, 90, 180, 270}."""
    raw = (stream.get("tags") or {}).get("rotate")
    if raw is None:
        for side_data in stream.get("side_data_list") or []:
            if "rotation" in side_data:
                raw = side_data["rotation"]
                break
    if raw is None:
        return 0
    try:
        return int(float(raw)) % 360
    except (TypeError, ValueError):
        return 0


def probe_video(path: Path) -> dict:
    """ffprobe the first video stream. width/height are upright pixels."""
    out = _run(
        [
            _ffprobe(),
            "-v",
            "error",
            "-show_streams",
            "-show_format",
            "-of",
            "json",
            str(path),
        ]
    )
    data = json.loads(out.stdout or "{}")
    stream = next(
        (s for s in data.get("streams", []) if s.get("codec_type") == "video"),
        None,
    )
    if stream is None:
        raise VideoError(f"No video stream found in {path.name}")
    try:
        stored_width, stored_height = int(stream["width"]), int(stream["height"])
    except (KeyError, TypeError, ValueError):
        raise VideoError(f"Could not read video dimensions of {path.name}")
    rotation = _rotation_of(stream)
    duration = _parse_float(stream.get("duration"))
    if duration is None:
        duration = _parse_float((data.get("format") or {}).get("duration"))
    fps = _parse_rate(stream.get("avg_frame_rate")) or _parse_rate(
        stream.get("r_frame_rate")
    )
    try:
        frame_count: int | None = int(stream.get("nb_frames"))
    except (TypeError, ValueError):
        frame_count = None
    if frame_count is None and duration is not None and fps:
        frame_count = int(round(duration * fps))
    upright = rotation in (90, 270)
    return {
        "stored_width": stored_width,
        "stored_height": stored_height,
        "width": stored_height if upright else stored_width,
        "height": stored_width if upright else stored_height,
        "rotation": rotation,
        "duration": duration,
        "fps": fps,
        "frame_count": frame_count,
    }


def target_dims(
    width: int, height: int, long_side: int = LONG_SIDE
) -> tuple[int, int]:
    """Scale upright dims so the long side is long_side; short side stays even."""
    scale = long_side / max(width, height)
    short = max(2, int(round(min(width, height) * scale / 2) * 2))
    return (long_side, short) if width >= height else (short, long_side)


def keyframe_count(duration: float) -> int:
    """Candidate keyframes: one per ~0.4 s, clamped to 3..8."""
    return min(
        KEYFRAME_COUNT, max(MIN_KEYFRAMES, math.floor(duration / KEYFRAME_SECONDS + 1e-9))
    )


def _sharpness(gray) -> float:
    """Laplacian variance; higher means sharper."""
    return float(cv2.Laplacian(gray, cv2.CV_64F).var())


def dedupe_views(paths: list[Path]) -> list[int]:
    """Collapse consecutive same-view candidates, keeping the sharpest.

    Returns the positions into `paths` to keep, in order. Frames whose
    phase shift is under 3% of the width (with correlation >= 0.5) show
    the same view; ties keep the earlier frame.
    """
    grays = []
    for path in paths:
        gray = cv2.imread(str(path), cv2.IMREAD_GRAYSCALE)
        if gray is None:
            raise FileNotFoundError(f"Could not read keyframe: {path}")
        grays.append(gray)
    if not grays:
        return []
    keep = [0]
    for pos in range(1, len(grays)):
        prev = grays[keep[-1]]
        dx, dy, response = estimate_shift(prev, grays[pos])
        if (
            response >= SAME_VIEW_RESPONSE
            and math.hypot(dx, dy) < SAME_VIEW_SHIFT * prev.shape[1]
        ):
            if _sharpness(grays[pos]) > _sharpness(prev):
                keep[-1] = pos
        else:
            keep.append(pos)
    return keep


def pick_indices(
    count: int, n: int = KEYFRAME_COUNT, edge: float = EDGE_SKIP
) -> list[int]:
    """N evenly spaced frame indices, skipping the first/last edge fraction."""
    if count <= 0:
        raise VideoError("Could not determine the video frame count")
    if n <= 0:
        raise VideoError("Keyframe count must be at least 1")
    lo = math.floor(count * edge)
    hi = math.ceil(count * (1 - edge)) - 1
    if hi <= lo:
        lo, hi = 0, count - 1
    if n == 1 or hi == lo:
        return [lo]
    indices = [round(lo + i * (hi - lo) / (n - 1)) for i in range(n)]
    return list(dict.fromkeys(indices))


def make_clip(src: Path, dst: Path, width: int, height: int) -> None:
    """Transcode to upright long-side-1280, 10 fps, H.264, no audio, faststart."""
    dst.parent.mkdir(parents=True, exist_ok=True)
    cmd = [
        _ffmpeg(),
        "-y",
        "-autorotate",
        "-i",
        str(src),
        "-vf",
        f"scale={width}:{height},fps={CLIP_FPS}",
        "-c:v",
        "libx264",
        "-pix_fmt",
        "yuv420p",
        "-an",
        "-metadata:s:v:0",
        "rotate=0",
        "-movflags",
        "+faststart",
        str(dst),
    ]
    _run(cmd)


def extract_frames(
    src: Path,
    out_dir: Path,
    indices: list[int],
    size: tuple[int, int] | None = None,
) -> list[Path]:
    """Extract source frames as kf_0.jpg…; downscale too when size is given."""
    out_dir.mkdir(parents=True, exist_ok=True)
    # Quoteless select: argv goes to ffmpeg verbatim on Windows, and single
    # quotes inside -vf break some builds. Commas stay backslash-escaped.
    select = "+".join(f"eq(n\\,{i})" for i in indices)
    vf = f"select={select}"
    if size is not None:
        vf += f",scale={size[0]}:{size[1]}"
    cmd = [
        _ffmpeg(),
        "-y",
        "-autorotate",
        "-i",
        str(src),
        "-vf",
        vf,
        "-fps_mode",
        "passthrough",
        "-start_number",
        "0",
        "-q:v",
        str(KEYFRAME_QUALITY),
        str(out_dir / "kf_%d.jpg"),
    ]
    _run(cmd)
    paths = [out_dir / f"kf_{k}.jpg" for k in range(len(indices))]
    missing = [p.name for p in paths if not p.is_file()]
    if missing:
        raise RuntimeError(f"ffmpeg skipped keyframes: {', '.join(missing)}")
    return paths


def _source_frame_count(src: Path, probed: dict) -> int:
    if probed["frame_count"]:
        return probed["frame_count"]
    try:
        import cv2
    except ImportError:
        cv2 = None  # type: ignore[assignment]
    if cv2 is not None:
        capture = cv2.VideoCapture(str(src))
        try:
            count = int(capture.get(cv2.CAP_PROP_FRAME_COUNT) or 0)
        finally:
            capture.release()
        if count > 0:
            return count
    raise VideoError(f"Could not determine the frame count of {src.name}")


def prepare_scan(shared_root: Path, scan_id: str, video_path: str) -> dict:
    """Build clip.mp4 plus scaled and full-res keyframes for one scan."""
    root = shared_root.resolve()
    src = (root / video_path).resolve()
    if src != root and root not in src.parents:
        raise ValueError("video_path must stay inside SHARED_STORAGE_PATH")
    if not src.is_file():
        raise FileNotFoundError(f"Video not found: {video_path}")

    probed = probe_video(src)
    duration = probed["duration"]
    if duration is None:
        raise VideoError(f"Could not determine the duration of {src.name}")
    if duration < MIN_DURATION or duration > MAX_DURATION:
        raise VideoError(
            f"Video is {duration:.1f} s long; clips must be between "
            f"{MIN_DURATION} s and {MAX_DURATION} s."
        )
    count = _source_frame_count(src, probed)
    wanted = keyframe_count(duration)
    indices = pick_indices(count, wanted, EDGE_SKIP)
    if len(indices) < wanted:
        raise VideoError(
            f"Clip has only {count} frames; need at least "
            f"{wanted} distinct keyframes."
        )

    scan_dir = root / "scans" / scan_id
    kf_dir = scan_dir / "keyframes"
    full_dir = scan_dir / "keyframes_full"
    cand_dir = scan_dir / "candidates"
    full_cand_dir = scan_dir / "candidates_full"
    clip_path = scan_dir / "clip.mp4"
    for out_dir in (kf_dir, full_dir, cand_dir, full_cand_dir):
        out_dir.mkdir(parents=True, exist_ok=True)
        for stale in out_dir.glob("kf_*.jpg"):
            stale.unlink()

    clip_size = target_dims(probed["width"], probed["height"])
    make_clip(src, clip_path, *clip_size)
    candidates = extract_frames(src, cand_dir, indices, clip_size)
    keep = dedupe_views(candidates)
    for new, pos in enumerate(keep):
        shutil.copy(candidates[pos], kf_dir / f"kf_{new}.jpg")
    kept_indices = [indices[pos] for pos in keep]
    extract_frames(src, full_dir, kept_indices)
    extract_frames(src, full_cand_dir, indices)
    (cand_dir / "kept.json").write_text(json.dumps({"kept": keep}))

    clip = probe_video(clip_path)
    full_first = probe_video(full_dir / "kf_0.jpg")
    clip_duration = clip["duration"] or duration
    clip_frames = clip["frame_count"] or int(round(clip_duration * CLIP_FPS))

    def rel(path: Path) -> str:
        return path.relative_to(root).as_posix()

    return {
        "scan_id": scan_id,
        "clip_path": rel(clip_path),
        "clip_width": clip["width"],
        "clip_height": clip["height"],
        "full_width": full_first["width"],
        "full_height": full_first["height"],
        "frame_count": clip_frames,
        "duration": round(clip_duration, 3),
        "fps": CLIP_FPS,
        "rotation": probed["rotation"],
        "source_frame_count": count,
        "source_frame_indices": kept_indices,
        "dropped_views": len(indices) - len(kept_indices),
        "candidate_count": len(indices),
        "keyframes": [rel(kf_dir / f"kf_{k}.jpg") for k in range(len(kept_indices))],
        "keyframes_full": [
            rel(full_dir / f"kf_{k}.jpg") for k in range(len(kept_indices))
        ],
    }
