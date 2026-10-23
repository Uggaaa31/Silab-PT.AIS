# Alur Operasional & Aliran Data Sistem (End-to-End Workflow)
**SILAB — PT. AIS (Sistem Informasi Manajemen Laboratorium Mineral)**

Dokumen ini menjelaskan alur operasional laboratorium secara menyeluruh, mulai dari permohonan pengiriman sampel oleh klien melalui **Sample Submission Form (SSF)**, penerimaan fisik, penerbitan **Work Order (WO)**, preparasi, **penginputan hasil spektrometri XRF otomatis**, validasi mutu **QC**, hingga penerbitan **Sertifikat Analisis (CoA)** dan **Invoice / Nota Pembayaran**.

---

## 1. Diagram Alur Proses Utama (High-Level Workflow)

```mermaid
flowchart TD
    %% STAGE 1: SSF
    subgraph S1 ["1. Pengajuan Sampel (SSF)"]
        CLIENT(["Klien / Pemohon"]) -->|Isi Form Publik| F_SSF["pages/ssf.php\nNomor: SUB-YYMMDD-XXXX"]
        F_SSF -->|Auto-Create Akun| CLIENT_ACC[("client_access\nUsername + Password")]
        F_SSF -->|Notifikasi WhatsApp| WA_NOTIF["Konfirmasi WA Otomatis"]
        F_SSF -->|Verifikasi Admin| ADMIN_SUB["pages/submission.php\nStatus: Diterima Sistem"]
    end

    %% STAGE 2: RECEIPT
    subgraph S2 ["2. Penerimaan Sampel Fisik"]
        ADMIN_SUB -->|Sampel Fisik Tiba di Lab| F_REC["pages/penerimaan.php\nNomor: REC-YYMM-XXX"]
        F_REC -->|Registrasi Kode Sampel| DB_SMPL[("Tabel sampel\nKode: S-YYMM-XXX")]
        F_REC -->|Cetak Label & Barcode| LBL["Label / QR Code Sampel"]
    end

    %% STAGE 3: WORK ORDER
    subgraph S3 ["3. Work Order & Penugasan"]
        DB_SMPL --> F_WO["pages/work_order.php\nNomor: WO-YYMM-XXX"]
        F_WO -->|Plotting Analis| ANALIS["Penugasan Analis"]
        F_WO -->|Cek Kebutuhan Prep| C_PREP{"Butuh Preparasi?"}
    end

    %% STAGE 4: PREPARASI
    subgraph S4 ["4. Preparasi Sampel"]
        C_PREP -->|Ya| F_PREP["pages/preparasi.php\nCrushing, Pulverizing, Pellet"]
        F_PREP -->|Potong Stok Reagen| DB_BAHAN[("pages/bahan.php\nInventaris Bahan")]
        F_PREP -->|Siap Uji| F_TEST
    end

    %% STAGE 5: TESTING & XRF
    subgraph S5 ["5. Pengujian & Integrasi Data XRF"]
        C_PREP -->|Tidak (Direct)| F_TEST["pages/pengujian.php\nInput Lembar Hasil Uji"]
        
        %% ALUR XRF
        subgraph XRF_FLOW ["Integrasi Otomatis XRF"]
            ALAT_XRF["Handheld XRF Explorer 7000\n(Penembakan Sinar-X)"] -->|SQLite Lokal| SYNC["XrfSyncApp (Android)\nDataSenderService"]
            SYNC -->|HTTP POST JSON| API_XRF["api/api_xrf_receive.php"]
            API_XRF --> DB_XRF[("xrf_measurements\nxrf_measurement_elements")]
            DB_XRF --> AJAX_XRF["actions/get_xrf_measurements.php"]
        end
        
        AJAX_XRF -->|Auto-Suggest & Averaging Multi-Titik| F_TEST
        F_TEST --> DB_HASIL[("hasil_uji\nKadar %, ppm")]
    end

    %% STAGE 6: QC & VALIDASI
    subgraph S6 ["6. QC & Validasi Mutu"]
        DB_HASIL --> F_QC["pages/qc.php\nCek Blanko, CRM OREAS, Duplikat"]
        F_QC --> SPV{"Validasi Supervisor\nFlag: Pass / Warning / Fail"}
        SPV -->|Re-Test jika Gagal| F_TEST
        SPV -->|Lulus Validasi| READY_REPORT[("Hasil Uji Divalidasi\nkesimpulan = 'lulus'")]
    end

    %% STAGE 7 & 8: LAPORAN & INVOICE
    subgraph S7 ["7. Laporan & Sertifikat (CoA)"]
        READY_REPORT --> F_COA["pages/laporan.php\nexports/export_pdf.php\nCetak Sertifikat / CoA"]
    end

    subgraph S8 ["8. Penagihan & Nota (Invoice)"]
        READY_REPORT --> F_INV["pages/invoice.php\nNomor: INV-YYMM-XXX"]
        F_INV -->|Hitung Tarif Master| DB_TARIF[("tarif_pengujian\nBiaya Uji + Prep + PPN 11%")]
        F_INV --> F_PRINT_INV["exports/cetak_invoice.php\nCetak Nota / Kwitansi Pembayaran"]
        F_INV -->|Update Status| INV_LUNAS{"Status: Lunas?"}
    end

    %% MONITORING CLIENT
    subgraph S_CLIENT ["Monitoring Real-Time Klien"]
        CLIENT_ACC -.->|Login Mandiri| F_MON["pages/client_monitoring.php\nLive Stepper 6 Tahap\nDownload CoA & Invoice"]
        S1 -.-> F_MON
        S2 -.-> F_MON
        S4 -.-> F_MON
        S5 -.-> F_MON
        S6 -.-> F_MON
        S8 -.-> F_MON
    end
```

