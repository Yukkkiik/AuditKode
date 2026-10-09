# CodeAudit AI — Dokumentasi Kontrak API

> **Versi:** 1.0.0  
> **Status:** Rancangan untuk MVP / Tahap 1  
> **Base URL development:** `http://localhost:8000/api/v1`  
> **Tujuan:** Menjadi kesepakatan format komunikasi antara frontend Next.js dan backend Laravel.

Dokumen ini mendefinisikan endpoint, format request dan response, status audit, validasi, serta penanganan error. Implementasi backend dan frontend harus mengikuti kontrak ini. Jika format API berubah, perbarui dokumen ini juga.

---

## 1. Ruang Lingkup Tahap 1

Tahap 1 mendukung audit dengan mengunggah berkas ZIP yang berisi kode sumber.

- **Didukung:** `source_type = zip`.
- **Belum didukung:** audit melalui URL Git. Request dengan `source_type = git` harus mengembalikan HTTP `422` dengan kode `SOURCE_TYPE_NOT_SUPPORTED`.
- **Ukuran maksimum berkas:** 50 MB.
- **Pemrosesan:** asynchronous melalui antrean. Endpoint upload menerima pekerjaan; proses audit tidak harus selesai saat request upload berakhir.
- **Engine:** SAST dan SCA. Jika salah satu engine gagal tetapi engine lain menghasilkan hasil yang bisa digunakan, audit dapat berstatus `completed` dengan `warnings` berisi keterangan kegagalan parsial.
- **Kuota MVP:** untuk pengguna tamu, maksimal 3 audit per hari. Angka ini adalah konfigurasi produk dan dapat diubah.

Dokumen ini mengasumsikan endpoint MVP dapat digunakan tanpa login dan memiliki kuota tamu. Jika autentikasi ditambahkan, backend wajib memeriksa bahwa pengguna berhak membaca audit yang diminta; UUID bukan pengganti pemeriksaan otorisasi.

## 2. Konvensi Umum

### 2.1 URL dan metode

Semua endpoint menggunakan prefix `/api/v1`.

| Metode | Endpoint | Fungsi |
|---|---|---|
| `POST` | `/api/v1/audits` | Mengunggah ZIP dan memasukkan audit ke antrean. |
| `GET` | `/api/v1/audits/{audit_id}` | Mengambil status dan hasil audit berdasarkan UUID. |

### 2.2 Penamaan dan waktu

- Nama field request dan response menggunakan `snake_case`.
- `audit_id` menggunakan UUID.
- Waktu menggunakan ISO 8601 dalam UTC dan diakhiri `Z`, misalnya `2026-10-08T03:15:30Z`.
- Tipe konten response adalah `application/json`.
- Endpoint upload menerima `multipart/form-data`, bukan JSON.
- Jangan mengembalikan model/database record Laravel secara mentah. Gunakan API Resource atau formatter response yang terkontrol.

### 2.3 Amplop response

Response sukses berisi `success: true`, `message`, dan `data`. Response error berisi `success: false`, `message`, `data: null`, dan `errors`. Field `meta` bersifat opsional dan digunakan untuk informasi tambahan seperti kuota atau hasil yang dipotong.

Contoh sukses:

```json
{
  "success": true,
  "message": "Status audit berhasil diambil.",
  "data": {},
  "meta": {}
}
```

Contoh error:

```json
{
  "success": false,
  "message": "Data yang dikirim tidak valid.",
  "data": null,
  "errors": {
    "file": ["Berkas harus berformat ZIP dan berukuran maksimal 50 MB."]
  }
}
```

`meta` boleh tidak disertakan jika tidak ada metadata tambahan. Untuk error validasi, `errors` adalah objek dengan nama field sebagai key dan daftar pesan sebagai value. Untuk error umum yang tidak terkait field tertentu, `errors` dapat bernilai `null`.

**Penting:** `success` menyatakan apakah request HTTP berhasil diproses, bukan apakah proses audit berhasil. Jadi, `GET` yang berhasil mengambil audit berstatus `failed` tetap mengembalikan `success: true`; kegagalan audit dijelaskan di `data.error`.

