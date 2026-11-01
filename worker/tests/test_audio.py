"""Tests for services.audio conversion (needs ffmpeg on PATH)."""

import shutil
import subprocess
import wave
from pathlib import Path

import pytest

from services.audio import CHANNELS, SAMPLE_RATE, AudioError, to_wav

ROOT = Path(__file__).resolve().parent.parent.parent
VOICE_1 = ROOT / "samples" / "voice" / "voice_1.m4a"

needs_ffmpeg = pytest.mark.skipif(
    shutil.which("ffmpeg") is None, reason="ffmpeg is not on PATH"
)


def wav_params(path: Path):
    with wave.open(str(path), "rb") as wav:
        return wav.getnchannels(), wav.getsampwidth(), wav.getframerate()


def make_tone(path: Path, *args: str):
    """Synthesize 0.5 s of stereo 48 kHz audio in any container."""
    path.parent.mkdir(parents=True, exist_ok=True)
    subprocess.run(
        [
            "ffmpeg",
            "-y",
            "-f",
            "lavfi",
            "-i",
            "sine=frequency=440:duration=0.5",
            "-ac",
            "2",
            "-ar",
            "48000",
            *args,
            str(path),
        ],
        check=True,
        capture_output=True,
    )


@needs_ffmpeg
@pytest.mark.parametrize("name,args", [("note.ogg", []), ("note.webm", [])])
def test_to_wav_converts_containers_to_mono_24k(tmp_path, name, args):
    make_tone(tmp_path / name, *args)

    result = to_wav(tmp_path, name)

    wav = tmp_path / "wav" / "note.wav"
    assert result == {
        "audio_path": name,
        "wav_path": "wav/note.wav",
        "sample_rate": SAMPLE_RATE,
        "channels": CHANNELS,
    }
    assert wav.is_file()
    assert wav_params(wav) == (1, 2, 24000)


@needs_ffmpeg
def test_to_wav_downmixes_and_resamples_wav(tmp_path):
    src = tmp_path / "note.wav"
    make_tone(src)

    result = to_wav(tmp_path, "note.wav")

    wav = tmp_path / "wav" / "note.wav"
    assert result["wav_path"] == "wav/note.wav"
    assert wav.is_file()
    assert wav_params(wav) == (1, 2, 24000)
    # The source is never replaced: the worker may not write next to uploads.
    assert wav_params(src) == (2, 2, 48000)
    assert list((tmp_path / "wav").glob("*.to-wav.wav")) == []


@needs_ffmpeg
def test_to_wav_converts_real_voice_sample(tmp_path):
    assert VOICE_1.exists(), f"test asset missing: {VOICE_1}"
    src = tmp_path / "voice" / "voice_1.m4a"
    src.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy(VOICE_1, src)

    result = to_wav(tmp_path, "voice/voice_1.m4a")

    wav = tmp_path / "wav" / "voice" / "voice_1.wav"
    assert result["wav_path"] == "wav/voice/voice_1.wav"
    assert wav.is_file()
    assert wav_params(wav) == (1, 2, 24000)


@needs_ffmpeg
def test_to_wav_writes_nothing_next_to_source(tmp_path):
    src = tmp_path / "try" / "note.webm"
    make_tone(src)

    result = to_wav(tmp_path, "try/note.webm")

    assert result["wav_path"] == "wav/try/note.wav"
    assert sorted(p.name for p in src.parent.iterdir()) == ["note.webm"]


def test_to_wav_missing_file(tmp_path):
    with pytest.raises(FileNotFoundError, match="Audio not found"):
        to_wav(tmp_path, "voice/gone.ogg")


def test_to_wav_refuses_path_escape(tmp_path):
    with pytest.raises(ValueError, match="must stay inside"):
        to_wav(tmp_path, "../voice/note.ogg")


@needs_ffmpeg
def test_to_wav_rejects_garbage_bytes(tmp_path):
    src = tmp_path / "note.ogg"
    src.write_bytes(b"not audio at all")

    with pytest.raises(AudioError, match="Could not decode audio"):
        to_wav(tmp_path, "note.ogg")
