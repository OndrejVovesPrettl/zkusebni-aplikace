param(
    [string]$To = '',
    [string]$Subject = '',
    [string]$Body = '',
    [string]$BodyFile = ''
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($To)) {
    [PSCustomObject]@{
        ok = $false
        error = 'Missing recipient.'
    } | ConvertTo-Json -Compress
    exit 1
}

if ([string]::IsNullOrWhiteSpace($Subject)) {
    $Subject = 'DVD Outlook test ' + (Get-Date -Format 'yyyy-MM-dd HH:mm:ss')
}

if (-not [string]::IsNullOrWhiteSpace($BodyFile) -and (Test-Path $BodyFile)) {
    $Body = [System.IO.File]::ReadAllText($BodyFile, [System.Text.Encoding]::UTF8)
}

if ([string]::IsNullOrWhiteSpace($Body)) {
    $Body = 'Test sent via local Outlook profile from host ' + $env:COMPUTERNAME + ' at ' + (Get-Date -Format 'yyyy-MM-dd HH:mm:ss')
}

try {
    $outlook = New-Object -ComObject Outlook.Application
    $mail = $outlook.CreateItem(0)
    $mail.To = $To
    $mail.Subject = $Subject
    $mail.Body = $Body
    $mail.Send()

    [PSCustomObject]@{
        ok = $true
        to = $To
        subject = $Subject
        message = 'Sent via Outlook COM (queued in Outbox/Sent Items).'
    } | ConvertTo-Json -Compress

    exit 0
} catch {
    [PSCustomObject]@{
        ok = $false
        to = $To
        error = $_.Exception.Message
    } | ConvertTo-Json -Compress

    exit 1
}