## 3. Endpoint: Membuat Audit

### `POST /api/v1/audits`

Mengunggah berkas ZIP dan memasukkan pekerjaan audit ke antrean. Endpoint ini tidak menunggu proses SAST/SCA selesai.

### 3.1 Request

**Content-Type:** `multipart/form-data`

| Field | Wajib | Tipe | Aturan |
|---|---|---|---|
| `source_type` | Ya | string | Tahap 1 hanya menerima `zip`. Nilai `git` mengembalikan `422 SOURCE_TYPE_NOT_SUPPORTED`. |
| `file` | Ya jika `source_type=zip` | file | Harus berformat `.zip`, ukuran maksimum 50 MB, dan arsip harus dapat dibaca. |
| `git_url` | Tidak digunakan di Tahap 1 | string | Dicadangkan untuk Tahap 2. Jangan kirim pada integrasi Tahap 1. |

Validasi ekstensi saja tidak cukup. Backend tetap harus memeriksa bahwa isi berkas benar-benar arsip ZIP yang dapat dibaca.

### 3.2 Response berhasil — HTTP `202 Accepted`

Dikembalikan setelah berkas lolos validasi awal dan audit berhasil dicatat serta dimasukkan ke antrean.

```json
{
  "success": true,
  "message": "Repositori diterima dan masuk antrean pemindaian.",
  "data": {
    "audit_id": "9f1c2a7e-5b3d-4e8a-9c61-2d7f0b8a4e13",
    "status": "queued",
    "source_type": "zip",
    "repo_name": "api-gateway",
    "created_at": "2026-10-08T03:15:30Z",
    "poll_url": "/api/v1/audits/9f1c2a7e-5b3d-4e8a-9c61-2d7f0b8a4e13",
    "poll_interval_seconds": 3
  },
  "meta": {
    "quota": {
      "limit": 3,
      "remaining": 2
    }
  }
}
```

| Field | Arti |
|---|---|
| `audit_id` | UUID untuk mengidentifikasi audit. Simpan untuk polling berikutnya. |
| `status` | Status awal selalu `queued`. |
| `repo_name` | Nama repositori yang ditampilkan di UI. Backend menentukan nama dari isi/struktur ZIP atau nama berkas jika nama repositori tidak dapat ditentukan. |
| `created_at` | Waktu audit dibuat dalam UTC. |
| `poll_url` | Path relatif yang digunakan untuk mengambil status audit. |
| `poll_interval_seconds` | Saran interval polling dari server. Frontend harus membaca nilai ini, bukan mengasumsikan nilainya selalu 3 detik. |
| `meta.quota.limit` | Batas audit yang berlaku pada periode kuota. |
| `meta.quota.remaining` | Sisa audit setelah audit baru diterima. |

Aturan kuota: kuota dikurangi saat audit diterima dan berhasil dibuat (`202`). Request yang gagal validasi tidak mengonsumsi kuota. Nilai kuota aktual harus berasal dari konfigurasi dan pencatatan backend.

### 3.3 Contoh request dengan cURL

Dijalankan dari terminal. Ganti `./api-gateway.zip` dengan path ZIP yang tersedia.

```bash
curl -X POST "http://localhost:8000/api/v1/audits" \
  -H "Accept: application/json" \
  -F "source_type=zip" \
  -F "file=@./api-gateway.zip"
```

## 4. Endpoint: Mengambil Status dan Hasil Audit

### `GET /api/v1/audits/{audit_id}`

Mengambil status terbaru dan, setelah tersedia, ringkasan serta daftar temuan audit.

### 4.1 Path parameter

| Parameter | Wajib | Tipe | Aturan |
|---|---|---|---|
| `audit_id` | Ya | UUID | Harus merupakan UUID audit yang dikenal backend dan boleh diakses peminta. |

### 4.2 Status audit

