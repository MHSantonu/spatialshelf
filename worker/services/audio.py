"""Audio prep: convert any voice note to ASR-ready WAV with ffmpeg.

Mono 16-bit PCM at 24 kHz with metadata stripped, ported from
spikes/parse_order.py convert_to_wav(). Input may be ogg/opus from
WhatsApp, webm from browsers, m4a, or anything else ffmpeg decodes.

All paths in and out are relative to SHARED_STORAGE_PATH; the WAV lands
next to the source file under the same stem.
"""

from __future__ import annotations

import shutil
import subprocess
import wave
from pathlib import Path

SAMPLE_RATE = 24000
CHANNELS = 1


class AudioError(ValueError):
    """No decodable audio stream. The API maps this to HTTP 422."""


def _ffmpeg() -> str:
    path = shutil.which("ffmpeg")
    if path is None:
        raise RuntimeError("ffmpeg was not found on PATH")
    return path


def to_wav(shared_root: Path, audio_path: str) -> dict:
    """Convert one audio file to mono 16-bit PCM WAV at 24 kHz."""
    root = shared_root.resolve()
    src = (root / audio_path).resolve()
    if src != root and root not in src.parents:
        raise ValueError("audio_path must stay inside SHARED_STORAGE_PATH")
    if not src.is_file():
        raise FileNotFoundError(f"Audio not found: {audio_path}")

    final = src.with_suffix(".wav")
    dst = final
    if dst == src:
        # Never read and write the same file; convert aside, then replace.
        dst = src.with_name(f"{src.stem}.to-wav.wav")
    cmd = [
        _ffmpeg(),
        "-y",
        "-i",
        str(src),
        "-ac",
        str(CHANNELS),
        "-ar",
        str(SAMPLE_RATE),
        "-c:a",
        "pcm_s16le",
        "-map_metadata",
        "-1",
        str(dst),
    ]
    try:
        subprocess.run(cmd, check=True, capture_output=True, text=True)
    except subprocess.CalledProcessError as error:
        tail = (error.stderr or "")[-2000:]
        raise AudioError(f"Could not decode audio {src.name}:\n{tail}") from error
    if dst != final:
        dst.replace(final)
        dst = final
    _check_wav(dst, src.name)

    return {
        "audio_path": audio_path,
        "wav_path": dst.relative_to(root).as_posix(),
        "sample_rate": SAMPLE_RATE,
        "channels": CHANNELS,
    }


def _check_wav(path: Path, src_name: str) -> None:
    """Refuse anything that is not mono 16-bit PCM at 24 kHz."""
    try:
        with wave.open(str(path), "rb") as wav:
            params = (
                wav.getnchannels(),
                wav.getsampwidth(),
                wav.getframerate(),
            )
    except (OSError, wave.Error) as error:
        raise AudioError(f"ffmpeg produced no WAV for {src_name}: {error}")
    if params != (CHANNELS, 2, SAMPLE_RATE):
        raise AudioError(
            f"ffmpeg produced {params[0]}ch/{params[2]}Hz for {src_name}; "
            f"expected {CHANNELS}ch/{SAMPLE_RATE}Hz."
        )
