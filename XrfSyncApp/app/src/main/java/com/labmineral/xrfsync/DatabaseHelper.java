package com.labmineral.xrfsync;

import android.content.ContentValues;
import android.content.Context;
import android.database.Cursor;
import android.database.sqlite.SQLiteDatabase;
import android.database.sqlite.SQLiteOpenHelper;
import android.util.Log;

import java.util.ArrayList;
import java.util.List;

public class DatabaseHelper extends SQLiteOpenHelper {

    private static final String DATABASE_NAME = "xrf_offline.db";
    private static final int DATABASE_VERSION = 1;

    public static final String TABLE_SCANS = "scans";
    public static final String COLUMN_ID = "id";
    public static final String COLUMN_PAYLOAD = "payload_json";
    public static final String COLUMN_IS_SENT = "is_sent";
    public static final String COLUMN_CREATED_AT = "created_at";

    public DatabaseHelper(Context context) {
        super(context, DATABASE_NAME, null, DATABASE_VERSION);
    }

    @Override
    public void onCreate(SQLiteDatabase db) {
        String CREATE_TABLE = "CREATE TABLE " + TABLE_SCANS + "("
                + COLUMN_ID + " INTEGER PRIMARY KEY AUTOINCREMENT,"
                + COLUMN_PAYLOAD + " TEXT,"
                + COLUMN_IS_SENT + " INTEGER DEFAULT 0,"
                + COLUMN_CREATED_AT + " DATETIME DEFAULT CURRENT_TIMESTAMP"
                + ")";
        db.execSQL(CREATE_TABLE);
    }

    @Override
    public void onUpgrade(SQLiteDatabase db, int oldVersion, int newVersion) {
        db.execSQL("DROP TABLE IF EXISTS " + TABLE_SCANS);
        onCreate(db);
    }

    public void insertScan(String jsonPayload) {
        SQLiteDatabase db = this.getWritableDatabase();
        ContentValues values = new ContentValues();
        values.put(COLUMN_PAYLOAD, jsonPayload);
        values.put(COLUMN_IS_SENT, 0);

        long id = db.insert(TABLE_SCANS, null, values);
        Log.d("DatabaseHelper", "Data scan offline disimpan dengan ID: " + id);
        db.close();
    }

    public void markAsSent(int id) {
        SQLiteDatabase db = this.getWritableDatabase();
        ContentValues values = new ContentValues();
        values.put(COLUMN_IS_SENT, 1);
        db.update(TABLE_SCANS, values, COLUMN_ID + " = ?", new String[]{String.valueOf(id)});
        db.close();
    }

    public List<ScanRecord> getUnsentScans() {
        List<ScanRecord> scanList = new ArrayList<>();
        SQLiteDatabase db = this.getReadableDatabase();
        Cursor cursor = db.rawQuery("SELECT * FROM " + TABLE_SCANS + " WHERE " + COLUMN_IS_SENT + " = 0", null);

        if (cursor.moveToFirst()) {
            do {
                ScanRecord record = new ScanRecord();
                record.id = cursor.getInt(cursor.getColumnIndexOrThrow(COLUMN_ID));
                record.payload = cursor.getString(cursor.getColumnIndexOrThrow(COLUMN_PAYLOAD));
                scanList.add(record);
            } while (cursor.moveToNext());
        }
        cursor.close();
        db.close();
        return scanList;
    }

    public static class ScanRecord {
        public int id;
        public String payload;
    }
}
