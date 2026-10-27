# Spikes

Throwaway day-1 probes against the Meta Model API. Nothing here ships.

## Setup

```powershell
cd spikes
C:\Users\User\AppData\Local\Programs\Python\Python311\python.exe -m venv .venv
.venv\Scripts\Activate.ps1
pip install -r requirements.txt
```

## Run

Each spike is a standalone script. Model output goes to `out/` (gitignored):

```powershell
python <spike_name>.py
```

Shared setup lives in `common.py`: it loads `MODEL_API_KEY` from the
repo-root `.env` and exposes an OpenAI client with
`base_url="https://api.meta.ai/v1"`.