| Status | Arti | Hasil final tersedia? |
|---|---|---|
| `queued` | Audit menunggu diproses oleh antrean. | Belum |
| `processing` | Audit sedang dijalankan. | Belum tentu |
| `completed` | Audit telah berakhir dan hasil yang tersedia sudah bisa dibaca. Hasil mungkin parsial jika ada warning. | Ya |
| `failed` | Audit gagal total sehingga hasil audit yang dapat digunakan tidak tersedia. | Tidak |

### 4.3 Status engine

Field `engines` menunjukkan status tiap engine. Nilai yang diperbolehkan:

- `pending`: belum dimulai.
- `running`: sedang berjalan.
- `completed`: selesai dengan hasil yang dapat digunakan.
- `failed`: gagal dijalankan atau gagal menghasilkan hasil yang dapat digunakan.
- `skipped`: sengaja dilewati karena kondisi tertentu, misalnya tidak ada manifest dependensi untuk SCA.

### 4.4 Response ketika masih berjalan — HTTP `200 OK`

```json
{
  "success": true,
  "message": "Status audit berhasil diambil.",
  "data": {
    "audit_id": "9f1c2a7e-5b3d-4e8a-9c61-2d7f0b8a4e13",
    "status": "processing",
    "source_type": "zip",
    "repo_name": "api-gateway",
    "created_at": "2026-10-08T03:15:30Z",
    "completed_at": null,
    "engines": {
      "sast": "running",
      "sca": "completed"
    },
    "health_score": null,
    "summary": null,
    "findings": null,
    "warnings": [],
    "error": null
  }
}
```

Saat status masih `queued` atau `processing`, `completed_at`, `health_score`, `summary`, dan `findings` dapat bernilai `null` karena hasil final belum tersedia.

### 4.5 Response ketika selesai — HTTP `200 OK`

```json
{
  "success": true,
  "message": "Audit selesai diproses.",
  "data": {
    "audit_id": "9f1c2a7e-5b3d-4e8a-9c61-2d7f0b8a4e13",
    "status": "completed",
    "source_type": "zip",
    "repo_name": "api-gateway",
    "created_at": "2026-10-08T03:15:30Z",
    "completed_at": "2026-10-08T03:16:02Z",
    "engines": {
      "sast": "completed",
      "sca": "completed"
    },
    "health_score": 72,
    "summary": {
      "total_findings": 2,
      "by_severity": {
        "critical": 0,
        "high": 2,
        "medium": 0,
        "low": 0,
        "info": 0,
        "unknown": 0
      },
      "by_type": {
        "sast": 1,
        "sca": 1
      }
    },
    "findings": [
      {
        "id": 101,
        "finding_type": "sast",
        "severity": "high",
        "issue_type": "sql-injection",
        "description": "Input pengguna digabungkan langsung ke query SQL.",
        "file_path": "src/routes/users.js",
        "line_number": 42,
        "rule_id": "javascript.lang.security.audit.sqli.node-postgres-sqli.node-postgres-sqli",
        "package_name": null,
        "current_version": null,
        "cve_id": null,
        "fixed_version": null
      },
      {
        "id": 102,
        "finding_type": "sca",
        "severity": "high",
        "issue_type": "vulnerable_dependency",
        "description": "Command Injection in lodash.",
        "file_path": "package.json",
        "line_number": null,
        "rule_id": null,
        "package_name": "lodash",
        "current_version": "4.17.15",
        "cve_id": "CVE-2021-23337",
        "fixed_version": "4.17.21"
      }
    ],
    "warnings": [],
    "error": null
  },
  "meta": {
    "findings": {
      "limit": 1000,
      "returned": 2,
      "total": 2,
      "truncated": false
    }
  }
}
```

#### Arti field hasil

