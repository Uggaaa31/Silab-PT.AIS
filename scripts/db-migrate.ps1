param(
    [string]$MysqlUser = "root",
    [string]$MysqlPassword = "",
    [string]$Database = "labmineral",
    [switch]$IncludeBase
)

$ErrorActionPreference = "Stop"

$projectRoot = Split-Path -Parent $PSScriptRoot
$mysqlExe = ""
try {
    $mysqlExe = (Get-Command mysql -ErrorAction Stop).Source
} catch {
    # Laragon & XAMPP Fallback Detector
    $laragonPattern = "C:\laragon\bin\mysql\mysql-*\bin\mysql.exe"
    $laragonPaths = Resolve-Path $laragonPattern -ErrorAction SilentlyContinue
    if ($laragonPaths) {
        $mysqlExe = $laragonPaths[0].Path
    } else {
        $xamppPath = "C:\xampp\mysql\bin\mysql.exe"
        if (Test-Path $xamppPath) {
            $mysqlExe = $xamppPath
        }
    }
}

if (-not $mysqlExe) {
    throw "mysql.exe tidak ditemukan di PATH, Laragon, maupun XAMPP. Silakan instal MySQL atau tambahkan ke PATH."
}

function Get-MysqlArgs {
    param(
        [string]$User,
        [string]$Password
    )

    $args = @("--default-character-set=utf8mb4", "-u$User")
    if ($Password -ne "") {
        $args += "-p$Password"
    }
    return $args
}

function Invoke-SqlFile {
    param(
        [string]$FilePath,
        [string[]]$MysqlArgs
    )

    Write-Host ("`n==> Menjalankan {0}" -f (Resolve-Path -LiteralPath $FilePath))
    Get-Content -LiteralPath $FilePath -Raw | & $mysqlExe @MysqlArgs
    if ($LASTEXITCODE -ne 0) {
        throw "Migrasi gagal saat menjalankan $FilePath"
    }
}

$mysqlArgs = Get-MysqlArgs -User $MysqlUser -Password $MysqlPassword

$dbExistsRaw = & $mysqlExe @mysqlArgs -N -e "SHOW DATABASES LIKE '$Database';"
$dbExists = if ($null -eq $dbExistsRaw) { "" } else { "$dbExistsRaw".Trim() }

$migrationFile = Join-Path $projectRoot "scripts/sql/database_latest.sql"

if (-not (Test-Path $migrationFile)) {
    throw "File skrip konsolidasi database_latest.sql tidak ditemukan!"
}

Write-Host "Menjalankan migrasi database konsolidasi (database_latest.sql)..."
Invoke-SqlFile -FilePath $migrationFile -MysqlArgs $mysqlArgs

$xrfDumpFile = Join-Path $projectRoot "scripts/sql/xrf_data_dump.sql"
if (Test-Path $xrfDumpFile) {
    $xrfTableExists = & $mysqlExe @mysqlArgs -D $Database -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$Database' AND table_name='xrf_measurements';" 2>$null
    $hasXrfTable = if ($null -eq $xrfTableExists) { "0" } else { "$xrfTableExists".Trim() }
    if ($hasXrfTable -eq "0") {
        Write-Host "Mengimpor skema & data XRF (xrf_data_dump.sql)..."
        Invoke-SqlFile -FilePath $xrfDumpFile -MysqlArgs $mysqlArgs
    }
}

Write-Host "`n==> Verifikasi akhir"
& $mysqlExe @mysqlArgs -D $Database -e @"
SELECT 'pengguna' AS tabel, COUNT(*) AS total FROM pengguna
UNION ALL
SELECT 'sampel', COUNT(*) FROM sampel
UNION ALL
SELECT 'penerimaan_sampel', COUNT(*) FROM penerimaan_sampel
UNION ALL
SELECT 'work_order', COUNT(*) FROM work_order
UNION ALL
SELECT 'work_order_sampel', COUNT(*) FROM work_order_sampel
UNION ALL
SELECT 'preparasi_sampel', COUNT(*) FROM preparasi_sampel
UNION ALL
SELECT 'qc_sampel', COUNT(*) FROM qc_sampel
UNION ALL
SELECT 'invoice', COUNT(*) FROM invoice
UNION ALL
SELECT 'xrf_measurements', COUNT(*) FROM xrf_measurements;
"@

if ($LASTEXITCODE -ne 0) {
    throw "Verifikasi akhir gagal."
}

Write-Host "`nMigrasi database selesai."
