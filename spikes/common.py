"""Shared helpers for day-1 spikes.

Loads MODEL_API_KEY from the repo-root .env and exposes an OpenAI-compatible
client pointed at the Meta Model API (https://api.meta.ai/v1).
"""

from pathlib import Path

from dotenv import load_dotenv
import os
from openai import OpenAI

ROOT = Path(__file__).resolve().parent.parent
load_dotenv(ROOT / ".env")

META_BASE_URL = os.getenv("META_BASE_URL", "https://api.meta.ai/v1")


def get_client() -> OpenAI:
    api_key = os.getenv("MODEL_API_KEY")
    if not api_key:
        raise RuntimeError("MODEL_API_KEY is missing in the root .env")
    return OpenAI(api_key=api_key, base_url=META_BASE_URL)
