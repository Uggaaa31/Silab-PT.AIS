# Silab-PT.AIS

Sistem Informasi Manajemen Laboratorium Mineral (LabMineral Pro) - PT. AIS.

## Fitur Utama
- **Manajemen Sampel & Batch**: Penerimaan, registrasi, dan pelacakan status sampel realtime.
- **Work Order & Penugasan**: Alur kerja analis, preparasi sampel, dan pengujian instrumen.
- **Preparasi & QC**: Manajemen reagen, kontrol kualitas (blanko, standar, spike, duplikat), dan validasi hasil.
- **Integrasi XRF Explorer 7000**: Sinkronisasi data otomatis dari aplikasi mobile / instrumen XRF via REST API.
- **Multi-Role Access**: Hak akses berjenjang (Admin, Supervisor, Analis, Client).
- **Deployment Ready**: Terintegrasi Docker Compose (MariaDB + PHP 8.2 Apache) dan Cloudflare Tunnel.

## Menjalankan dengan Docker
1. Salin konfigurasi environment:
   ```bash
   cp .env.example .env
   ```
2. Isi kredensial dan Cloudflare Tunnel Token di `.env`.
3. Jalankan container:
   ```bash
   docker compose up -d --build
   ```
