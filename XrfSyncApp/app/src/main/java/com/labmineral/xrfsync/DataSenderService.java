package com.labmineral.xrfsync;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.Service;
import android.content.Intent;
import android.os.Build;
import android.os.IBinder;
import android.util.Log;

import androidx.core.app.NotificationCompat;

import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.util.List;

public class DataSenderService extends Service {
    private boolean isRunning = false;
    private static final String TAG = "DataSenderService";
    private static final String CHANNEL_ID = "XrfSyncChannel";
    
    // Sesuaikan URL ini dengan alamat server LIMS lokal Anda
    private static final String API_URL = "http://192.168.1.100/labmineral/api/api_xrf_receive.php";
    private static final String API_KEY = "xrf_secret_labmineral_2026";
    
    private DatabaseHelper dbHelper;

    @Override
    public void onCreate() {
        super.onCreate();
        Log.d(TAG, "Service Created");
        createNotificationChannel();
        dbHelper = new DatabaseHelper(this);
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        if (!isRunning) {
            isRunning = true;
            Log.d(TAG, "Service Started in Background");
            
            Notification notification = new NotificationCompat.Builder(this, CHANNEL_ID)
                    .setContentTitle("XRF Sync Service")
                    .setContentText("Menjaga data tetap aman. Tersinkronisasi saat Wi-Fi aktif.")
                    .setSmallIcon(android.R.drawable.ic_dialog_info)
                    .build();
            startForeground(1, notification);

            new Thread(new Runnable() {
                @Override
                public void run() {
                    while (isRunning) {
                        try {
                            // 1. Simulasikan: Setiap ada scan baru dari alat, SIMPAN KE DATABASE LOKAL DULU
                            // Ini memastikan data tidak akan hilang meskipun Wi-Fi mati
                            String newScanData = getDummyJsonData();
                            dbHelper.insertScan(newScanData);
                            
                            // 2. Cek database lokal: Adakah data scan yang BELUM TERKIRIM?
                            List<DatabaseHelper.ScanRecord> unsentScans = dbHelper.getUnsentScans();
                            
                            if (unsentScans.size() > 0) {
                                Log.d(TAG, "Mencoba mengirim " + unsentScans.size() + " data yang belum terkirim...");
                            }

                            for (DatabaseHelper.ScanRecord scan : unsentScans) {
                                boolean success = sendDataToServer(scan.payload);
                                if (success) {
                                    // 3. JIKA SUKSES TERKIRIM KE WEB, tandai sebagai sukses (1)
                                    dbHelper.markAsSent(scan.id);
                                    Log.d(TAG, "Data ID " + scan.id + " sukses terkirim dan ditandai selesai.");
                                } else {
                                    // 4. JIKA GAGAL (TIDAK ADA WIFI), biarkan saja. Akan dicoba lagi di putaran berikutnya.
                                    Log.d(TAG, "Tidak ada koneksi. Data ID " + scan.id + " ditahan di SQLite lokal.");
                                    break; // Berhenti mencoba sisa data jika sedang offline
                                }
                            }
                            
                            Thread.sleep(30000); // Tunggu 30 detik
                        } catch (InterruptedException e) {
                            e.printStackTrace();
                        }
                    }
                }
            }).start();
        }
        return START_STICKY; 
    }

    private String getDummyJsonData() {
        return "{\n" +
                "  \"api_key\": \"" + API_KEY + "\",\n" +
                "  \"device_id\": \"XRF-NEW-APP\",\n" +
                "  \"db_source\": \"connection_test\",\n" +
                "  \"sample_name\": \"SCAN_\" + System.currentTimeMillis(),\n" +
                "  \"elements\": []\n" +
                "}";
    }

    // Mengembalikan true jika sukses terkirim, false jika gagal (offline)
    private boolean sendDataToServer(String jsonPayload) {
        try {
            URL url = new URL(API_URL);
            HttpURLConnection conn = (HttpURLConnection) url.openConnection();
            conn.setRequestMethod("POST");
            conn.setRequestProperty("Content-Type", "application/json; utf-8");
            conn.setRequestProperty("Accept", "application/json");
            conn.setDoOutput(true);
            conn.setConnectTimeout(5000);
            conn.setReadTimeout(5000);

            try (OutputStream os = conn.getOutputStream()) {
                byte[] input = jsonPayload.getBytes("utf-8");
                os.write(input, 0, input.length);
            }

            int code = conn.getResponseCode();
            return (code >= 200 && code < 300);
            
        } catch (Exception e) {
            return false;
        }
    }

    private void createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            NotificationChannel serviceChannel = new NotificationChannel(
                    CHANNEL_ID,
                    "XRF Sync Service Channel",
                    NotificationManager.IMPORTANCE_LOW
            );
            NotificationManager manager = getSystemService(NotificationManager.class);
            if (manager != null) {
                manager.createNotificationChannel(serviceChannel);
            }
        }
    }

    @Override
    public void onDestroy() {
        isRunning = false;
        super.onDestroy();
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }
}