| Field | Tipe | Keterangan |
|---|---|---|
| `health_score` | integer atau `null` | Skor kesehatan kode 0–100 sesuai formula yang ditentukan proyek. `null` jika skor belum dapat dihitung. |
| `summary.total_findings` | integer | Jumlah keseluruhan temuan, bukan hanya jumlah yang dikirim dalam array `findings`. |
| `summary.by_severity` | object | Jumlah temuan menurut severity. Semua key severity tetap ada, meskipun nilainya `0`. |
| `summary.by_type` | object | Jumlah temuan menurut jenis engine. |
| `findings` | array atau `null` | `null` berarti hasil final belum tersedia. Array kosong `[]` berarti audit selesai dan tidak ada temuan. |
| `warnings` | array string | Peringatan nonfatal, misalnya salah satu engine gagal atau dilewati. |
| `error` | object atau `null` | Detail kegagalan total. `null` jika tidak ada kegagalan total. |
| `meta.findings.limit` | integer | Batas maksimal temuan yang dikembalikan dalam response MVP, yaitu 1000. |
| `meta.findings.returned` | integer | Jumlah item yang benar-benar ada dalam array `findings`. |
| `meta.findings.total` | integer | Jumlah seluruh temuan yang tersedia untuk audit tersebut. |
| `meta.findings.truncated` | boolean | `true` jika tidak semua temuan dikirim karena batas response. |

Daftar `findings` harus diurutkan berdasarkan severity dari tertinggi ke terendah: `critical`, `high`, `medium`, `low`, `info`, kemudian `unknown`. `summary` dihitung dari seluruh temuan yang tersimpan, bukan hanya item yang ditampilkan.

### 4.6 Response jika satu engine gagal tetapi engine lain berhasil

Jika satu engine gagal tetapi hasil engine lainnya masih dapat digunakan, status audit boleh tetap `completed`. Engine yang gagal ditandai `failed`, hasil yang tersedia tetap dikembalikan, dan alasan kegagalan dicantumkan dalam `warnings`.

Contoh:

```json
{
  "success": true,
  "message": "Audit selesai dengan peringatan.",
  "data": {
    "audit_id": "9f1c2a7e-5b3d-4e8a-9c61-2d7f0b8a4e13",
    "status": "completed",
    "source_type": "zip",
    "repo_name": "api-gateway",
    "created_at": "2026-10-08T03:15:30Z",
    "completed_at": "2026-10-08T03:16:02Z",
    "engines": {
      "sast": "completed",
      "sca": "failed"
    },
    "health_score": 72,
    "summary": {
      "total_findings": 1,
      "by_severity": {
        "critical": 0,
        "high": 1,
        "medium": 0,
        "low": 0,
        "info": 0,
        "unknown": 0
      },
      "by_type": {
        "sast": 1,
        "sca": 0
      }
    },
    "findings": [
      {
        "id": 101,
        "finding_type": "sast",
        "severity": "high",
        "issue_type": "sql-injection",
        "description": "Input pengguna digabungkan langsung ke query SQL.",
        "file_path": "src/routes/users.js",
        "line_number": 42,
        "rule_id": "javascript.lang.security.audit.sqli.node-postgres-sqli.node-postgres-sqli",
        "package_name": null,
        "current_version": null,
        "cve_id": null,
        "fixed_version": null
      }
    ],
    "warnings": [
      "Pemindaian SCA gagal: OSV.dev tidak dapat dijangkau."
    ],
    "error": null
  },
  "meta": {
    "findings": {
      "limit": 1000,
      "returned": 1,
      "total": 1,
      "truncated": false
    }
  }
}
```

> **Catatan contoh:** seluruh nilai pada contoh hanya ilustrasi. Pada implementasi, `summary` dan metadata `findings` harus dihitung dari hasil audit aktual yang tersimpan.

### 4.7 Response jika audit gagal total — HTTP `200 OK`

HTTP `200` digunakan karena request untuk mengambil status berhasil. Status proses audit dijelaskan melalui `data.status`.

