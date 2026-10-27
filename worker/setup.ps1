# SpatialShelf scan worker setup (Windows PowerShell, no Docker).
# Creates worker/.venv with Python 3.11, installs requirements (torch CPU),
# and pre-downloads facebook/dinov2-small.

$ErrorActionPreference = "Stop"

$Python311 = "C:\Users\User\AppData\Local\Programs\Python\Python311\python.exe"
$Worker = $PSScriptRoot
if ([string]::IsNullOrEmpty($Worker)) { $Worker = Split-Path -Parent $MyInvocation.MyCommand.Path }

if (-not (Test-Path $Python311)) {
    throw "Python 3.11 not found at $Python311. Install Python 3.11 and re-run."
}

& $Python311 -m venv "$Worker\.venv"
& "$Worker\.venv\Scripts\python.exe" -m pip install --upgrade pip
& "$Worker\.venv\Scripts\python.exe" -m pip install -r "$Worker\requirements.txt" --extra-index-url https://download.pytorch.org/whl/cpu

# Pre-download facebook/dinov2-small so first embedding is fast.
& "$Worker\.venv\Scripts\python.exe" -c "from transformers import AutoModel, AutoImageProcessor; AutoImageProcessor.from_pretrained('facebook/dinov2-small'); AutoModel.from_pretrained('facebook/dinov2-small')"

Write-Host "Worker venv ready at $Worker\.venv"
