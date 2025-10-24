param(
  [string]$InstallDir = "C:\Program Files\HotelBomeRFID",
  [string]$LaravelApi = "http://hotel-bome.test",
  [string]$BridgeToken = "hotelbome-bridge-2025",
  [int]$Port = 8200,
  [int]$LockType = 5,
  [bool]$AlwaysOn = $true,
  [bool]$PushOnScan = $true
)

$ErrorActionPreference = "Stop"
Write-Host "Installing HotelBomeRFID to $InstallDir"

New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null
New-Item -ItemType Directory -Force -Path "$InstallDir\vendor" | Out-Null
New-Item -ItemType Directory -Force -Path "$InstallDir\logs" | Out-Null

@"
LARAVEL_API=$LaravelApi
BRIDGE_TOKEN=$BridgeToken
PORT=$Port
LOCK_TYPE=$LockType
POLL_INTERVAL_MS=250
DEBOUNCE_MS=5000
TIMEOUT_MS=8000
ALWAYS_ON=$AlwaysOn
PUSH_ON_SCAN=$PushOnScan
"@ | Out-File -Encoding ascii "$InstallDir\rfid.env"

Write-Host "[INFO] rfid.env written"

$svcName = "HotelBomeRFIDService"
$exePath = Join-Path $InstallDir "rfid.exe"

if (Get-Command nssm -ErrorAction SilentlyContinue) {
  try { nssm stop $svcName | Out-Null; nssm remove $svcName confirm | Out-Null } catch {}
  nssm install $svcName $exePath
  nssm set $svcName AppDirectory $InstallDir
  nssm start $svcName
  Write-Host "[INFO] Service installed via NSSM: $svcName"
} else {
  Write-Warning "NSSM not found. Install NSSM or use Task Scheduler to auto-start $exePath"
}

Write-Host "Done."