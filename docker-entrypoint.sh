#!/bin/bash
set -e

echo "[Docker Entrypoint] Memeriksa koneksi database (${DB_HOST:-db})..."

MAX_TRIES=30
COUNT=0
until mysqladmin ping -h "${DB_HOST:-db}" -P "${DB_PORT:-3306}" -u "${DB_USER:-labuser}" -p"${DB_PASS:-labmineral_secure_pass2026}" --silent > /dev/null 2>&1 || [ $COUNT -eq $MAX_TRIES ]; do
    echo "[Docker Entrypoint] Menunggu database aktif... ($COUNT/$MAX_TRIES)"
    sleep 2
    COUNT=$((COUNT+1))
done

if [ $COUNT -lt $MAX_TRIES ]; then
    echo "[Docker Entrypoint] Database siap! Memeriksa sinkronisasi skema dan data XRF..."
    
    # 1. Jalankan perbaikan skema penting jika belum ada
    mysql -h "${DB_HOST:-db}" -P "${DB_PORT:-3306}" -u "${DB_USER:-labuser}" -p"${DB_PASS:-labmineral_secure_pass2026}" "${DB_NAME:-labmineral}" -e "
        ALTER TABLE work_order ADD COLUMN IF NOT EXISTS butuh_preparasi TINYINT(1) DEFAULT 0 AFTER catatan;
        ALTER TABLE preparasi_sampel MODIFY COLUMN metode_preparasi VARCHAR(100) NOT NULL DEFAULT 'destruksi_asam';
    " > /dev/null 2>&1 || true

    # 2. Periksa apakah tabel xrf_measurements sudah ada dan berisi data
    HAS_XRF_TABLE=$(mysql -h "${DB_HOST:-db}" -P "${DB_PORT:-3306}" -u "${DB_USER:-labuser}" -p"${DB_PASS:-labmineral_secure_pass2026}" "${DB_NAME:-labmineral}" -sN -e "
        SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME:-labmineral}' AND table_name='xrf_measurements';
    " 2>/dev/null || echo "0")

    XRF_COUNT=0
    if [ "$HAS_XRF_TABLE" -gt 0 ]; then
        XRF_COUNT=$(mysql -h "${DB_HOST:-db}" -P "${DB_PORT:-3306}" -u "${DB_USER:-labuser}" -p"${DB_PASS:-labmineral_secure_pass2026}" "${DB_NAME:-labmineral}" -sN -e "
            SELECT COUNT(*) FROM xrf_measurements;
        " 2>/dev/null || echo "0")
    fi

    # Jika xrf_measurements masih 0 atau belum ada tabelnya, otomatis impor xrf_data_dump.sql
    if [ "$XRF_COUNT" -eq 0 ] && [ -f "/var/www/html/scripts/sql/xrf_data_dump.sql" ]; then
        echo "[Docker Entrypoint] Tabel xrf_measurements kosong. Mengimpor xrf_data_dump.sql otomatis (2081 measurements)..."
        mysql -h "${DB_HOST:-db}" -P "${DB_PORT:-3306}" -u "${DB_USER:-labuser}" -p"${DB_PASS:-labmineral_secure_pass2026}" "${DB_NAME:-labmineral}" < /var/www/html/scripts/sql/xrf_data_dump.sql
        echo "[Docker Entrypoint] Berhasil mengimpor 2081 data pengukuran XRF!"
    else
        echo "[Docker Entrypoint] Data XRF sudah ada ($XRF_COUNT measurements). Melewati impor dump."
    fi
else
    echo "[Docker Entrypoint] Peringatan: Tidak dapat terhubung ke database dalam batas waktu. Melanjutkan startup..."
fi

exec "$@"
