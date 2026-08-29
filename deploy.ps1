<#
.SYNOPSIS
    PrimePrint Automated Local FTP Deployment Tool
.DESCRIPTION
    Safely deploys changed PrimePrint Web Application files to Hostinger Shared Hosting via FTP/FTPS.
.EXAMPLE
    .\deploy.ps1 -TestConnection
    .\deploy.ps1 -ShowStatus
    .\deploy.ps1 -DryRun
    .\deploy.ps1 -Initial
    .\deploy.ps1
#>

[CmdletBinding()]
param (
    [switch]$DryRun,
    [switch]$TestConnection,
    [switch]$Initial,
    [switch]$ShowStatus,
    [switch]$Yes,
    [string]$EnvFile = ".deploy.env"
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

# Script Locations
$RootDir = $PSScriptRoot
if (-not $RootDir) { $RootDir = (Get-Location).Path }
$StateFile = Join-Path $RootDir ".deploy-state.json"
$DeploymentsDir = Join-Path $RootDir "deployments"

# Initialize Deployment Logs
if (-not (Test-Path $DeploymentsDir)) {
    New-Item -ItemType Directory -Path $DeploymentsDir -Force | Out-Null
}
$LogTimestamp = (Get-Date).ToString("yyyy-MM-dd_HH-mm-ss")
$LogFilePath = Join-Path $DeploymentsDir "$LogTimestamp.log"

function Log-Message {
    param (
        [string]$Message,
        [string]$Color = "White",
        [bool]$NoConsole = $false
    )
    $timeStr = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
    $logLine = "[$timeStr] $Message"
    Add-Content -Path $LogFilePath -Value $logLine -Encoding UTF8
    
    if (-not $NoConsole) {
        Write-Host $Message -ForegroundColor $Color
    }
}

# Web Application File Allowlist / Denylist Rule
function Test-IsDeployableFile {
    param ([string]$RelativePath)
    
    if ([string]::IsNullOrWhiteSpace($RelativePath)) { return $false }
    $cleanPath = $RelativePath.Replace('\', '/').TrimStart('/')
    
    # 1. Strict Exclusion Denylist
    if ($cleanPath -match '^(src/|test-assets/|test/|\.git/|node_modules/|deployments/)') { return $false }
    if ($cleanPath -match '^\.env' -or $cleanPath -match '^\.deploy') { return $false }
    if ($cleanPath -match '\.(exe|dll|pak|dat|bin|zip|tar|7z|log|tmp|swp)$') { return $false }
    if ($cleanPath -in @('package.json', 'package-lock.json', 'router.php', 'README.txt', 'LICENSES.chromium.html', 'version', 'sample-test-document.pdf')) { return $false }
    if ($cleanPath -match '^uploads/' -and $cleanPath -notin @('uploads/.htaccess', 'uploads/index.php')) { return $false }
    if ($cleanPath -match '^logs/' -and $cleanPath -notin @('logs/.htaccess', 'logs/index.php')) { return $false }
    
    # 2. Strict Inclusion Allowlist
    if ($cleanPath -match '^(admin/|api/|assets/|config/|customer/|database/|includes/|shop/)') { return $true }
    if ($cleanPath -in @('.htaccess', 'health.php', 'index.php', 'login.php', 'logout.php', 'p.php', 'DEPLOYMENT.md', 'PRODUCTION_CHECKLIST.md', 'DEPLOY_LOCAL.md')) { return $true }
    if ($cleanPath -in @('uploads/.htaccess', 'uploads/index.php', 'logs/.htaccess', 'logs/index.php')) { return $true }
    
    return $false
}

# Load .deploy.env configuration
function Load-DeployEnv {
    $envPath = Join-Path $RootDir $EnvFile
    if (-not (Test-Path $envPath)) {
        Log-Message "Deployment configuration file '$EnvFile' not found!" -Color Red
        Log-Message "Please copy '.deploy.env.example' to '.deploy.env' and configure your Hostinger FTP credentials." -Color Yellow
        exit 1
    }
    
    $config = @{}
    Get-Content $envPath | ForEach-Object {
        $line = $_.Trim()
        if ($line -and -not $line.StartsWith('#') -and $line.Contains('=')) {
            $parts = $line.Split('=', 2)
            $key = $parts[0].Trim()
            $val = $parts[1].Trim()
            $config[$key] = $val
        }
    }
    
    $requiredKeys = @('FTP_HOST', 'FTP_USERNAME', 'FTP_PASSWORD')
    foreach ($k in $requiredKeys) {
        if (-not $config.ContainsKey($k) -or [string]::IsNullOrWhiteSpace($config[$k])) {
            Log-Message "Missing required configuration key '$k' in '$EnvFile'!" -Color Red
            exit 1
        }
    }
    
    if (-not $config.ContainsKey('FTP_PORT') -or [string]::IsNullOrWhiteSpace($config['FTP_PORT'])) {
        $config['FTP_PORT'] = "21"
    }
    if (-not $config.ContainsKey('FTP_REMOTE_ROOT') -or [string]::IsNullOrWhiteSpace($config['FTP_REMOTE_ROOT'])) {
        $config['FTP_REMOTE_ROOT'] = "/public_html"
    }
    if (-not $config.ContainsKey('FTP_USE_TLS') -or [string]::IsNullOrWhiteSpace($config['FTP_USE_TLS'])) {
        $config['FTP_USE_TLS'] = "true"
    }
    
    return $config
}

# Helper to build FTP URI
function Get-FtpUri {
    param (
        [hashtable]$Config,
        [string]$RelativeRemotePath = ""
    )
    $hostName = $Config['FTP_HOST']
    $port = $Config['FTP_PORT']
    $remoteRoot = $Config['FTP_REMOTE_ROOT'].Trim().Replace('\', '/').TrimEnd('/')
    $cleanPath = $RelativeRemotePath.Replace('\', '/').TrimStart('/')
    
    $fullPath = if ($cleanPath) { "$remoteRoot/$cleanPath" } else { $remoteRoot }
    if (-not $fullPath.StartsWith('/')) { $fullPath = "/$fullPath" }
    
    return "ftp://$hostName`:$port$fullPath"
}

# Create FTP Request object
function New-FtpRequest {
    param (
        [string]$Uri,
        [hashtable]$Config,
        [string]$Method
    )
    [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.SecurityProtocolType]::Tls12 -bor [System.Net.SecurityProtocolType]::Tls13
    [System.Net.ServicePointManager]::ServerCertificateValidationCallback = { $true }
    
    $request = [System.Net.FtpWebRequest]::Create($Uri)
    $request.Credentials = New-Object System.Net.NetworkCredential($Config['FTP_USERNAME'], $Config['FTP_PASSWORD'])
    $request.Method = $Method
    $request.UseBinary = $true
    $request.UsePassive = $true
    $request.KeepAlive = $false
    
    $useTls = ($Config['FTP_USE_TLS'] -eq "true" -or $Config['FTP_USE_TLS'] -eq "1")
    $request.EnableSsl = $useTls
    
    return $request
}

# Recursively ensure remote directory exists
function Ensure-RemoteDirectory {
    param (
        [string]$RelativeDirPath,
        [hashtable]$Config
    )
    if (-not $RelativeDirPath -or $RelativeDirPath -eq "." -or $RelativeDirPath -eq "/") { return }
    
    $normalized = $RelativeDirPath.Replace('\', '/').Trim('/')
    $segments = $normalized.Split('/')
    $currentPath = ""
    
    foreach ($seg in $segments) {
        $currentPath = if ($currentPath) { "$currentPath/$seg" } else { $seg }
        $dirUri = Get-FtpUri -Config $Config -RelativeRemotePath $currentPath
        
        try {
            $req = New-FtpRequest -Uri $dirUri -Config $Config -Method ([System.Net.WebRequestMethods+Ftp]::MakeDirectory)
            $response = $req.GetResponse()
            $response.Close()
        } catch [System.Net.WebException] {
            $status = $_.Exception.Response
            if ($status) { $status.Close() }
        } catch {
            # Ignore directory existence exceptions
        }
    }
}

# Upload a local file via FTP
function Send-FtpFile {
    param (
        [string]$LocalFilePath,
        [string]$RelativePath,
        [hashtable]$Config
    )
    $dirName = [System.IO.Path]::GetDirectoryName($RelativePath)
    if ($dirName) {
        Ensure-RemoteDirectory -RelativeDirPath $dirName -Config $Config
    }
    
    $targetUri = Get-FtpUri -Config $Config -RelativeRemotePath $RelativePath
    $req = New-FtpRequest -Uri $targetUri -Config $Config -Method ([System.Net.WebRequestMethods+Ftp]::UploadFile)
    
    $fileBytes = [System.IO.File]::ReadAllBytes($LocalFilePath)
    $req.ContentLength = $fileBytes.Length
    
    $reqStream = $req.GetRequestStream()
    $reqStream.Write($fileBytes, 0, $fileBytes.Length)
    $reqStream.Close()
    
    $res = $req.GetResponse()
    $res.Close()
}

# Delete a remote file via FTP
function Remove-FtpFile {
    param (
        [string]$RelativePath,
        [hashtable]$Config
    )
    $targetUri = Get-FtpUri -Config $Config -RelativeRemotePath $RelativePath
    $req = New-FtpRequest -Uri $targetUri -Config $Config -Method ([System.Net.WebRequestMethods+Ftp]::DeleteFile)
    $res = $req.GetResponse()
    $res.Close()
}

# ==============================================================================
# MAIN WORKFLOW ROUTINES
# ==============================================================================

Log-Message "==================================================" -Color Cyan
Log-Message "PrimePrint Automated Hostinger Deployment Tool" -Color Cyan
Log-Message "==================================================" -Color Cyan

# 1. Test Connection Mode
if ($TestConnection) {
    Log-Message "`nTesting FTP connection to Hostinger..." -Color Yellow
    $cfg = Load-DeployEnv
    $rootUri = Get-FtpUri -Config $cfg -RelativeRemotePath ""
    
    try {
        $req = New-FtpRequest -Uri $rootUri -Config $cfg -Method ([System.Net.WebRequestMethods+Ftp]::ListDirectory)
        $res = $req.GetResponse()
        $reader = New-Object System.IO.StreamReader($res.GetResponseStream())
        $listing = $reader.ReadToEnd()
        $reader.Close()
        $res.Close()
        
        $protoDesc = if ($cfg['FTP_USE_TLS'] -eq 'true') { 'FTPS (Explicit TLS)' } else { 'Plain FTP (Unencrypted)' }
        $hostVal = $cfg['FTP_HOST']
        $userVal = $cfg['FTP_USERNAME']
        $rootVal = $cfg['FTP_REMOTE_ROOT']

        Log-Message "FTP Connection Successful!" -Color Green
        Log-Message "   Host:        $hostVal" -Color Green
        Log-Message "   User:        $userVal" -Color Green
        Log-Message "   Remote Root: $rootVal" -Color Green
        Log-Message "   Protocol:    $protoDesc" -Color Green
        exit 0
    } catch {
        Log-Message "FTP Connection Failed: $($_.Exception.Message)" -Color Red
        exit 1
    }
}

# 2. Verify Git State
try {
    $commitRaw = git rev-parse HEAD
    $currentCommit = if ($commitRaw) { ($commitRaw -join "").Trim() } else { "" }
    $branchRaw = git rev-parse --abbrev-ref HEAD
    $currentBranch = if ($branchRaw) { ($branchRaw -join "").Trim() } else { "main" }
} catch {
    Log-Message "Error: Not in a valid Git repository." -Color Red
    exit 1
}

# 3. Check for Clean Working Tree
$statusRaw = git status --porcelain
$statusOutput = if ($statusRaw) { ($statusRaw -join "`n").Trim() } else { "" }
if ($statusOutput -and -not $DryRun -and -not $ShowStatus) {
    Log-Message "Uncommitted changes detected in working tree!" -Color Red
    Log-Message "   Please commit your changes before deploying:" -Color Yellow
    Log-Message "   git add ." -Color Gray
    Log-Message "   git commit -m 'your message'" -Color Gray
    Log-Message "   git push origin $currentBranch" -Color Gray
    exit 1
}

# Read Deployment State
$state = @{}
if (Test-Path $StateFile) {
    try {
        $raw = Get-Content -Path $StateFile -Raw -Encoding UTF8
        $state = ConvertFrom-Json $raw -AsHashtable
    } catch {}
}
$lastDeployedCommit = if ($state.ContainsKey('lastDeployedCommit') -and $state['lastDeployedCommit']) { [string]$state['lastDeployedCommit'] } else { $null }

# 4. Show Status Mode
if ($ShowStatus) {
    Log-Message "`nPrimePrint Deployment Status:" -Color Cyan
    Log-Message "   GitHub Branch:        $currentBranch"
    Log-Message "   Current Git Commit:   $currentCommit"
    $lastDisp = if ($lastDeployedCommit) { $lastDeployedCommit } else { 'None (Initial deployment needed)' }
    Log-Message "   Last Deployed Commit: $lastDisp"
    
    if ($lastDeployedCommit) {
        $diffLines = git diff --name-status $lastDeployedCommit $currentCommit
        $pendingUploads = @()
        $pendingDeletes = @()
        foreach ($l in $diffLines) {
            if (-not $l) { continue }
            $parts = $l -split "\s+"
            $action = $parts[0]
            $file = $parts[1]
            if (Test-IsDeployableFile -RelativePath $file) {
                if ($action -match '^D') { $pendingDeletes += $file }
                else { $pendingUploads += $file }
            }
        }
        Log-Message "   Pending Uploads:      $($pendingUploads.Count) file(s)"
        Log-Message "   Pending Deletions:    $($pendingDeletes.Count) file(s)"
    }
    exit 0
}

# 5. Determine Files to Deploy
$filesToUpload = @()
$filesToDelete = @()
$detectedMigrations = @()
$ignoredFiles = @()

if ($Initial -or [string]::IsNullOrWhiteSpace($lastDeployedCommit)) {
    Log-Message "`nScanning all deployable Web App files for INITIAL deployment..." -Color Yellow
    $allFiles = Get-ChildItem -Path $RootDir -Recurse -File
    foreach ($item in $allFiles) {
        $relPath = $item.FullName.Substring($RootDir.Length).TrimStart('\', '/')
        if (Test-IsDeployableFile -RelativePath $relPath) {
            $filesToUpload += $relPath
            if ($relPath -match '^database/migrations/') {
                $detectedMigrations += $relPath
            }
        } else {
            $ignoredFiles += $relPath
        }
    }
} else {
    $shortLen = [Math]::Min(8, $lastDeployedCommit.Length)
    $shortLast = $lastDeployedCommit.Substring(0, $shortLen)
    Log-Message "`nCalculating changed files since commit: $shortLast..." -Color Yellow
    
    if ($lastDeployedCommit -eq $currentCommit) {
        Log-Message "No changes since last deployment. Web app is already up to date with commit $currentCommit." -Color Green
        exit 0
    }
    
    $diffOutput = git diff --name-status $lastDeployedCommit $currentCommit
    foreach ($line in $diffOutput) {
        if (-not $line) { continue }
        $parts = $line -split "\s+"
        $status = $parts[0]
        
        if ($status -match '^R') {
            $oldFile = $parts[1]
            $newFile = $parts[2]
            if (Test-IsDeployableFile -RelativePath $newFile) {
                $filesToUpload += $newFile
                if (Test-IsDeployableFile -RelativePath $oldFile) {
                    $filesToDelete += $oldFile
                }
            }
        } elseif ($status -eq 'D') {
            $file = $parts[1]
            if (Test-IsDeployableFile -RelativePath $file) {
                $filesToDelete += $file
            }
        } else {
            $file = $parts[1]
            if ($file -match '^\.env') {
                Log-Message "WARNING: .env is excluded from deployment. Production credentials on Hostinger are protected." -Color Yellow
                continue
            }
            if (Test-IsDeployableFile -RelativePath $file) {
                $filesToUpload += $file
                if ($file -match '^database/migrations/') {
                    $detectedMigrations += $file
                }
            } else {
                $ignoredFiles += $file
            }
        }
    }
}

# 6. Display Deployment Plan
Log-Message "`n==================================================" -Color Cyan
Log-Message "Deployment Summary Plan" -Color Cyan
Log-Message "==================================================" -Color Cyan
Log-Message "Current Git Commit:   $currentCommit (Branch: $currentBranch)"
$prevDisp = if ($lastDeployedCommit) { $lastDeployedCommit } else { 'None (Initial Baseline)' }
Log-Message "Previous Deployed:    $prevDisp"
Log-Message "Files to Upload:      $($filesToUpload.Count)" -Color $(if ($filesToUpload.Count -gt 0) { 'Green' } else { 'Gray' })
Log-Message "Files to Delete:      $($filesToDelete.Count)" -Color $(if ($filesToDelete.Count -gt 0) { 'Red' } else { 'Gray' })

if ($filesToUpload.Count -gt 0) {
    Log-Message "`nFiles to UPLOAD:" -Color Green
    foreach ($f in $filesToUpload) {
        Log-Message "   + $f" -Color Green
    }
}

if ($filesToDelete.Count -gt 0) {
    Log-Message "`nFiles to DELETE remotely:" -Color Red
    foreach ($f in $filesToDelete) {
        Log-Message "   - $f" -Color Red
    }
}

# Migration Notification
if ($detectedMigrations.Count -gt 0) {
    Log-Message "`nDATABASE MIGRATION DETECTED:" -Color Yellow
    foreach ($m in $detectedMigrations) {
        Log-Message "   * $m" -Color Yellow
    }
    Log-Message "   Note: Database schema will NOT be changed automatically." -Color Yellow
    Log-Message "   Please run migrations on Hostinger via https://yourdomain.com/database/migrate.php" -Color Yellow
}

# 7. DryRun Mode Handler
if ($DryRun) {
    Log-Message "`n[DRY RUN MODE] No files were uploaded or deleted." -Color Cyan
    Log-Message "   Ignored files count: $($ignoredFiles.Count)" -Color Gray
    exit 0
}

# Ensure there is work to do
if ($filesToUpload.Count -eq 0 -and $filesToDelete.Count -eq 0) {
    Log-Message "`nNo web application files to deploy." -Color Green
    exit 0
}

# 8. Confirmation Prompts
if ($filesToDelete.Count -gt 0) {
    Log-Message "`nWARNING: You are about to permanently delete $($filesToDelete.Count) remote file(s) on Hostinger." -Color Red
    $delConfirm = Read-Host "Type DELETE to confirm deletion"
    if ($delConfirm -ne "DELETE") {
        Log-Message "Deployment aborted by user (deletion not confirmed)." -Color Red
        exit 1
    }
}

if (-not $Yes) {
    $proceed = Read-Host "`nContinue deployment to Hostinger? [Y/N]"
    if ($proceed -ne 'Y' -and $proceed -ne 'y') {
        Log-Message "Deployment cancelled by user." -Color Yellow
        exit 0
    }
}

# 9. Execute Deployment
$config = Load-DeployEnv
$hostName = $config['FTP_HOST']
Log-Message "`nConnecting to Hostinger FTP ($hostName)..." -Color Cyan

$failedUploads = @()
$failedDeletes = @()
$successCount = 0

# Upload files
foreach ($relPath in $filesToUpload) {
    $localFile = Join-Path $RootDir $relPath
    if (-not (Test-Path $localFile)) {
        Log-Message "Local file not found: $relPath" -Color Red
        $failedUploads += $relPath
        continue
    }
    
    try {
        Send-FtpFile -LocalFilePath $localFile -RelativePath $relPath -Config $config
        Log-Message "  Uploaded: $relPath" -Color Green
        $successCount++
    } catch {
        Log-Message "  Failed to upload ${relPath}: $($_.Exception.Message)" -Color Red
        $failedUploads += $relPath
    }
}

# Delete files
foreach ($relPath in $filesToDelete) {
    try {
        Remove-FtpFile -RelativePath $relPath -Config $config
        Log-Message "  Deleted remote: $relPath" -Color Yellow
        $successCount++
    } catch {
        Log-Message "  Failed to delete remote ${relPath}: $($_.Exception.Message)" -Color Red
        $failedDeletes += $relPath
    }
}

# 10. Evaluate Result & Update State
if ($failedUploads.Count -gt 0 -or $failedDeletes.Count -gt 0) {
    Log-Message "`nDEPLOYMENT FAILED with errors!" -Color Red
    Log-Message "   Failed uploads:   $($failedUploads.Count)" -Color Red
    Log-Message "   Failed deletes:   $($failedDeletes.Count)" -Color Red
    Log-Message "   .deploy-state.json was NOT updated to ensure retry consistency." -Color Yellow
    exit 1
}

# Save new state on 100% success
$newState = @{
    lastDeployedCommit = $currentCommit
    lastDeployedAt     = (Get-Date).ToString("o")
    branch             = $currentBranch
    filesUploaded      = $filesToUpload.Count
    filesDeleted       = $filesToDelete.Count
}
$jsonState = ConvertTo-Json $newState -Depth 3
[System.IO.File]::WriteAllText($StateFile, $jsonState, [System.Text.UTF8Encoding]::new($false))

Log-Message "`n==================================================" -Color Green
Log-Message "DEPLOYMENT SUCCEEDED 100%!" -Color Green
Log-Message "==================================================" -Color Green
Log-Message "Deployed Commit:      $currentCommit" -Color Green
Log-Message "Total Files Uploaded: $($filesToUpload.Count)" -Color Green
Log-Message "Total Files Deleted:  $($filesToDelete.Count)" -Color Green
Log-Message "Log File:             $LogFilePath" -Color Gray
