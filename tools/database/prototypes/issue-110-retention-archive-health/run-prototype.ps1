param(
    [switch]$SkipLarge,
    [switch]$KeepScratch
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$prototypeRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$scratchRoot = Join-Path $env:TEMP 'kiwi-retention-issue-110-prototype'
$downloadRoot = Join-Path $scratchRoot 'downloads'
$runtimeRoot = Join-Path $scratchRoot 'runtime'
$dataRoot = Join-Path $scratchRoot 'mariadb-data'
$depsRoot = Join-Path $scratchRoot 'python-deps'
$workRoot = Join-Path $scratchRoot 'work'
$resultRoot = Join-Path $prototypeRoot ('results\' + (Get-Date -Format 'yyyyMMdd-HHmmss'))

$archiveName = 'mariadb-11.8.8-winx64.zip'
$archivePath = Join-Path $downloadRoot $archiveName
$archiveUrl = 'https://downloads.mariadb.org/rest-api/mariadb/11.8.8/mariadb-11.8.8-winx64.zip'
$archiveSha256 = '20871a79964e1819ddaad9247b676b9d08c958c345e5e3d4748242b2b2965ff1'
$mariaRoot = Join-Path $runtimeRoot 'mariadb-11.8.8-winx64'
$mariaBin = Join-Path $mariaRoot 'bin'
$serverPath = Join-Path $mariaBin 'mariadbd.exe'
$installerPath = Join-Path $mariaBin 'mariadb-install-db.exe'
$adminPath = Join-Path $mariaBin 'mariadb-admin.exe'
$configPath = Join-Path $dataRoot 'my.ini'
$serverLog = Join-Path $scratchRoot 'mariadb-server.log'
$serverPidFile = Join-Path $scratchRoot 'mariadb.pid'
$runStatusPath = Join-Path $scratchRoot 'run-status.json'
$port = 33110
$password = 'kiwi_proto_only'
$serverProcess = $null

function Assert-ScratchBoundary {
    param([string]$Path)

    $tempRoot = [System.IO.Path]::GetFullPath($env:TEMP).TrimEnd('\')
    $resolved = [System.IO.Path]::GetFullPath($Path).TrimEnd('\')
    $expected = [System.IO.Path]::GetFullPath(
        (Join-Path $env:TEMP 'kiwi-retention-issue-110-prototype')
    ).TrimEnd('\')

    if ($resolved -ne $expected -or !$resolved.StartsWith($tempRoot + '\', [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Refusing destructive scratch operation outside the exact prototype boundary: $resolved"
    }
}

function Get-Sha256 {
    param([string]$Path)

    $stream = [System.IO.File]::OpenRead($Path)
    try {
        $sha = [System.Security.Cryptography.SHA256]::Create()
        try {
            return ([System.BitConverter]::ToString($sha.ComputeHash($stream))).Replace('-', '').ToLowerInvariant()
        }
        finally {
            $sha.Dispose()
        }
    }
    finally {
        $stream.Dispose()
    }
}

function Test-MariaReady {
    if (!(Test-Path -LiteralPath $adminPath)) {
        return $false
    }

    & $adminPath `
        --protocol=tcp `
        --host=127.0.0.1 `
        --port=$port `
        --user=root `
        --password=$password `
        --disable-ssl `
        ping *> $null

    return $LASTEXITCODE -eq 0
}

function Stop-ScratchMaria {
    if (Test-MariaReady) {
        & $adminPath `
            --protocol=tcp `
            --host=127.0.0.1 `
            --port=$port `
            --user=root `
            --password=$password `
            --disable-ssl `
            shutdown *> $null
    }

    if ($null -ne $serverProcess) {
        $stillRunning = Get-Process -Id $serverProcess.Id -ErrorAction SilentlyContinue
        if ($null -ne $stillRunning) {
            $actualPath = [System.IO.Path]::GetFullPath($stillRunning.Path)
            $expectedPath = [System.IO.Path]::GetFullPath($serverPath)
            if ($actualPath -ne $expectedPath) {
                throw "Refusing to stop unexpected process path: $actualPath"
            }
            Stop-Process -Id $stillRunning.Id -Force
        }
    }
}

Assert-ScratchBoundary -Path $scratchRoot
New-Item -ItemType Directory -Force -Path $downloadRoot, $workRoot, $resultRoot | Out-Null
if (Test-Path -LiteralPath $runStatusPath) {
    Remove-Item -LiteralPath $runStatusPath -Force
}

try {
    $downloadRequired = $true
    if (Test-Path -LiteralPath $archivePath) {
        $existingHash = Get-Sha256 -Path $archivePath
        $downloadRequired = $existingHash -ne $archiveSha256
    }

    if ($downloadRequired) {
        curl.exe -L --fail --show-error --output $archivePath $archiveUrl
        if ($LASTEXITCODE -ne 0) {
            throw "MariaDB download failed with exit code $LASTEXITCODE"
        }
    }

    $actualHash = Get-Sha256 -Path $archivePath
    if ($actualHash -ne $archiveSha256) {
        throw "MariaDB ZIP SHA-256 mismatch"
    }

    if (!(Test-Path -LiteralPath $serverPath)) {
        if (Test-Path -LiteralPath $runtimeRoot) {
            Remove-Item -LiteralPath $runtimeRoot -Recurse -Force
        }
        New-Item -ItemType Directory -Force -Path $runtimeRoot | Out-Null
        Expand-Archive -LiteralPath $archivePath -DestinationPath $runtimeRoot
    }

    if (Test-Path -LiteralPath $dataRoot) {
        Remove-Item -LiteralPath $dataRoot -Recurse -Force
    }

    & $installerPath `
        --datadir=$dataRoot `
        --password=$password `
        --port=$port `
        --allow-remote-root-access `
        --silent

    if ($LASTEXITCODE -ne 0 -or !(Test-Path -LiteralPath $configPath)) {
        throw "Portable MariaDB initialization failed"
    }

    if (!(Test-Path -LiteralPath (Join-Path $depsRoot 'pymysql'))) {
        python -m pip install `
            --disable-pip-version-check `
            --no-warn-script-location `
            --target $depsRoot `
            'PyMySQL==1.1.2' `
            'psutil==7.1.3' `
            'tzdata==2025.2'
        if ($LASTEXITCODE -ne 0) {
            throw "Scratch Python dependency installation failed"
        }
    }

    $spawnTimer = [System.Diagnostics.Stopwatch]::StartNew()
    $serverProcess = Start-Process `
        -FilePath $serverPath `
        -ArgumentList @(
            "--defaults-file=$configPath",
            '--bind-address=127.0.0.1',
            "--log-error=$serverLog",
            '--console'
        ) `
        -PassThru `
        -WindowStyle Hidden
    $spawnTimer.Stop()

    if ($spawnTimer.Elapsed.TotalSeconds -gt 5) {
        throw "MariaDB spawn did not return promptly"
    }

    $serverProcess.Id | Set-Content -LiteralPath $serverPidFile
    $deadline = [DateTime]::UtcNow.AddSeconds(20)
    while ([DateTime]::UtcNow -lt $deadline -and !(Test-MariaReady)) {
        Start-Sleep -Milliseconds 200
    }
    if (!(Test-MariaReady)) {
        throw "MariaDB did not become ready within 20 seconds"
    }

    $env:PYTHONPATH = $depsRoot
    $env:KIWI_PROTO_DB_HOST = '127.0.0.1'
    $env:KIWI_PROTO_DB_PORT = [string]$port
    $env:KIWI_PROTO_DB_USER = 'root'
    $env:KIWI_PROTO_DB_PASSWORD = $password
    $env:KIWI_PROTO_MARIADB_PID = [string]$serverProcess.Id

    $arguments = @(
        (Join-Path $prototypeRoot 'prototype.py'),
        'full',
        '--scratch', $workRoot,
        '--results', $resultRoot
    )
    if ($SkipLarge) {
        $arguments += '--skip-large'
    }

    $prototypeStdout = Join-Path $resultRoot 'prototype.stdout.log'
    $prototypeStderr = Join-Path $resultRoot 'prototype.stderr.log'
    $pythonPath = (Get-Command python -ErrorAction Stop).Source
    $pythonProcess = Start-Process `
        -FilePath $pythonPath `
        -ArgumentList $arguments `
        -PassThru `
        -Wait `
        -WindowStyle Hidden `
        -RedirectStandardOutput $prototypeStdout `
        -RedirectStandardError $prototypeStderr
    if ($pythonProcess.ExitCode -ne 0) {
        throw "Prototype returned exit code $($pythonProcess.ExitCode)"
    }

    @{
        success = $true
        result_directory = $resultRoot
        finished_at = [DateTime]::UtcNow.ToString('o')
    } | ConvertTo-Json | Set-Content -LiteralPath $runStatusPath
    Write-Output "Prototype results: $resultRoot"
}
catch {
    $safeMessage = [string]$_.Exception.Message
    $safeMessage = $safeMessage.Replace($password, '[redacted]')
    $safeMessage = $safeMessage.Replace($scratchRoot, '[scratch]')
    $safeMessage = $safeMessage.Replace($prototypeRoot, '[prototype]')
    @{
        success = $false
        error_code = 'prototype_launcher_failed'
        error_message = $safeMessage
        finished_at = [DateTime]::UtcNow.ToString('o')
    } | ConvertTo-Json | Set-Content -LiteralPath $runStatusPath
    throw
}
finally {
    Stop-ScratchMaria

    if (!$KeepScratch) {
        if (Test-Path -LiteralPath $workRoot) {
            Remove-Item -LiteralPath $workRoot -Recurse -Force
        }
        if (Test-Path -LiteralPath $dataRoot) {
            Remove-Item -LiteralPath $dataRoot -Recurse -Force
        }
    }
}