```json
{
  "success": true,
  "message": "Status audit berhasil diambil.",
  "data": {
    "audit_id": "9f1c2a7e-5b3d-4e8a-9c61-2d7f0b8a4e13",
    "status": "failed",
    "source_type": "zip",
    "repo_name": "api-gateway",
    "created_at": "2026-10-08T03:15:30Z",
    "completed_at": "2026-10-08T03:15:45Z",
    "engines": {
      "sast": "failed",
      "sca": "failed"
    },
    "health_score": null,
    "summary": null,
    "findings": null,
    "warnings": [],
    "error": {
      "code": "SANDBOX_EXTRACTION_FAILED",
      "message": "Berkas ZIP tidak dapat diekstrak."
    }
  }
}
```

Gunakan `data.error` hanya untuk kegagalan total atau kondisi yang membuat hasil audit tidak dapat digunakan. Jika hanya satu engine gagal, gunakan `warnings` dan status tiap engine.

## 5. Struktur Temuan (`findings`)

Setiap item dalam `findings` memiliki bentuk yang sama agar frontend lebih mudah menampilkan tabel temuan.

| Field | Tipe | Nullable? | Keterangan |
|---|---|---|---|
| `id` | integer atau string | Tidak | Identitas temuan yang stabil dalam sistem. |
| `finding_type` | string | Tidak | Tahap 1 menggunakan `sast` atau `sca`. |
| `severity` | string | Tidak | Salah satu dari `critical`, `high`, `medium`, `low`, `info`, `unknown`. |
| `issue_type` | string | Tidak | Kategori masalah, misalnya `sql-injection` atau `vulnerable_dependency`. |
| `description` | string | Tidak | Penjelasan singkat yang dapat ditampilkan kepada pengguna. |
| `file_path` | string | Ya | Path relatif terhadap root repositori, bukan path absolut server. |
| `line_number` | integer | Ya | Nomor baris mulai dari 1 jika tersedia. `null` untuk temuan tingkat paket yang tidak memiliki nomor baris. |
| `rule_id` | string | Ya | ID aturan scanner untuk temuan SAST. Umumnya `null` untuk SCA. |
| `package_name` | string | Ya | Nama dependensi untuk temuan SCA. |
| `current_version` | string | Ya | Versi dependensi yang ditemukan. |
| `cve_id` | string | Ya | ID CVE jika tersedia; jika tidak, `null`. |
| `fixed_version` | string | Ya | Versi perbaikan yang diketahui; jika tidak diketahui, `null`. |

Field khusus SAST dan SCA boleh bernilai `null` jika tidak relevan. Frontend harus menangani `null` tanpa menampilkan teks seperti `null` kepada pengguna.

## 6. Response Error HTTP

### 6.1 Validasi gagal — HTTP `422 Unprocessable Content`

Contoh ketika file tidak dikirim, ekstensi salah, atau ukurannya melebihi 50 MB:

```json
{
  "success": false,
  "message": "Data yang dikirim tidak valid.",
  "data": null,
  "errors": {
    "file": [
      "Berkas harus berformat ZIP dan berukuran maksimal 50 MB."
    ]
  }
}
```

Contoh ketika Git belum didukung:

```json
{
  "success": false,
  "message": "Jenis sumber belum tersedia.",
  "data": null,
  "errors": {
    "source_type": [
      "Audit melalui Git belum tersedia pada Tahap 1. Gunakan source_type=zip."
    ]
  },
  "meta": {
    "error_code": "SOURCE_TYPE_NOT_SUPPORTED"
  }
}
```

### 6.2 Kuota habis — HTTP `429 Too Many Requests`

```json
{
  "success": false,
  "message": "Kuota pemindaian tamu hari ini sudah habis.",
  "data": null,
  "errors": null,
  "meta": {
    "error_code": "QUOTA_EXCEEDED",
    "quota": {
      "limit": 3,
      "remaining": 0
    },
    "retry_after_seconds": 3600
  }
}
```

`retry_after_seconds` harus dihitung backend berdasarkan kapan kuota benar-benar pulih. Nilai `3600` di atas hanya contoh, bukan nilai tetap yang wajib digunakan.

### 6.3 Audit tidak ditemukan — HTTP `404 Not Found`

