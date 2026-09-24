-- ============================================================
--  LabMineral Pro — SQL Migration Script (Complete)
--  Version: 2.0.0
--  MySQL 5.7+ / MariaDB 10.4+
--
--  Urutan eksekusi:
--    1. Buat database & pilih
--    2. Buat tabel (urutan FK-safe)
--    3. Buat stored procedure & trigger
--    4. Seed data awal
--
--  Aman dijalankan ulang: semua statement menggunakan
--  CREATE TABLE IF NOT EXISTS / INSERT IGNORE.
-- ============================================================

-- ------------------------------------------------------------
-- DATABASE
-- ------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS labmineral
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE labmineral;

-- ============================================================
-- TABEL UTAMA
-- ============================================================

-- ------------------------------------------------------------
-- 1. PENGGUNA
--    Menyimpan akun semua pengguna sistem (admin, analis,
--    supervisor, client).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pengguna (
    id         INT          AUTO_INCREMENT PRIMARY KEY,
    nama       VARCHAR(100) NOT NULL,
    username   VARCHAR(50)  NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL,
    email      VARCHAR(100),
    role       ENUM('admin','analis','supervisor','client','klien') DEFAULT 'analis',
    status     ENUM('aktif','nonaktif') DEFAULT 'aktif',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. BAHAN / REAGEN
--    Inventaris bahan kimia dan reagen laboratorium.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bahan (
    id                 INT           AUTO_INCREMENT PRIMARY KEY,
    kode_bahan         VARCHAR(20)   NOT NULL UNIQUE,
    nama               VARCHAR(150)  NOT NULL,
    stok               DECIMAL(10,3) NOT NULL DEFAULT 0,
    satuan             VARCHAR(20),
    stok_minimum       DECIMAL(10,3) DEFAULT 0,
    supplier           VARCHAR(100),
    tanggal_kadaluarsa DATE,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. LOG BAHAN
--    Riwayat masuk/keluar stok bahan (audit trail inventaris).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS log_bahan (
    id          INT           AUTO_INCREMENT PRIMARY KEY,
    bahan_id    INT           NOT NULL,
    jenis       ENUM('masuk','keluar') NOT NULL,
    jumlah      DECIMAL(10,3) NOT NULL,
    keterangan  TEXT,
    pengguna_id INT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bahan_id)    REFERENCES bahan(id)    ON DELETE CASCADE,
    FOREIGN KEY (pengguna_id) REFERENCES pengguna(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. PERALATAN
--    Daftar alat/instrumen laboratorium beserta status
--    kalibrasi dan maintenance.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS peralatan (
    id                     INT          AUTO_INCREMENT PRIMARY KEY,
    kode_alat              VARCHAR(20)  NOT NULL UNIQUE,
    nama                   VARCHAR(150) NOT NULL,
    lokasi                 VARCHAR(100),
    status                 ENUM('tersedia','digunakan','maintenance','rusak') DEFAULT 'tersedia',
    tanggal_kalibrasi      DATE,
    masa_berlaku_kalibrasi DATE,
    jam_pakai              INT DEFAULT 0,
    jadwal_maintenance     DATE,
    pic                    VARCHAR(100),
    catatan                TEXT,
    created_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. METODE PREPARASI
--    Daftar metode preparasi yang dapat dipilih oleh analis.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS metode_preparasi (
    id         INT          AUTO_INCREMENT PRIMARY KEY,
    metode     VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. PENERIMAAN SAMPEL (BATCH)
--    Header batch penerimaan dari klien.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS penerimaan_sampel (
    id               INT          AUTO_INCREMENT PRIMARY KEY,
    nomor_penerimaan VARCHAR(50)  NOT NULL UNIQUE,
    klien            VARCHAR(150) NOT NULL,
    tanggal_terima   DATE         NOT NULL,
    jumlah_sampel    INT          DEFAULT 0,
    jenis_material   VARCHAR(200),
    metode_uji       VARCHAR(200),
    keterangan       TEXT,
    status           ENUM('diterima','diproses','selesai','dibatalkan') DEFAULT 'diterima',
    is_confirmed     TINYINT(1)   DEFAULT 0,
    dibuat_oleh      INT,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dibuat_oleh) REFERENCES pengguna(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7. SAMPEL
--    Detail setiap sampel yang diterima lab.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sampel (
    id             INT           AUTO_INCREMENT PRIMARY KEY,
    penerimaan_id  INT,
    kode_sampel    VARCHAR(20)   NOT NULL UNIQUE,
    tanggal_masuk  DATE          NOT NULL,
    jenis_material VARCHAR(100)  NOT NULL,
    berat_gram     DECIMAL(10,3),
    klien          VARCHAR(100),
    metode_uji     VARCHAR(50),
    keterangan     TEXT,
    status         ENUM('antrian','diuji','review','selesai','ditolak') DEFAULT 'antrian',
    dibuat_oleh    INT,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (penerimaan_id) REFERENCES penerimaan_sampel(id) ON DELETE SET NULL,
    FOREIGN KEY (dibuat_oleh)   REFERENCES pengguna(id)           ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 8. WORK ORDER
--    Perintah kerja pengujian (bisa batch per penerimaan
--    atau single pilih sampel tertentu).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS work_order (
    id              INT          AUTO_INCREMENT PRIMARY KEY,
    nomor_wo        VARCHAR(20)  NOT NULL UNIQUE COMMENT 'Format: WO-YYMM-NNN',
    penerimaan_id   INT          NULL,
    lingkup_batch   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1=batch per penerimaan, 0=single sampel',
    analis_id       INT,
    peralatan_id    INT,
    parameter       VARCHAR(200) COMMENT 'Parameter yang akan diuji, dipisah koma',
    metode          VARCHAR(50),
    prioritas       ENUM('normal','tinggi','urgent') DEFAULT 'normal',
    jadwal_mulai    DATETIME,
    jadwal_selesai  DATETIME,
    selesai_at      DATETIME     NULL,
    status          ENUM('draft','aktif','selesai','dibatalkan') DEFAULT 'draft',
    catatan         TEXT,
    butuh_preparasi TINYINT(1)   DEFAULT 0,
    dibuat_oleh     INT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (penerimaan_id) REFERENCES penerimaan_sampel(id) ON DELETE SET NULL,
    FOREIGN KEY (analis_id)     REFERENCES pengguna(id)           ON DELETE SET NULL,
    FOREIGN KEY (peralatan_id)  REFERENCES peralatan(id)          ON DELETE SET NULL,
    FOREIGN KEY (dibuat_oleh)   REFERENCES pengguna(id)           ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 9. PIVOT WORK ORDER <-> SAMPEL
--    Relasi many-to-many antara work_order dan sampel.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS work_order_sampel (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    wo_id      INT NOT NULL,
    sampel_id  INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wo_sampel (wo_id, sampel_id),
    CONSTRAINT fk_wos_wo     FOREIGN KEY (wo_id)     REFERENCES work_order(id) ON DELETE CASCADE,
    CONSTRAINT fk_wos_sampel FOREIGN KEY (sampel_id) REFERENCES sampel(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 10. PREPARASI SAMPEL
--     Catatan proses preparasi sebelum pengujian.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS preparasi_sampel (
    id                 INT          AUTO_INCREMENT PRIMARY KEY,
    work_order_id      INT,
    sampel_id          INT          NOT NULL,
    metode_preparasi   VARCHAR(100) NOT NULL DEFAULT 'destruksi_asam',
    prosedur           TEXT,
    faktor_pengenceran DECIMAL(10,4) DEFAULT 1.0000,
    volume_awal_ml     DECIMAL(8,3),
    volume_akhir_ml    DECIMAL(8,3),
    reagen_detail      TEXT         COMMENT 'JSON: [{bahan_id, nama, kode, jumlah, satuan, lot}]',
    blanko_disiapkan   TINYINT(1)   DEFAULT 0,
    standar_disiapkan  TINYINT(1)   DEFAULT 0,
    spike_disiapkan    TINYINT(1)   DEFAULT 0,
    duplikat_disiapkan TINYINT(1)   DEFAULT 0,
    suhu_ruang         DECIMAL(5,2),
    kelembaban         DECIMAL(5,2),
    catatan            TEXT,
    analis_id          INT,
    tanggal_preparasi  DATE,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (work_order_id) REFERENCES work_order(id)   ON DELETE SET NULL,
    FOREIGN KEY (sampel_id)     REFERENCES sampel(id)        ON DELETE CASCADE,
    FOREIGN KEY (analis_id)     REFERENCES pengguna(id)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 11. HASIL UJI
--     Hasil pengujian per parameter untuk setiap sampel.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS hasil_uji (
    id                 INT           AUTO_INCREMENT PRIMARY KEY,
    kode_uji           VARCHAR(20)   NOT NULL UNIQUE COMMENT 'Format: U-NNN',
    sampel_id          INT           NOT NULL,
    no_referensi       VARCHAR(50),
    preparasi_id       INT           DEFAULT NULL,
    parameter          VARCHAR(100)  NOT NULL,
    nilai              DECIMAL(12,4) NOT NULL,
    faktor_pengenceran DECIMAL(10,4) DEFAULT 1.0000,
    nilai_terkoreksi   DECIMAL(12,4) DEFAULT NULL COMMENT 'Auto-hitung via trigger: nilai x faktor_pengenceran x faktor_konversi',
    satuan             VARCHAR(20),
    faktor_konversi    DECIMAL(14,8) DEFAULT 1.00000000,
    batas_min          DECIMAL(12,4),
    batas_maks         DECIMAL(12,4),
    metode             VARCHAR(50),
    alat_id            INT,
    analis_id          INT,
    kesimpulan         ENUM('lulus','tidak_lulus','pending') DEFAULT 'pending',
    catatan            TEXT,
    tanggal_uji        DATE,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sampel_id)    REFERENCES sampel(id)           ON DELETE CASCADE,
    FOREIGN KEY (preparasi_id) REFERENCES preparasi_sampel(id) ON DELETE SET NULL,
    FOREIGN KEY (analis_id)    REFERENCES pengguna(id)         ON DELETE SET NULL,
    FOREIGN KEY (alat_id)      REFERENCES peralatan(id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 12. QC SAMPEL
--     Data Quality Control (blanko, standar, spike, duplikat).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS qc_sampel (
    id              INT           AUTO_INCREMENT PRIMARY KEY,
    preparasi_id    INT,
    sampel_id       INT           NOT NULL,
    tipe_qc         ENUM('blanko','standar','spike','duplikat') NOT NULL,
    parameter       VARCHAR(100),
    nilai_qc        DECIMAL(12,4),
    nilai_expected  DECIMAL(12,4),
    satuan          VARCHAR(20),
    persen_recovery DECIMAL(8,4)  COMMENT 'Auto-hitung via trigger',
    batas_min_pct   DECIMAL(6,2)  DEFAULT 85.00,
    batas_maks_pct  DECIMAL(6,2)  DEFAULT 115.00,
    flag            ENUM('pass','fail','warning') DEFAULT 'pass',
    status_qc       ENUM('pending','disetujui','ditolak') DEFAULT 'pending',
    reviewer_id     INT,
    catatan_review  TEXT,
    tanggal_uji     DATE,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (preparasi_id) REFERENCES preparasi_sampel(id) ON DELETE SET NULL,
    FOREIGN KEY (sampel_id)    REFERENCES sampel(id)           ON DELETE CASCADE,
    FOREIGN KEY (reviewer_id)  REFERENCES pengguna(id)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 13. TARIF PENGUJIAN
--     Daftar harga layanan pengujian untuk invoice.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tarif_pengujian (
    id         INT           AUTO_INCREMENT PRIMARY KEY,
    nama       VARCHAR(150)  NOT NULL,
    metode     VARCHAR(50),
    parameter  VARCHAR(100),
    harga      DECIMAL(12,0) NOT NULL DEFAULT 0,
    satuan     VARCHAR(50)   DEFAULT 'per parameter',
    aktif      TINYINT(1)    DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 14. INVOICE
--     Header tagihan yang dikirimkan ke klien.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoice (
    id                  INT           AUTO_INCREMENT PRIMARY KEY,
    nomor_invoice       VARCHAR(50)   NOT NULL UNIQUE,
    penerimaan_id       INT,
    klien               VARCHAR(150)  NOT NULL,
    alamat_klien        TEXT,
    tanggal_invoice     DATE          NOT NULL,
    tanggal_jatuh_tempo DATE,
    subtotal            DECIMAL(15,2) DEFAULT 0,
    diskon_pct          DECIMAL(5,2)  DEFAULT 0,
    diskon_nominal      DECIMAL(15,2) DEFAULT 0,
    ppn_pct             DECIMAL(5,2)  DEFAULT 11,
    ppn_nominal         DECIMAL(15,2) DEFAULT 0,
    total               DECIMAL(15,2) DEFAULT 0,
    status              ENUM('draft','diterbitkan','lunas','dibatalkan') DEFAULT 'draft',
    catatan             TEXT,
    dibuat_oleh         INT,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (penerimaan_id) REFERENCES penerimaan_sampel(id) ON DELETE SET NULL,
    FOREIGN KEY (dibuat_oleh)   REFERENCES pengguna(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 15. ITEM INVOICE
--     Detail baris tagihan dalam sebuah invoice.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoice_item (
    id           INT           AUTO_INCREMENT PRIMARY KEY,
    invoice_id   INT           NOT NULL,
    deskripsi    VARCHAR(250)  NOT NULL,
    sampel_id    INT,
    tarif_id     INT,
    qty          INT           DEFAULT 1,
    harga_satuan DECIMAL(12,0) DEFAULT 0,
    subtotal     DECIMAL(14,0) DEFAULT 0,
    catatan      VARCHAR(200),
    FOREIGN KEY (invoice_id) REFERENCES invoice(id)         ON DELETE CASCADE,
    FOREIGN KEY (sampel_id)  REFERENCES sampel(id)          ON DELETE SET NULL,
    FOREIGN KEY (tarif_id)   REFERENCES tarif_pengujian(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 16. SUBMISSION SAMPEL (FORM PUBLIK / SSF)
--     Form pengajuan sampel oleh klien sebelum diterima lab.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS submission_sampel (
    id               INT          AUTO_INCREMENT PRIMARY KEY,
    nomor_submission VARCHAR(30)  NOT NULL UNIQUE,
    klien            VARCHAR(150) NOT NULL,
    kontak_person    VARCHAR(150),
    email            VARCHAR(150) NOT NULL,
    telepon          VARCHAR(50),
    alamat           TEXT,
    po_referensi     VARCHAR(100),
    instruksi_khusus TEXT,
    catatan          TEXT,
    status           ENUM('pending','diterima','diproses','ditolak') DEFAULT 'pending',
    tanggal_submit   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 17. DETAIL SUBMISSION SAMPEL
--     Detail sampel yang disertakan dalam satu submission.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS submission_sampel_detail (
    id             INT           AUTO_INCREMENT PRIMARY KEY,
    submission_id  INT           NOT NULL,
    jenis_material VARCHAR(100)  NOT NULL,
    berat_gram     DECIMAL(10,3),
    metode_uji     VARCHAR(50),
    parameter      VARCHAR(100),
    keterangan     TEXT,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_submission_detail_submission
        FOREIGN KEY (submission_id) REFERENCES submission_sampel(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 18. CLIENT ACCESS
--     Akun akses klien untuk memantau progress pengujian.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS client_access (
    id            INT          AUTO_INCREMENT PRIMARY KEY,
    pengguna_id   INT          NOT NULL,
    submission_id INT          NULL,
    penerimaan_id INT          NULL,
    kode_akses    VARCHAR(40)  NOT NULL UNIQUE,
    klien         VARCHAR(150) NOT NULL,
    email         VARCHAR(150),
    status        ENUM('aktif','selesai','ditutup') DEFAULT 'aktif',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (pengguna_id)   REFERENCES pengguna(id)          ON DELETE CASCADE,
    FOREIGN KEY (submission_id) REFERENCES submission_sampel(id) ON DELETE SET NULL,
    FOREIGN KEY (penerimaan_id) REFERENCES penerimaan_sampel(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 19. LOG AKTIVITAS
--     Audit trail login, logout, dan aksi penting pengguna.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS log_aktivitas (
    id          INT          AUTO_INCREMENT PRIMARY KEY,
    pengguna_id INT,
    aksi        VARCHAR(200),
    modul       VARCHAR(50),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pengguna_id) REFERENCES pengguna(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- STORED PROCEDURES & TRIGGERS
-- ============================================================

-- Drop existing procedures & triggers (idempoten)
DROP PROCEDURE IF EXISTS recalc_invoice;
DROP TRIGGER IF EXISTS trg_inv_item_insert;
DROP TRIGGER IF EXISTS trg_inv_item_update;
DROP TRIGGER IF EXISTS trg_inv_item_delete;
DROP TRIGGER IF EXISTS trg_hitung_koreksi_insert;
DROP TRIGGER IF EXISTS trg_hitung_koreksi_update;
DROP TRIGGER IF EXISTS trg_qc_recovery_insert;
DROP TRIGGER IF EXISTS trg_qc_recovery_update;
DROP TRIGGER IF EXISTS trg_sampel_after_insert;
DROP TRIGGER IF EXISTS trg_sampel_after_update;
DROP TRIGGER IF EXISTS trg_sampel_after_delete;
DROP TRIGGER IF EXISTS trg_hasil_uji_after_insert;
DROP TRIGGER IF EXISTS trg_hasil_uji_after_update;

DELIMITER $$

-- ------------------------------------------------------------
-- SP: recalc_invoice
--     Hitung ulang subtotal, diskon, ppn, dan total invoice.
--     Dipanggil otomatis oleh trigger invoice_item.
-- ------------------------------------------------------------
CREATE PROCEDURE recalc_invoice(IN inv_id INT)
BEGIN
    DECLARE v_sub   DECIMAL(15,2);
    DECLARE v_dis   DECIMAL(5,2);
    DECLARE v_ppn   DECIMAL(5,2);
    DECLARE v_dis_n DECIMAL(15,2);
    DECLARE v_ppn_n DECIMAL(15,2);
    DECLARE v_total DECIMAL(15,2);

    SELECT COALESCE(SUM(subtotal), 0) INTO v_sub
    FROM   invoice_item WHERE invoice_id = inv_id;

    SELECT diskon_pct, ppn_pct INTO v_dis, v_ppn
    FROM   invoice WHERE id = inv_id;

    SET v_dis_n = ROUND(v_sub * v_dis / 100, 2);
    SET v_ppn_n = ROUND((v_sub - v_dis_n) * v_ppn / 100, 2);
    SET v_total = v_sub - v_dis_n + v_ppn_n;

    UPDATE invoice
    SET    subtotal       = v_sub,
           diskon_nominal = v_dis_n,
           ppn_nominal    = v_ppn_n,
           total          = v_total
    WHERE  id = inv_id;
END$$

-- ------------------------------------------------------------
-- Trigger: Recalculate invoice ketika item berubah
-- ------------------------------------------------------------
CREATE TRIGGER trg_inv_item_insert
AFTER INSERT ON invoice_item FOR EACH ROW
BEGIN CALL recalc_invoice(NEW.invoice_id); END$$

CREATE TRIGGER trg_inv_item_update
AFTER UPDATE ON invoice_item FOR EACH ROW
BEGIN CALL recalc_invoice(NEW.invoice_id); END$$

CREATE TRIGGER trg_inv_item_delete
AFTER DELETE ON invoice_item FOR EACH ROW
BEGIN CALL recalc_invoice(OLD.invoice_id); END$$

-- ------------------------------------------------------------
-- Trigger: Hitung nilai_terkoreksi di hasil_uji
--          nilai_terkoreksi = nilai x faktor_pengenceran x faktor_konversi
-- ------------------------------------------------------------
CREATE TRIGGER trg_hitung_koreksi_insert
BEFORE INSERT ON hasil_uji FOR EACH ROW
BEGIN
    SET NEW.nilai_terkoreksi =
        NEW.nilai
        * COALESCE(NEW.faktor_pengenceran, 1.0)
        * COALESCE(NEW.faktor_konversi,    1.0);
END$$

CREATE TRIGGER trg_hitung_koreksi_update
BEFORE UPDATE ON hasil_uji FOR EACH ROW
BEGIN
    SET NEW.nilai_terkoreksi =
        NEW.nilai
        * COALESCE(NEW.faktor_pengenceran, 1.0)
        * COALESCE(NEW.faktor_konversi,    1.0);
END$$

-- ------------------------------------------------------------
-- Trigger: Hitung persen_recovery & flag di qc_sampel
-- ------------------------------------------------------------
CREATE TRIGGER trg_qc_recovery_insert
BEFORE INSERT ON qc_sampel FOR EACH ROW
BEGIN
    IF NEW.nilai_expected IS NOT NULL AND NEW.nilai_expected > 0 THEN
        SET NEW.persen_recovery = (NEW.nilai_qc / NEW.nilai_expected) * 100;
        IF   NEW.persen_recovery < NEW.batas_min_pct
          OR NEW.persen_recovery > NEW.batas_maks_pct THEN
            SET NEW.flag = 'fail';
        ELSEIF  NEW.persen_recovery < (NEW.batas_min_pct  + 5)
             OR NEW.persen_recovery > (NEW.batas_maks_pct - 5) THEN
            SET NEW.flag = 'warning';
        ELSE
            SET NEW.flag = 'pass';
        END IF;
    END IF;
END$$

CREATE TRIGGER trg_qc_recovery_update
BEFORE UPDATE ON qc_sampel FOR EACH ROW
BEGIN
    IF NEW.nilai_expected IS NOT NULL AND NEW.nilai_expected > 0 THEN
        SET NEW.persen_recovery = (NEW.nilai_qc / NEW.nilai_expected) * 100;
        IF   NEW.persen_recovery < NEW.batas_min_pct
          OR NEW.persen_recovery > NEW.batas_maks_pct THEN
            SET NEW.flag = 'fail';
        ELSEIF  NEW.persen_recovery < (NEW.batas_min_pct  + 5)
             OR NEW.persen_recovery > (NEW.batas_maks_pct - 5) THEN
            SET NEW.flag = 'warning';
        ELSE
            SET NEW.flag = 'pass';
        END IF;
    END IF;
END$$

-- ------------------------------------------------------------
-- Trigger: Sinkronisasi jumlah_sampel di penerimaan_sampel
--          setiap kali ada perubahan di tabel sampel.
-- ------------------------------------------------------------
CREATE TRIGGER trg_sampel_after_insert
AFTER INSERT ON sampel FOR EACH ROW
BEGIN
    IF NEW.penerimaan_id IS NOT NULL THEN
        UPDATE penerimaan_sampel
        SET    jumlah_sampel = (
                   SELECT COUNT(*) FROM sampel WHERE penerimaan_id = NEW.penerimaan_id
               )
        WHERE  id = NEW.penerimaan_id;
    END IF;
END$$

CREATE TRIGGER trg_sampel_after_update
AFTER UPDATE ON sampel FOR EACH ROW
BEGIN
    -- Update jumlah di penerimaan lama
    IF OLD.penerimaan_id IS NOT NULL THEN
        UPDATE penerimaan_sampel
        SET    jumlah_sampel = (
                   SELECT COUNT(*) FROM sampel WHERE penerimaan_id = OLD.penerimaan_id
               )
        WHERE  id = OLD.penerimaan_id;
    END IF;
    -- Update jumlah di penerimaan baru (jika berbeda dari lama)
    IF NEW.penerimaan_id IS NOT NULL
       AND NEW.penerimaan_id != COALESCE(OLD.penerimaan_id, 0) THEN
        UPDATE penerimaan_sampel
        SET    jumlah_sampel = (
                   SELECT COUNT(*) FROM sampel WHERE penerimaan_id = NEW.penerimaan_id
               )
        WHERE  id = NEW.penerimaan_id;
    END IF;
END$$

CREATE TRIGGER trg_sampel_after_delete
AFTER DELETE ON sampel FOR EACH ROW
BEGIN
    IF OLD.penerimaan_id IS NOT NULL THEN
        UPDATE penerimaan_sampel
        SET    jumlah_sampel = (
                   SELECT COUNT(*) FROM sampel WHERE penerimaan_id = OLD.penerimaan_id
               )
        WHERE  id = OLD.penerimaan_id;
    END IF;
END$$

-- ------------------------------------------------------------
-- Trigger: Update status sampel secara otomatis setelah
--          hasil uji diinput (antrian -> diuji -> selesai).
-- ------------------------------------------------------------
CREATE TRIGGER trg_hasil_uji_after_insert
AFTER INSERT ON hasil_uji FOR EACH ROW
BEGIN
    DECLARE total_param   INT;
    DECLARE pending_param INT;

    SELECT COUNT(*) INTO total_param
    FROM   hasil_uji WHERE sampel_id = NEW.sampel_id;

    SELECT COUNT(*) INTO pending_param
    FROM   hasil_uji WHERE sampel_id = NEW.sampel_id AND kesimpulan = 'pending';

    -- Pertama kali ada hasil uji: sampel berubah dari antrian -> diuji
    IF total_param = 1 THEN
        UPDATE sampel SET status = 'diuji'
        WHERE  id = NEW.sampel_id AND status = 'antrian';
    END IF;

    -- Semua parameter tidak lagi pending: sampel selesai
    IF total_param > 0 AND pending_param = 0 THEN
        UPDATE sampel SET status = 'selesai'
        WHERE  id = NEW.sampel_id AND status IN ('diuji','review');
    END IF;
END$$

CREATE TRIGGER trg_hasil_uji_after_update
AFTER UPDATE ON hasil_uji FOR EACH ROW
BEGIN
    DECLARE pending_param INT;

    SELECT COUNT(*) INTO pending_param
    FROM   hasil_uji WHERE sampel_id = NEW.sampel_id AND kesimpulan = 'pending';

    IF pending_param = 0 THEN
        UPDATE sampel SET status = 'selesai'
        WHERE  id = NEW.sampel_id AND status IN ('diuji','review');
    END IF;
END$$

DELIMITER ;


-- ============================================================
-- SEED DATA AWAL
-- ============================================================

-- ------------------------------------------------------------
-- Akun pengguna default
-- Password untuk semua akun: password
-- (bcrypt hash dari string 'password')
-- ------------------------------------------------------------
INSERT IGNORE INTO pengguna (nama, username, password, email, role, status) VALUES
('Administrator Lab',      'admin',      '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@labmineral.com',  'admin',      'aktif'),
('Rani Dewi, S.Si',        'rani.d',     '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'rani@lab.com',          'analis',     'aktif'),
('Budi Santoso',           'budi.s',     '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'budi@lab.com',          'analis',     'aktif'),
('Dr. Siti Aminah, M.Sc',  'supervisor', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'siti@lab.com',          'supervisor', 'aktif'),
('PT. Aneka Tambang',      'aneka.tm',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'contact@antam.com',     'client',     'aktif'),
('PT. Freeport Indonesia', 'freeport',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'info@freeport.co.id',   'client',     'aktif');

-- ------------------------------------------------------------
-- Tarif pengujian standar
-- ------------------------------------------------------------
INSERT IGNORE INTO tarif_pengujian (nama, metode, parameter, harga, satuan) VALUES
('AAS — Logam tunggal',       'AAS',        NULL,            250000, 'per parameter'),
('XRF — Oksida mayor',        'XRF',        NULL,            300000, 'per parameter'),
('ICP-OES — Multi elemen',    'ICP-OES',    NULL,            350000, 'per parameter'),
('Gravimetri',                'Gravimetri', NULL,            200000, 'per parameter'),
('Fire Assay — Au (Emas)',     'Fire Assay', 'Au (Emas)',     500000, 'per sampel'),
('Fire Assay — Ag (Perak)',    'Fire Assay', 'Ag (Perak)',    400000, 'per sampel'),
('Preparasi Destruksi Asam',  NULL,         NULL,            150000, 'per sampel'),
('Preparasi Fusion',          NULL,         NULL,            200000, 'per sampel');

-- ------------------------------------------------------------
-- Metode preparasi default
-- ------------------------------------------------------------
INSERT IGNORE INTO metode_preparasi (metode) VALUES
('Destruksi Asam'),
('Ekstraksi'),
('Pengenceran'),
('Fusion'),
('Lainnya');

-- ------------------------------------------------------------
-- Peralatan laboratorium contoh
-- ------------------------------------------------------------
INSERT IGNORE INTO peralatan (kode_alat, nama, lokasi, status) VALUES
('AAS-001',     'Atomic Absorption Spectrophotometer (AAS)', 'Ruang Instrumen A', 'tersedia'),
('XRF-001',     'X-Ray Fluorescence Spectrometer (XRF)',     'Ruang Instrumen B', 'tersedia'),
('ICP-001',     'ICP-OES Spectrometer',                      'Ruang Instrumen A', 'tersedia'),
('FA-FURN-01',  'Fire Assay Furnace',                        'Ruang Fire Assay',  'tersedia'),
('TIMBANG-01',  'Timbangan Analitik 4 Desimal',              'Ruang Timbang',     'tersedia');

-- ------------------------------------------------------------
-- Bahan / reagen laboratorium contoh
-- ------------------------------------------------------------
INSERT IGNORE INTO bahan (kode_bahan, nama, stok, satuan, stok_minimum) VALUES
('HCl-37',    'Asam Klorida (HCl) 37%',     5.000,   'Liter', 1.000),
('HNO3-65',   'Asam Nitrat (HNO3) 65%',     3.500,   'Liter', 0.500),
('H2SO4-98',  'Asam Sulfat (H2SO4) 98%',    2.000,   'Liter', 0.500),
('AQR-001',   'Aqua Regia (HCl:HNO3=3:1)',  1.000,   'Liter', 0.200),
('Na2CO3',    'Sodium Karbonat (Na2CO3)',  500.000,   'gram',  100.000);

-- ------------------------------------------------------------
-- Data penerimaan & sampel contoh (data demo)
-- ------------------------------------------------------------
INSERT IGNORE INTO penerimaan_sampel
    (nomor_penerimaan, klien, tanggal_terima, jumlah_sampel, jenis_material, metode_uji, status, dibuat_oleh)
VALUES
    ('REC-2603-001', 'PT. Freeport Indonesia', '2026-03-13', 1, 'Bijih Emas', 'Fire Assay', 'diproses', 1);

INSERT IGNORE INTO sampel
    (penerimaan_id, kode_sampel, tanggal_masuk, jenis_material, berat_gram, klien, metode_uji, status, dibuat_oleh)
VALUES
    (1, 'S-2603-001', '2026-03-13', 'Bijih Emas', 500.000, 'PT. Freeport Indonesia', 'Fire Assay', 'antrian', 1);

-- ============================================================
-- END OF MIGRATION
-- ============================================================
