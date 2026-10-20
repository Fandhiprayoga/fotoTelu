# FotoTelu — foto privat dan pencarian selfie

## Fitur versi awal

- Superadmin/admin: album draf/terbit, folder sampai 20 tingkat, upload maksimum
  20 foto per pengiriman, pratinjau privat, pencarian nama, rename, pindah dalam
  album, hapus dan retry pemrosesan. Folder/album hanya bisa dihapus ketika kosong.
- User: daftar melalui Shield, pilih album terbit, upload/ambil selfie kamera,
  simpan/ganti/hapus selfie, lihat dan unduh kandidat foto yang cocok.
- Role manager dan seluruh role lama tetap ada. Role aktif menentukan akses fitur.
- JPEG, PNG, WebP maksimum 10 MB dan 40 megapiksel per foto, dibatasi pula oleh
  konfigurasi upload PHP. SVG/GIF/HEIC tidak didukung.
- Foto tidak berada di web root. File hanya dikirim setelah pemeriksaan akses;
  izin hasil pencarian berlaku 15 menit dan dicabut oleh perubahan album/selfie.

## Menjalankan lokal

Jalankan dari root aplikasi. PHP 8.2+ membutuhkan GD, fileinfo, EXIF (orientasi JPEG),
cURL, OpenSSL dan driver database. Python 3.12 sudah dipakai untuk validasi lokal.

1. Jalankan `php spark migrate --all` untuk migrasi Shield, Settings dan FotoTelu.
2. Jalankan `php spark photos:setup` sekali. Perintah membuat konfigurasi privat
   lokal dengan mode 0600 di writable, tanpa menampilkan token/kunci.
3. Buat virtual environment dengan `python3.12 -m venv face-service/.venv`.
4. Instal `face-service/.venv/bin/python -m pip install -r face-service/requirements-dev.txt`.
5. Unduh model dengan `face-service/.venv/bin/python face-service/download_models.py`.
   Model diverifikasi SHA256 dan tidak masuk Git.
6. Jalankan `face-service/.venv/bin/python face-service/run_local.py` di terminal
   tersendiri. Launcher membaca token privat, bind hanya 127.0.0.1:8090.
7. Jalankan `php spark photos:work` di terminal tersendiri. Worker memproses antrean
   upload dan penghapusan. `php spark photos:work --once` memproses satu pekerjaan.
8. Jalankan PHP seperti biasa, misalnya `php spark serve`. Buka menu **File Manager
   Foto** atau **Temukan Foto Saya**. Kamera memerlukan localhost atau HTTPS.
9. Jalankan `php spark photos:cleanup` secara periodik (misalnya cron setiap menit)
   untuk membersihkan grant kedaluwarsa dan mencoba ulang penghapusan selfie tertunda.

`upload_max_filesize`, `post_max_size`, `max_file_uploads`, dan memory_limit PHP
harus sesuai ukuran batch. Default bawaan PHP dapat menolak upload sebelum aplikasi
menerimanya. Upload batch menggunakan form multipart biasa dengan laporan tiap file;
progress real-time per file dan hapus batch belum termasuk versi ini.

## Pengaturan VPS dan rahasia

Lihat README layanan wajah untuk detail supervisi. Gunakan proses PHP, Python
dan worker dengan akun tanpa hak root, satu proses Python inference, HTTPS publik,
firewall yang menutup 8090 dari luar, persistent private storage dan backup terenkripsi.
Jangan membuat reverse proxy publik ke API Python.

Konfigurasi privat dapat diberikan lewat environment server:

| Variabel | Kegunaan |
| --- | --- |
| `FACE_SERVICE_TOKEN` | Token yang sama pada PHP dan Python; jangan masuk browser/log |
| `PHOTOS_FACE_URL` | URL internal, default http://127.0.0.1:8090 |
| `PHOTOS_MATCH_THRESHOLD` | Ambang cosine sementara 0.55, wajib dikalibrasi sebelum produksi |
| `encryption.key` | Kunci CI Encryption untuk selfie; didahulukan atas fallback lokal |
| `FACE_MODEL_DIR` | Direktori model terverifikasi milik layanan Python |
| `FACE_DB_PATH` | SQLite indeks privat milik layanan Python |