```json
{
  "success": false,
  "message": "Audit tidak ditemukan atau sudah kedaluwarsa.",
  "data": null,
  "errors": null,
  "meta": {
    "error_code": "AUDIT_NOT_FOUND"
  }
}
```

Gunakan response yang sama jika kebijakan privasi mengharuskan backend tidak membocorkan apakah audit milik pihak lain ada atau tidak.

### 6.4 Request terlalu besar — HTTP `413 Content Too Large`

Dapat dikembalikan jika request melewati batas ukuran yang dikonfigurasi pada web server atau PHP sebelum validasi Laravel berjalan.

```json
{
  "success": false,
  "message": "Ukuran request melebihi batas yang diizinkan.",
  "data": null,
  "errors": {
    "file": ["Ukuran berkas maksimum adalah 50 MB."]
  },
  "meta": {
    "error_code": "PAYLOAD_TOO_LARGE"
  }
}
```

Konfigurasi PHP dan web server harus diselaraskan dengan batas 50 MB agar response error tetap konsisten.

### 6.5 Kesalahan server — HTTP `500 Internal Server Error`

```json
{
  "success": false,
  "message": "Terjadi kesalahan pada server.",
  "data": null,
  "errors": null,
  "meta": {
    "error_code": "INTERNAL_SERVER_ERROR"
  }
}
```

Jangan mengirim stack trace, kredensial, path absolut server, atau detail internal lain kepada frontend.

## 7. Daftar Kode Error

| Kode | HTTP / lokasi | Arti |
|---|---|---|
| `VALIDATION_ERROR` | `422` | Request tidak memenuhi aturan validasi. Bisa ditaruh di `meta.error_code` jika dibutuhkan oleh frontend. |
| `SOURCE_TYPE_NOT_SUPPORTED` | `422` | Sumber `git` belum didukung pada Tahap 1. |
| `QUOTA_EXCEEDED` | `429` | Batas audit telah tercapai. |
| `AUDIT_NOT_FOUND` | `404` | Audit tidak ditemukan, kedaluwarsa, atau tidak boleh diakses. |
| `PAYLOAD_TOO_LARGE` | `413` | Ukuran request melebihi batas. |
| `SANDBOX_EXTRACTION_FAILED` | `data.error.code` pada polling | Berkas ZIP tidak dapat diekstrak sehingga audit gagal total. |
| `AUDIT_PROCESSING_FAILED` | `data.error.code` pada polling | Audit gagal total karena kesalahan pemrosesan lainnya. |
| `INTERNAL_SERVER_ERROR` | `500` | Kesalahan server saat menangani request. |

Kode error bersifat stabil untuk logika frontend. Pesan error dapat diperjelas tanpa mengubah kode selama makna error tetap sama.

## 8. Aturan Pemetaan Severity

Mapping ini adalah aturan normalisasi di aplikasi, bukan klaim bahwa severity dari setiap scanner selalu setara secara universal.

| Sumber | Nilai sumber | Severity API |
|---|---|---|
| Semgrep | `ERROR` | `high` |
| Semgrep | `WARNING` | `medium` |
| Semgrep | `INFO` | `low` |
| OSV/GHSA | `CRITICAL` | `critical` |
| OSV/GHSA | `HIGH` | `high` |
| OSV/GHSA | `MODERATE` | `medium` |
| OSV/GHSA | `LOW` | `low` |
| Sumber apa pun | Tidak tersedia atau tidak dikenali | `unknown` |

Jika sumber menyediakan severity lain, normalisasikan menurut aturan yang ditetapkan proyek dan dokumentasikan pemetaannya. Jangan mengarang severity atau versi perbaikan ketika sumber data tidak menyediakannya.

## 9. Alur Integrasi Frontend

