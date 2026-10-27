# SpatialShelf dev runner (Windows PowerShell, no Makefile needed).
# Starts all four processes in parallel with name-prefixed output.
# Ctrl+C stops everything.

$ErrorActionPreference = "Stop"
$Root = $PSScriptRoot
if ([string]::IsNullOrEmpty($Root)) { $Root = Split-Path -Parent $MyInvocation.MyCommand.Path }

$tasks = @(
    @{ Name = "serve";   Dir = "$Root\app";    Cmd = "php artisan serve" },
    @{ Name = "queue";   Dir = "$Root\app";    Cmd = "php artisan queue:work --queue=scans,whatsapp,default --timeout=300" },
    @{ Name = "vite";    Dir = "$Root\app";    Cmd = "npm run dev" },
    @{ Name = "worker";  Dir = "$Root\worker"; Cmd = "$Root\worker\.venv\Scripts\python.exe -m uvicorn main:app --port 8080 --reload" }
)

$jobs = foreach ($t in $tasks) {
    Start-Job -Name $t.Name -ScriptBlock {
        param($Dir, $Cmd)
        Set-Location $Dir
        Invoke-Expression $Cmd
    } -ArgumentList $t.Dir, $t.Cmd
}

Write-Host "Running serve, queue, vite, worker. Press Ctrl+C to stop them all."
try {
    while ($true) {
        foreach ($j in $jobs) {
            Receive-Job $j | ForEach-Object { "[$($j.Name)] $_" }
            if ($j.State -eq "Failed") {
                Write-Warning "[$($j.Name)] job failed; stopping the rest."
                throw "Job $($j.Name) failed."
            }
        }
        Start-Sleep -Milliseconds 200
    }
}
finally {
    $jobs | Stop-Job -ErrorAction SilentlyContinue
    $jobs | Remove-Job -Force -ErrorAction SilentlyContinue
    Write-Host "All dev processes stopped."
}