---

## 2. Tahap Demi Tahap Alur Sistem (Detail Breakdown)

---

### TAHAP 1: Pengajuan Sampel / SSF (Sample Submission Form)
* **Halaman Web**: 
  - Klien (Publik): [`pages/ssf.php`](file:///c:/laragon/www/silab/pages/ssf.php)
  - Admin (Internal): [`pages/submission.php`](file:///c:/laragon/www/silab/pages/submission.php)
* **Aktor**: Klien (Pemohon Pengujian) & Administrator Laboratorium.
* **Proses Operasional**:
  1. Klien mengakses halaman form SSF publik tanpa perlu login terlebih dahulu.
  2. Klien melengkapi data:
     - **Identitas Perusahaan/Klien**: Nama instansi, kontak person, email, nomor WhatsApp, alamat, dan nomor PO/Referensi kerja.
     - **Daftar Sampel**: Jenis material (*Nikel Laterit, Bijih Besi, Emas, Tembaga, Bauksit, dll.*), estimasi berat sampel (gram), metode analisis yang diminta (*XRF, AAS, ICP-OES, Fire Assay*), serta parameter target (*Ni, Fe, Co, SiO2, MgO, Au, dll.*).
     - **Instruksi Khusus**: Kebutuhan perlakuan khusus, misal target deteksi presisi atau prioritas pengerjaan.
  3. Saat formulir di-submit:
     - Sistem menerbitkan nomor permohonan otomatis: `SUB-YYMMDD-XXXX`.
     - Sistem otomatis membuatkan akun kredensial portal client ([`client_access`](file:///c:/laragon/www/silab/pages/client_monitoring.php)) dengan *username* klien dan *password* default (`client123`).
     - Tampil tombol otomatis untuk mengirimkan konfirmasi via **WhatsApp API link** ke pihak pemohon.
  4. Status awal submission tercatat sebagai: `pending` atau `diterima` (oleh sistem).

---

### TAHAP 2: Penerimaan Fisik & Registrasi Laboratorium
* **Halaman Web**: [`pages/penerimaan.php`](file:///c:/laragon/www/silab/pages/penerimaan.php) & [`pages/sampel.php`](file:///c:/laragon/www/silab/pages/sampel.php)
* **Aktor**: Administrator / Petugas Penerima Sampel (*Sample Receptionist*).
* **Proses Operasional**:
  1. Sampel fisik tiba di laboratorium (diantar kurir atau pihak klien).
  2. Petugas membuka menu **Penerimaan Sampel** dan mengklik opsi **"Input dari Submission"** (`?from_submission=ID`).
  3. Seluruh data dari SSF otomatis terisi ke form penerimaan tanpa perlu mengetik ulang:
     - Petugas memeriksa kondisi fisik sampel (*kondisi utuh, kemasan rusak, basah/kering, kesesuaian jumlah kantong sampel*).
  4. Sistem menerbitkan:
     - **Nomor Penerimaan Batch**: `REC-YYMM-XXX` (disimpan di tabel `penerimaan_sampel`).
     - **Kode Sampel Laboratorium Unik**: `S-YYMM-XXX` untuk setiap kantong sampel (disimpan di tabel `sampel` dengan status `antrian`).
  5. Petugas mencetak label barcode/QR code dan menempelkannya pada kantong sampel. Status SSF diperbarui menjadi `diproses`.

---

### TAHAP 3: Penerbitan Work Order (WO) & Penugasan Analis
* **Halaman Web**: [`pages/work_order.php`](file:///c:/laragon/www/silab/pages/work_order.php)
* **Aktor**: Manajer Teknis / Supervisor Laboratorium / Admin.
* **Proses Operasional**:
  1. Supervisor melihat daftar batch penerimaan yang berada dalam antrean.
  2. Supervisor menerbitkan surat perintah kerja resmi: **Nomor WO: `WO-YYMM-XXX`**.
  3. Penugasan detail dalam Work Order:
     - Mengaitkan nomor penerimaan batch (`REC-...`) atau memilih sampel secara individual (pivot `work_order_sampel`).
     - Menunjuk Analis penanggung jawab (`analis_id`).
     - Menentukan apakah sampel **Butuh Preparasi Fisik** (`butuh_preparasi = 1`) atau langsung diuji (*direct testing* `butuh_preparasi = 0`).
     - Memilih metode uji utama (*XRF Spektrometri, AAS, Titrasi, dll.*) dan parameter target.
     - Menetapkan target tanggal selesai (deadline) dan tingkat prioritas (*Biasa, Mendesak*).
  4. Status WO diubah menjadi `aktif`. Di dashboard Analis terkait, muncul badge notifikasi tugas baru.

---

### TAHAP 4: Preparasi Sampel (Jika Dibutuhkan)
* **Halaman Web**: [`pages/preparasi.php`](file:///c:/laragon/www/silab/pages/preparasi.php), [`pages/metode_preparasi.php`](file:///c:/laragon/www/silab/pages/metode_preparasi.php), & [`pages/bahan.php`](file:///c:/laragon/www/silab/pages/bahan.php)
* **Aktor**: Analis Preparasi.
* **Proses Operasional**:
  1. Analis mengambil sampel fisik berdasarkan nomor Work Order yang ditugaskan.
  2. Melakukan tahapan perlakuan awal fisik dan kimia:
     - **Drying & Moisture**: Pengeringan sampel dalam oven pada suhu $105^\circ\text{C}$.
     - **Crushing & Pulverizing**: Penghancuran bongkahan batu/mineral dan penggilingan hingga kehalusan mesh 200 ($74\,\mu\text{m}$).
     - **Pelleting (Khusus XRF)**: Pengepresan serbuk mineral menjadi pelet padat (*pressed pellet*) menggunakan *hydraulic press* dan bahan pengikat (*binding agent* seperti asam borat/wax).
     - **Destruksi Asam (Khusus AAS/ICP)**: Pelarutan serbuk menggunakan campuran asam kuat ($HNO_3, HCl, HF$).
  3. Analis menginput rekaman kerja preparasi:
     - Berat awal & berat akhir (gram).
     - Reagen kimia yang digunakan (sistem otomatis memotong stok inventaris di `pages/bahan.php`).
     - Menyiapkan sampel pengendali mutu: **Blanko**, **Standar CRM (OREAS)**, **Spike**, dan **Duplikat**.
  4. Status preparasi ditandai `selesai`. Sampel siap diserahkan ke meja instrumen uji.

---

### TAHAP 5: Pengujian Laboratorium & Integrasi Data XRF
* **Halaman Web & Endpoint**:
  - Frontend Uji: [`pages/pengujian.php`](file:///c:/laragon/www/silab/pages/pengujian.php)
  - AJAX Controller: [`actions/get_xrf_measurements.php`](file:///c:/laragon/www/silab/actions/get_xrf_measurements.php)
  - Ingestion API: [`api/api_xrf_receive.php`](file:///c:/laragon/www/silab/api/api_xrf_receive.php)
  - Raw Data Explorer: [`pages/xrf_data.php`](file:///c:/laragon/www/silab/pages/xrf_data.php)
* **Aktor**: Analis Pengujian & Spektrometer Portabel (XRF Explorer 7000 / multi-unit `XRF01`–`XRF04`).
* **Proses Operasional & Otomasi XRF**:
  1. **Penembakan Sinar-X di Alat**:
     - Operator menempatkan sampel mineral/pelet pada sensor instrumen XRF dan memulai scan (durasi 30 detik).
     - Hasil scan tersimpan pada database internal alat (`mineral.db` / `alloy.db`).
  2. **Transmisi Otomatis (Wi-Fi)**:
     - Aplikasi Android *XrfSyncApp* membaca scan baru dan mengirimkannya via HTTP POST JSON ke endpoint `api/api_xrf_receive.php`.
     - Server SILAB memvalidasi API Key, mencatat kondisi tabung ($kV, \mu A, \text{cps}$), sensor lingkungan, dan menguraikan konsentrasi unsur ke tabel `xrf_measurements` & `xrf_measurement_elements`.
  3. **Input di Antarmuka SILAB (`pengujian.php`)**:
     - Analis membuka Work Order pada halaman `pengujian.php`.
     - Analis memilih kode sampel yang sedang dikerjakan, lalu mengklik tombol **"⚡ Ambil dari XRF"**.
     - **Fitur Auto-Suggest**: Sistem mencocokkan kode sampel secara cerdas. Jika ditemukan kecocokan scan $\ge 2$ data, tombol otomatis *Auto-Pilih N Scan* muncul.
     - **Multi-Point Averaging**: Jika sampel ditembak pada beberapa titik (*titik 1, titik 2, titik 3*), analis cukup mencentang seluruh titik tersebut. Sistem otomatis menghitung nilai rata-rata (*averaging*) untuk setiap unsur ($Ni, Fe, SiO_2, MgO, Co, Al_2O_3$).
     - Nilai rata-rata langsung otomatis terisi ke form input hasil uji.
  4. Analis memeriksa angka pembacaan, mengisi data pembacaan instrumen, dan menyimpan hasil ke tabel `hasil_uji`.

---

### TAHAP 6: QC & Validasi Mutu Hasil Analisis
* **Halaman Web**: [`pages/qc.php`](file:///c:/laragon/www/silab/pages/qc.php)
* **Aktor**: Supervisor Laboratorium / QA-QC Officer.
* **Proses Operasional**:
  1. Supervisor membuka modul **QC & Validasi**.
  2. Melakukan evaluasi parameter akurasi dan presisi:
     - **Nilai Blanko**: Memastikan kontaminasi silang nihil (konsentrasi harus di bawah batas deteksi instrumen).
     - **Standar Referensi Bersertifikat (CRM OREAS)**: Menghitung persentase perolehan kembali (% Recovery):
       $$\% \text{Recovery} = \frac{\text{Nilai Terukur}}{\text{Nilai Tersertifikasi}} \times 100\%$$
       *(Harus masuk rentang keberterimaan, misal $98\% - 102\%$)*.
     - **Sampel Duplikat**: Menghitung *Relative Percent Difference* (% RPD) untuk mengukur presisi operator.
  3. Menetapkan status kelayakan hasil:
     - `pass` (Lulus): Hasil akurat dan presisi, siap diterbitkan.
     - `fail` (Gagal): Terdapat deviasi abnormal; Work Order dikembalikan ke analis untuk preparasi ulang atau penembakan scan ulang.
  4. Hasil uji yang disetujui divalidasi resmi dengan penanda `kesimpulan = 'lulus'`.

---

### TAHAP 7: Penerbitan Sertifikat Hasil Analisis (CoA / Laporan)
* **Halaman Web**: [`pages/laporan.php`](file:///c:/laragon/www/silab/pages/laporan.php) & [`exports/export_pdf.php`](file:///c:/laragon/www/silab/exports/export_pdf.php)
* **Aktor**: Supervisor / Manajer Teknis & Admin.
* **Proses Operasional**:
  1. Sistem mengumpulkan seluruh data hasil uji sampel yang telah berstatus `lulus` validasi QC.
  2. Administrator atau Supervisor mengklik **"Export PDF per Klien"**.
  3. Dokumen resmi **Report of Analysis / Certificate of Analysis (CoA)** digenerate secara otomatis dengan standar industri:
     - KOP Surat resmi PT. AIS (Aispektra Laboratory).
     - Identitas klien, nomor penerimaan, tanggal registrasi, dan tanggal selesai uji.
     - Tabel kandungan unsur lengkap (metode pengujian XRF, satuan %, batas deteksi, dan hasil uji).
     - Tanda tangan digital / nama pengesah (*Head of Laboratory / Chemist in Charge*).
  4. CoA dapat diunduh langsung oleh staf lab atau diakses mandiri oleh klien melalui portal monitoring.

---

### TAHAP 8: Pembuatan Invoice & Nota Pembayaran
* **Halaman Web**: [`pages/invoice.php`](file:///c:/laragon/www/silab/pages/invoice.php) & [`exports/cetak_invoice.php`](file:///c:/laragon/www/silab/exports/cetak_invoice.php)
* **Aktor**: Bagian Administrasi / Keuangan & Klien.
* **Proses Operasional**:
  1. Bagian Keuangan membuka menu **Invoice** dan mengklik **"Buat Invoice Baru"**.
  2. Memilih nomor penerimaan batch sampel (`penerimaan_id`).
  3. Sistem men-generate **Nomor Invoice: `INV-YYMM-XXX`**.
  4. Sistem otomatis memuat rincian tarif uji dari master data `tarif_pengujian`:
     - Biaya pengujian unsur via XRF per sampel ($\text{Tarif} \times \text{Jumlah Sampel}$).
     - Biaya preparasi fisik sampel (*crushing, pulverizing, pelleting*).
     - Biaya perlakuan khusus atau reagen (jika ada).
     - Diskon khusus (jika disepakati) dan Pajak Pertambahan Nilai (**PPN 11%**).
     - Total nilai tagihan (*Grand Total*).
  5. **Penerbitan & Pencetakan**:
     - Invoice diterbitkan (`status = 'diterbitkan'`).
     - Admin mencetak **Nota / Kwitansi Resmi** melalui [`exports/cetak_invoice.php`](file:///c:/laragon/www/silab/exports/cetak_invoice.php).
  6. **Pelunasan**:
     - Klien melakukan pembayaran melalui transfer bank laboratorium.
     - Admin mengonfirmasi pembayaran dan mengubah status menjadi `lunas`.
  7. Klien dapat melihat bukti pelunasan dan mengunduh nota secara real-time di portal [client_monitoring.php](file:///c:/laragon/www/silab/pages/client_monitoring.php).

---

## 3. Matriks Peran Pengguna (Role-Based Access Control)

Sistem SILAB menerapkan pembatasan hak akses berbasis peran (*Role-Based*) demi menjaga integritas data laboratorium:

| Modul / Halaman | Klien (Eksternal) | Administrator | Supervisor | Analis |
| :--- | :---: | :---: | :---: | :---: |
| **Form SSF Publik** (`ssf.php`) | 🟢 Input Mandiri | 🟢 Akses Penuh | 🟢 Akses Penuh | ⚪ Akses Web |
| **Manajemen Permintaan SSF** (`submission.php`) | ⚪ Tidak Ada Akses | 🟢 Full CRUD & WA | 🟡 View Only | ⚪ Tidak Ada Akses |
| **Penerimaan Sampel** (`penerimaan.php`) | ⚪ Tidak Ada Akses | 🟢 Full Input & Barcode | 🟡 View Only | ⚪ Tidak Ada Akses |
| **Work Order** (`work_order.php`) | ⚪ Tidak Ada Akses | 🟢 Buat & Atur WO | 🟢 Validasi & Plot | 🟡 View Task Terjadwal |
| **Preparasi Sampel** (`preparasi.php`) | ⚪ Tidak Ada Akses | 🟡 Monitoring | 🟡 Monitoring | 🟢 Input Data Preparasi |
| **Pengujian & Input XRF** (`pengujian.php`) | ⚪ Tidak Ada Akses | 🟡 Monitoring | 🟡 Monitoring | 🟢 Input Data & Fetch XRF |
| **Explorer Data XRF** (`xrf_data.php`) | ⚪ Tidak Ada Akses | 🟢 Full Explorer | 🟡 View Only | ⚪ Tidak Ada Akses |
| **QC & Validasi Mutu** (`qc.php`) | ⚪ Tidak Ada Akses | 🟡 Monitoring | 🟢 Otoritas Validasi | 🟡 Input Duplikat/CRM |
| **Laporan & Cetak CoA** (`laporan.php`) | ⚪ Tidak Ada Akses | 🟢 Ekspor PDF/Excel | 🟢 Pengesahan CoA | ⚪ Tidak Ada Akses |
| **Invoice & Nota** (`invoice.php`) | ⚪ Tidak Ada Akses | 🟢 Terbitkan & Kasir | 🟡 View Only | ⚪ Tidak Ada Akses |
| **Portal Client Monitoring** (`client_monitoring.php`)| 🟢 Live Tracking & Download | ⚪ Admin View | ⚪ Supervisor View | ⚪ Analis View |

---

## 4. Pelacakan Status Real-Time di Portal Klien (`client_monitoring.php`)

Klien dapat memantau posisi sampel mereka secara transparan melalui *timeline stepper* 6 tahap:

```
[Tahap 1: Pengajuan SSF] ➔ Selesai diajukan secara online (Klien)
          │
[Tahap 2: Penerimaan Fisik] ➔ Sampel diterima dan diverifikasi di lab (Admin)
          │
[Tahap 3: Work Order & Preparasi] ➔ Sampel ditugaskan ke analis & dipreparasi pelet (Analis Prep)
          │
[Tahap 4: Pengujian XRF] ➔ Sampel ditembak spektrometer & data masuk sistem (Analis Uji)
          │
[Tahap 5: QC & Validasi Mutu] ➔ Data diuji silang CRM OREAS & disetujui (Supervisor)
          │
[Tahap 6: CoA & Nota Selesai] ➔ Klien mengunduh Sertifikat Analisis & Nota Pembayaran
```

Dokumentasi ini mencakup seluruh siklus hidup sampel di SILAB PT. AIS dari kedatangan awal hingga penyelesaian administrasi dan pembayaran.