1. Pengguna memilih ZIP dan menekan tombol untuk memulai audit.
2. Frontend mengirim `POST /api/v1/audits` dengan `multipart/form-data`.
3. Jika menerima `202`, frontend menyimpan `data.audit_id` dan membaca `data.poll_url` serta `data.poll_interval_seconds`.
4. Frontend memanggil endpoint polling sesuai interval dari server. Hentikan polling ketika status menjadi `completed` atau `failed`.
5. Ketika status `completed`, frontend menampilkan ringkasan, skor, warning, dan temuan yang tersedia.
6. Ketika status `failed`, frontend menampilkan `data.error.message`.
7. Jika request polling mengembalikan error HTTP seperti `404` atau `500`, tampilkan error request yang sesuai; jangan menyamakan error HTTP tersebut dengan `data.status`.

Jangan menjalankan polling terus-menerus setelah status final. Tangani timeout jaringan dan kegagalan sementara secara terpisah dari status audit.

## 10. Contoh Request Polling dengan cURL

Ganti UUID dengan `audit_id` dari response `202`.

```bash
curl -X GET \
  "http://localhost:8000/api/v1/audits/9f1c2a7e-5b3d-4e8a-9c61-2d7f0b8a4e13" \
  -H "Accept: application/json"
```

## 11. Checklist Implementasi

### Backend Laravel

- [ ] Daftarkan route sesuai prefix `/api/v1`.
- [ ] Validasi `source_type`, berkas ZIP, dan ukuran maksimum 50 MB.
- [ ] Selaraskan batas upload pada PHP/web server dengan batas API.
- [ ] Buat record audit dan antrekan job sebelum mengembalikan `202`.
- [ ] Gunakan UUID sebagai `audit_id`.
- [ ] Bentuk response melalui API Resource atau formatter terpusat.
- [ ] Terapkan status audit dan status engine sesuai enum dalam dokumen ini.
- [ ] Hitung `summary` dari seluruh temuan, bukan hanya daftar temuan yang dikirim.
- [ ] Batasi response maksimal 1000 temuan dan isi metadata `truncated` dengan benar.
- [ ] Pastikan kegagalan satu engine dapat menjadi warning jika engine lain menghasilkan hasil yang dapat digunakan.
- [ ] Jangan membocorkan stack trace atau path internal server.
- [ ] Terapkan pembatasan kuota dan otorisasi akses audit sesuai mode autentikasi aplikasi.

### Frontend Next.js

- [ ] Kirim upload sebagai `multipart/form-data`.
- [ ] Baca `audit_id`, `poll_url`, dan `poll_interval_seconds` dari response server.
- [ ] Tangani `queued`, `processing`, `completed`, dan `failed`.
- [ ] Bedakan `findings: null` dengan `findings: []`.
- [ ] Tangani field opsional atau `null` tanpa membuat UI error.
- [ ] Tampilkan `warnings` untuk hasil parsial.
- [ ] Hentikan polling saat audit sudah final.
- [ ] Tangani response `413`, `422`, `429`, `404`, dan `500`.

## 12. Keputusan yang Perlu Dijaga Konsisten

1. Tahap 1 hanya menerima ZIP; Git belum aktif.
2. Upload sukses mengembalikan `202`, bukan menunggu hasil audit.
3. Polling yang berhasil mengembalikan `200`, termasuk jika status audit `failed`.
4. `success` menjelaskan keberhasilan request HTTP, sedangkan `data.status` menjelaskan keadaan audit.
5. `findings: null` berarti hasil belum tersedia; `findings: []` berarti hasil tersedia dan kosong.
6. Kegagalan parsial disampaikan melalui status engine dan `warnings`; kegagalan total disampaikan melalui `data.error`.
7. Waktu selalu UTC ISO 8601 dan nama field selalu `snake_case`.
8. Jika implementasi membutuhkan perubahan kontrak, perbarui dokumen ini dan sesuaikan backend serta frontend bersama-sama.

---

**Catatan implementasi:** Contoh ID, waktu, skor, kuota, nama repositori, hasil temuan, dan durasi retry di dokumen ini adalah data ilustrasi. Backend harus mengisi nilai berdasarkan kondisi nyata, bukan menyalin nilai contoh secara tetap.
