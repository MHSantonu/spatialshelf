# SpatialShelf dev runner (Windows PowerShell, no Makefile needed).
# Starts serve, queue, vite and worker in parallel with name-prefixed output.
# Ctrl+C stops everything and cleans up orphaned children.
#
# Usage:
#   .\dev.ps1            # the four dev processes
#   .\dev.ps1 -Tunnel    # ... plus a cloudflared tunnel to :8000
#   .\dev.ps1 -Phone     # build assets, no Vite (for tunnel use from a phone)

param(
    [switch]$Tunnel,
    [switch]$Phone
)

$ErrorActionPreference = 'Continue'
$Root = $PSScriptRoot
if ([string]::IsNullOrEmpty($Root)) { $Root = Split-Path -Parent $MyInvocation.MyCommand.Path }
# cmd.exe chokes on long-path ('\\?\') prefixes: it cannot use them as the
# working directory and cannot launch executables through them. Strip the
# prefix so `cmd /c` and child processes see ordinary paths.
$Root = $Root -replace '^\\\\\?\\UNC\\', '\\' -replace '^\\\\\?\\', ''

function Get-PortHolders {
    param([int]$Port)
    Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue | ForEach-Object {
        $proc = Get-Process -Id $_.OwningProcess -ErrorAction SilentlyContinue
        [pscustomobject]@{ Port = $Port; Pid = $_.OwningProcess; Name = if ($proc) { $proc.Name } else { 'unknown' } }
    }
}

# 0. Ports must be free before anything starts. Report, never kill here.
$blocked = @((@(8000, 8080) | ForEach-Object { Get-PortHolders $_ }))
if ($blocked.Count -gt 0) {
    foreach ($b in $blocked) {
        Write-Host "Port $($b.Port) is already held by $($b.Name) (PID $($b.Pid))."
    }
    Write-Host 'Stop those processes and re-run .\dev.ps1. Nothing was started.'
    exit 1
}

if ($Phone) {
    # A phone behind the tunnel cannot reach the desktop's Vite dev server,
    # so serve the production build instead: build once, drop the hot-file
    # marker (it would point pages at a Vite that isn't running), and skip
    # the vite job below.
    Write-Host 'Phone mode: building production assets (this takes a bit)...'
    Push-Location "$Root\app"
    try {
        npm run build
        if ($LASTEXITCODE -ne 0) {
            Write-Host 'npm run build failed; not starting anything.'
            exit 1
        }
    } finally {
        Pop-Location
    }
    Remove-Item "$Root\app\public\hot" -Force -ErrorAction SilentlyContinue
    Write-Host 'Assets built; public/hot removed.'
}

$tasks = @(
    @{ Name = 'serve';  Dir = "$Root\app";    Cmd = 'php artisan serve --host=127.0.0.1 --port=8000' },
    @{ Name = 'queue';  Dir = "$Root\app";    Cmd = 'php artisan queue:work --queue=scans,whatsapp,default --timeout=300' },
    @{ Name = 'vite';   Dir = "$Root\app";    Cmd = 'npm run dev' },
    @{ Name = 'worker'; Dir = "$Root\worker"; Cmd = "$Root\worker\.venv\Scripts\python.exe -m uvicorn main:app --host 127.0.0.1 --port 8080 --reload" }
)
if ($Phone) {
    $tasks = @($tasks | Where-Object { $_.Name -ne 'vite' })
}
if ($Tunnel) {
    if (Get-Command cloudflared -ErrorAction SilentlyContinue) {
        $tasks += @{ Name = 'tunnel'; Dir = $Root; Cmd = 'cloudflared tunnel --url http://127.0.0.1:8000' }
    } else {
        Write-Warning 'cloudflared not found on PATH; continuing without a tunnel.'
    }
}

$jobs = foreach ($t in $tasks) {
    Start-Job -Name $t.Name -ScriptBlock {
        param($Dir, $Cmd)
        $ErrorActionPreference = 'Continue'
        $Dir = $Dir -replace '^\\\\\?\\UNC\\', '\\' -replace '^\\\\\?\\', ''
        Set-Location $Dir
        # Merge stderr into stdout inside cmd so uvicorn INFO logs (stderr)
        # arrive as normal output lines instead of job errors.
        cmd /c "$Cmd 2>&1"
    } -ArgumentList $t.Dir, $t.Cmd
}