Launcher lokal memakai konfigurasi privat yang dibuat `photos:setup` jika token
environment tidak tersedia. Produksi sebaiknya menyuntik token melalui konfigurasi
supervisor privat. Backup konfigurasi kunci dengan akses terbatas. Jangan mengganti
kunci, beralih dari fallback lokal ke `encryption.key`, atau menghapus file konfigurasi
sebelum seluruh selfie dimigrasikan atau dihapus: kunci baru tidak otomatis mengenkripsi
ulang data lama. Setup tidak pernah merotasi kunci yang sudah ada.

Backup mencakup database PHP, file privat, kunci enkripsi dan SQLite menggunakan
SQLite online backup (jangan menyalin DB saja ketika WAL aktif). Tombstone indeks
tidak boleh dipangkas; restore harus merekonsiliasi penghapusan sebelum membuka layanan.
Storage/swap/backup harus dienkripsi pada tingkat OS. Gallery index SQLite bukan
database terenkripsi. Jurnal purge mempertahankan referensi berkas selfie sampai
unlink berhasil; pantau jumlah tertunda melalui `photos:cleanup`.

## Privasi dan batasan

Kamera mendukung pengambilan otomatis setelah tepat satu wajah berukuran cukup,
berada di tengah, dan stabil selama dua detik. MediaPipe Tasks Vision 0.10.32 dan
model BlazeFace di-host lokal; frame video diproses di browser, tidak dikirim ke
Google atau layanan wajah. Model dimuat saat kamera siap dan mode otomatis aktif.
Mode otomatis dapat dimatikan; tombol manual dan unggahan berkas tetap tersedia
jika pemuatan detektor gagal. Deteksi browser hanya bantuan pengambilan, bukan
jaminan ketajaman, identitas, atau kelulusan validasi server. Foto hanya dikirim
melalui formulir setelah persetujuan dan tindakan simpan pengguna.

Selfie memerlukan persetujuan dan disimpan terenkripsi sampai pengguna mengganti,
menghapus, atau akunnya dihapus. Pengelola galeri tidak memiliki endpoint untuk
membuka selfie. Penghapusan akun dan penggantian selfie mencabut grant segera;
kegagalan unlink dicatat untuk retry cleanup, bukan dilupakan.

Pengguna dapat memilih semua album terbit, tetapi tidak dapat menelusuri isinya
tanpa hasil kecocokan. Administrator wajib mempunyai dasar hukum/izin untuk foto
dan pemrosesan wajah orang yang ada di dalamnya. Tidak ada klaim bahwa upload selfie
membuktikan kepemilikan wajah: belum ada liveness/anti-spoofing. Ini **penemuan foto,
bukan verifikasi identitas atau login biometrik**. Skor bukan probabilitas identitas.
Salah cocok dapat membuka foto kepada orang yang salah; kalibrasi dan penilaian
risiko dengan dataset yang mendapat izin wajib sebelum pemakaian nyata.

Pencarian saat ini exact cosine CPU, maksimum 20.000 foto siap cari per album,
maksimum 200 kandidat. Wajah kecil, buram, tertutup atau sudut ekstrem dapat terlewat.
Belum ada kalibrasi akurasi atau benchmark kapasitas VPS dengan foto acara nyata.
Pergantian model harus disertai reindex dan pembaruan selfie; versi campuran ditolak.
Pemindahan lintas album, ZIP/batch-download, album undangan, per-file upload progress,
dan batch-delete ditunda, tidak diklaim sudah tersedia.

## Pengujian

- `node --test tests/js/selfie-auto.test.mjs`: stabilitas wajah otomatis, reset
  saat wajah hilang/bergerak/lebih dari satu, frame terputus, dan posisi tidak valid.
- `vendor/bin/phpunit --no-coverage`: tes PHP domain, storage dan HTTP dengan SQLite
  in-memory; tidak mengubah database aplikasi.
- Dari direktori face-service, `.venv/bin/python -m pytest`: tes model-free layanan
  dengan engine stub, tanpa data biometrik pribadi.
- Browser lokal sudah memverifikasi album/folder, upload aset tanpa wajah,
  pemrosesan model nyata menjadi `no_face`, pindah folder, pratinjau dan penghapusan
  berkas/indeks. Data uji tersebut dibersihkan kembali.
- Tes kecocokan selfie nyata masih memerlukan foto milik pengguna yang mendapat
  persetujuan. Tes otomatis bukan bukti akurasi pengenal wajah. Tes konkurensi
  multi-koneksi MySQL dan benchmark 1k/10k foto perlu dilakukan sebelum produksi.

YuNet/SFace menggunakan lisensi masing-masing MIT/Apache-2.0. Lisensi model,
hash dan sumber tercatat di face-service/THIRD_PARTY_NOTICES.md.