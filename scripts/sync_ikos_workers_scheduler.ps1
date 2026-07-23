$ErrorActionPreference = 'Stop'

$phpExe = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe'
$extDir = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\ext'
$syncScript = 'C:\laragon\www\pisek\v3.4\scripts\sync_ikos_workers.php'
$lockFile = 'C:\laragon\www\pisek\v3.4\uploads\tmp\sync_ikos_workers_cron.lock'
$logFile = 'C:\laragon\www\pisek\v3.4\uploads\tmp\sync_ikos_workers_cron.log'

function Write-Log([string]$Message) {
    $line = "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') - $Message"
    [System.IO.File]::AppendAllText($logFile, $line + [Environment]::NewLine, [System.Text.Encoding]::UTF8)
}

function Invoke-Sync {
    if (Test-Path $lockFile) {
        Write-Log 'Skip: cron lock file exists.'
        return
    }

    try {
        Set-Content -Path $lockFile -Value (Get-Date -Format o) -Encoding UTF8
        Write-Log 'Start sync_ikos_workers.php'

        $output = & $phpExe -n -d "extension_dir=$extDir" -d extension=pdo_mysql -d extension=mysqli -d extension=mbstring $syncScript 2>&1
        $exitCode = $LASTEXITCODE

        if ($output) {
            $text = ($output | Out-String).Trim()
            if ($text -ne '') {
                Write-Log ("Output: " + $text)
            }
        }

        Write-Log ("Finished with exit code " + $exitCode)
    }
    catch {
        Write-Log ("ERROR: " + $_.Exception.Message)
    }
    finally {
        Remove-Item -Path $lockFile -ErrorAction SilentlyContinue
    }
}

Write-Log 'IKOS workers scheduler process started.'

while ($true) {
    try {
        $now = Get-Date
        if ($now.Minute -eq 1 -and $now.Second -lt 20) {
            Invoke-Sync
            Start-Sleep -Seconds 75
        }
    }
    catch {
        Write-Log ("Loop error: " + $_.Exception.Message)
    }

    Start-Sleep -Seconds 10
}