$names = ($tasks | ForEach-Object { $_.Name }) -join ', '
Write-Host "Running $names. Press Ctrl+C to stop them all."
$tunnelShown = $false
$live = @($jobs)
try {
    while ($live.Count -gt 0) {
        foreach ($j in @($live)) {
            Receive-Job $j -ErrorAction Continue 2>&1 | ForEach-Object {
                $line = "$_"
                Write-Host "[$($j.Name)] $line"
                if (-not $tunnelShown -and $j.Name -eq 'tunnel' -and $line -match 'https://[A-Za-z0-9.-]*trycloudflare\.com\S*') {
                    $tunnelShown = $true
                    Write-Host ''
                    Write-Host "  Tunnel live: $($Matches[0])" -ForegroundColor Green
                    Write-Host ''
                }
            }
            # A job only fails when its process actually exits. Drain it,
            # say so, and keep the others running.
            if ($j.State -in @('Completed', 'Failed', 'Stopped')) {
                Write-Host "[$($j.Name)] exited (state: $($j.State)). The rest keep running."
                $live = @($live | Where-Object { $_.Id -ne $j.Id })
            }
        }
        Start-Sleep -Milliseconds 200
    }
    Write-Host 'All jobs exited.'
}
finally {
    $jobs | Stop-Job -ErrorAction SilentlyContinue
    $jobs | Remove-Job -Force -ErrorAction SilentlyContinue
    # Start-Job grandchildren (cmd wrappers and their php/python/node children)
    # survive Stop-Job, so sweep our own commands by their distinctive command
    # lines, then sweep their children by parent PID: artisan serve spawns a
    # `php -S` child and the uvicorn reloader spawns a server child, and both
    # children carry different command lines.
    $markers = @(
        'artisan serve --host=127.0.0.1 --port=8000',
        'queue:work --queue=scans,whatsapp,default',
        'uvicorn main:app --host 127.0.0.1 --port 8080',
        'npm run dev',
        'cloudflared tunnel --url http://127.0.0.1:8000'
    )
    $victimPids = @()
    $procs = Get-CimInstance Win32_Process -ErrorAction SilentlyContinue
    foreach ($p in $procs) {
        $cmdline = $p.CommandLine
        if ([string]::IsNullOrEmpty($cmdline)) { continue }
        $ours = $false
        if ($p.Name -in @('php.exe', 'python.exe', 'cmd.exe')) {
            foreach ($m in $markers) {
                if ($cmdline.Contains($m)) { $ours = $true; break }
            }
        } elseif ($p.Name -eq 'node.exe' -and $cmdline.Contains('spatialshelf') -and ($cmdline.Contains('vite') -or $cmdline.Contains('npm-cli'))) {
            $ours = $true
        } elseif ($p.Name -eq 'cloudflared.exe' -and $cmdline.Contains('http://127.0.0.1:8000')) {
            $ours = $true
        }
        if ($ours) {
            Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue
            Write-Host "Cleaned $($p.Name) (PID $($p.ProcessId))."
            $victimPids += $p.ProcessId
        }
    }
    for ($i = 0; $i -lt 3 -and $victimPids.Count -gt 0; $i++) {
        Start-Sleep -Milliseconds 300
        $orphans = Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
            $_.ParentProcessId -in $victimPids -and $_.Name -in @('php.exe', 'python.exe', 'node.exe', 'cmd.exe')
        }
        if (-not $orphans) { break }
        foreach ($o in $orphans) {
            Stop-Process -Id $o.ProcessId -Force -ErrorAction SilentlyContinue
            Write-Host "Cleaned orphan $($o.Name) (PID $($o.ProcessId))."
            $victimPids += $o.ProcessId
        }
    }
    Start-Sleep -Milliseconds 500
    $leftover = @(@(8000, 8080) | ForEach-Object { Get-PortHolders $_ })
    if ($leftover.Count -gt 0) {
        foreach ($b in $leftover) {
            Write-Warning "Port $($b.Port) still held by $($b.Name) (PID $($b.Pid)) after cleanup."
        }
    } else {
        Write-Host 'Ports 8000 and 8080 are free.'
    }
    Write-Host 'All dev processes stopped.'
}
