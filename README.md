# GadgetHub + MySQL/XAMPP

Website ini menggunakan PHP sebagai API dan MySQL/MariaDB untuk akun, katalog produk, stok, serta pesanan. Jalankan melalui Apache XAMPP; membuka `index.html` langsung dengan `file://` tidak akan menghubungkan database.

## Menjalankan di XAMPP

1. Salin seluruh folder website ke `C:\xampp\htdocs\gadgethub` (folder harus berisi `index.html`, `api`, dan `database.sql`).
2. Buka XAMPP Control Panel, lalu jalankan **Apache** dan **MySQL**.
3. Buka `http://localhost/phpmyadmin`, pilih tab **Import**, lalu impor file `database.sql`. File ini membuat database `gadgethub` dan mengisi 8 produk contoh.
4. Jika kredensial MySQL XAMPP berbeda, ubah `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, dan `DB_PASS` di `api/config.php`.
5. Di `C:\xampp\php\php.ini`, aktifkan ZIP dengan mengubah `;extension=zip` menjadi `extension=zip`, lalu jalankan `composer install` dari folder proyek.
6. Pastikan ekstensi PHP **PDO MySQL** dan **OpenSSL** aktif di XAMPP.
7. Buka `http://localhost/gadgethub/`.

## Konfigurasi Gmail untuk OTP

```php
const SMTP_HOST = 'smtp.gmail.com';
const SMTP_PORT = 587;
const SMTP_ENCRYPTION = 'tls';
const SMTP_FROM_NAME = 'GadgetHub';

const OTP_EXPIRY_MINUTES = 10;
const OTP_MAX_ATTEMPTS = 5;
const OTP_RESEND_INTERVAL_SECONDS = 60;
const OTP_LENGTH = 6;
```

Jalankan `setup-smtp.ps1` dari PowerShell untuk menyimpan alamat Gmail dan Google App Password sebagai environment variable Windows untuk akun saat ini. Skrip meminta App Password dengan input tersembunyi dan tidak menaruhnya di source code. Setelah selesai, tutup seluruh XAMPP Control Panel, buka kembali, lalu jalankan Apache.

Jika ingin mengatur environment variable secara manual, contohnya pada konfigurasi Apache lokal:

```apache
SetEnv GADGETHUB_SMTP_USERNAME alamat-pengirim@gmail.com
SetEnv GADGETHUB_SMTP_PASSWORD app-password-google
```

Jangan gunakan kata sandi login Gmail biasa; aktifkan Verifikasi 2 Langkah dan buat Google App Password khusus. Restart Apache setelah mengubah environment variable. Jangan simpan App Password di source code atau bagikan ke orang lain.

Saat daftar, akun baru disimpan setelah OTP email berhasil diverifikasi. Saat login, kata sandi yang benar akan diikuti OTP email sebelum sesi dibuat. Kode OTP berlaku sesuai `OTP_EXPIRY_MINUTES`, memiliki panjang `OTP_LENGTH` digit, maksimal dicoba sebanyak `OTP_MAX_ATTEMPTS`, dan pengiriman ulang dibatasi sesuai `OTP_RESEND_INTERVAL_SECONDS`.

Untuk database GadgetHub yang sudah terpasang, buat tabel OTP login satu kali melalui phpMyAdmin → database `gadgethub` → tab **SQL**:

```sql
CREATE TABLE IF NOT EXISTS login_otp_challenges (
  email VARCHAR(190) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  otp_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_sent_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (email),
  UNIQUE KEY uq_login_otp_challenges_user (user_id),
  KEY idx_login_otp_challenges_expiry (expires_at),
  CONSTRAINT fk_login_otp_challenges_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;
```

## Lupa dan ganti kata sandi

Di halaman **Masuk**, pilih **Lupa kata sandi?** lalu masukkan email akun. Email hanya berisi kode konfirmasi kepemilikan akun—bukan tautan atau formulir untuk mengganti kata sandi. Masukkan kode dan kata sandi baru langsung di website. Kode berlaku 30 menit, hanya dapat digunakan sekali, dibatasi maksimal 5 percobaan, dan permintaan kode ulang dibatasi satu kali per 60 detik. Pesan konfirmasi tidak mengungkap apakah sebuah email terdaftar. Pengguna yang sudah masuk dapat memilih **Akun · nama** di header untuk mengganti kata sandi dengan terlebih dahulu memasukkan kata sandi saat ini; tombol keluar akun juga tersedia di panel tersebut.

Pengiriman kode menggunakan konfigurasi Gmail SMTP yang sama seperti OTP login dan pendaftaran.

Untuk database GadgetHub lama yang sudah memiliki tabel `password_reset_tokens` dari versi tautan reset sebelumnya, jalankan migrasi berikut satu kali melalui phpMyAdmin → database `gadgethub` → tab **SQL**:

```sql
ALTER TABLE password_reset_tokens
  MODIFY token_hash VARCHAR(255) NOT NULL,
  ADD COLUMN attempts TINYINT UNSIGNED NOT NULL DEFAULT 0;
```

Database baru mendapatkan skema terbaru saat mengimpor `database.sql`. Kode konfirmasi disimpan sebagai hash kata sandi dan dihapus setelah berhasil digunakan atau ketika kata sandi akun diganti.

## Batas percobaan login

Setelah 3 kali salah memasukkan email atau kata sandi, login untuk email tersebut disuspend selama 60 detik. Jika setelah suspend masih salah 3 kali lagi, suspend berikutnya berlangsung 120 detik; suspend berikutnya tetap 120 detik sampai login berhasil. Hitung mundur ditampilkan pada formulir login. Login yang berhasil menghapus hitungan dan tingkat suspend. Konfigurasi menggunakan `LOGIN_MAX_FAILED_ATTEMPTS`, `LOGIN_LOCKOUT_SECONDS`, dan `LOGIN_SECOND_LOCKOUT_SECONDS` di `api/config.php`.

Jika tabel `login_attempts` sudah ada, jalankan migrasi berikut satu kali melalui tab **SQL** phpMyAdmin:

```sql
ALTER TABLE login_attempts
  ADD COLUMN lockout_level TINYINT UNSIGNED NOT NULL DEFAULT 0;
```

Untuk instalasi baru, skema tabelnya:

```sql
CREATE TABLE IF NOT EXISTS login_attempts (
  email VARCHAR(190) NOT NULL,
  failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  lock_until DATETIME DEFAULT NULL,
  lockout_level TINYINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (email)
) ENGINE=InnoDB;
```

## Membuat akun user dan admin

- Daftar melalui tombol **Masuk** → **Daftar** untuk membuat akun user. Semua pendaftaran publik selalu mendapat peran `user`.
- Untuk demo lokal, impor `database.sql` menambahkan akun super admin: email `superadmin@ghub.local`, kata sandi `superadmin123`. Masuk lewat tombol **Masuk**; dashboard pusat kontrol akan tampil setelah login. Kata sandi ini sederhana dan hanya untuk demo lokal—ganti sebelum deployment.
- Untuk memberi akses admin, buat akun terlebih dahulu melalui formulir pendaftaran. Di phpMyAdmin, buka database `gadgethub` → tab **SQL**, lalu jalankan perintah berikut dengan email akun yang dipilih:

  ```sql
  UPDATE users SET role = 'admin' WHERE email = 'admin@email.com';
  ```

- Keluar lalu masuk kembali dengan akun tersebut. Tautan **Dashboard admin** akan muncul setelah login.
- URL user menggunakan alamat utama `http://localhost/gadgethub/`, dashboard admin `http://localhost/gadgethub/admin/`, dan pusat kontrol super admin `http://localhost/gadgethub/super_admin/`. Halaman `/admin/` dan `/super_admin/` memiliki formulir login sendiri, mengirim OTP setelah kata sandi benar, serta memeriksa peran akun pada tahap kata sandi dan verifikasi OTP. Dashboard dan API tetap memvalidasi hak akses di server. URL dashboard lama tetap berfungsi untuk kompatibilitas.
- Jangan membuka pendaftaran admin publik atau membagikan akses phpMyAdmin kepada pengguna biasa. API memeriksa peran dari sesi server untuk setiap operasi admin.
- Jika database GadgetHub sudah dibuat sebelum fitur peran tersedia, jalankan migrasi ini satu kali sebelum menggunakan fitur login:

  ```sql
  ALTER TABLE users ADD COLUMN role ENUM('user', 'admin', 'super_admin') NOT NULL DEFAULT 'user';
  ```

- Untuk database lama, buat tabel pendaftaran OTP tertunda melalui tab **SQL**:

  ```sql
  CREATE TABLE IF NOT EXISTS pending_registrations (
    email VARCHAR(190) NOT NULL PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_sent_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pending_registrations_expiry (expires_at)
  ) ENGINE=InnoDB;
  ```

- Untuk database yang sudah dibuat sebelum manajemen pengguna, tambahkan status akun melalui tab **SQL**:

  ```sql
  ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1;
  ```

- Untuk menambahkan akun demo pada database yang sudah terpasang, jalankan juga SQL berikut setelah migrasi kolom `role`:

  ```sql
  ALTER TABLE users MODIFY role ENUM('user', 'admin', 'super_admin') NOT NULL DEFAULT 'user';
  UPDATE users
  SET email = 'superadmin@ghub.local',
      password_hash = '$2y$10$iDjt5yCgNzCoC/Q0iJsKBeHJi1EepnlHASmbOcgVGXMwBdneYr7V.',
      role = 'super_admin'
  WHERE email IN ('demo.admin@gadgethub.local', 'superadmin@ghub.local');
  ```

Dashboard admin tersedia pada halaman pusat kontrol terpisah yang dibuka di tab baru dari tautan **Dashboard admin** di navigasi beranda. Dashboard menampilkan ringkasan, pesanan, dan kontrol stok. Super admin mendapatkan tampilan pusat kontrol khusus dengan ringkasan akun, administrator, akun nonaktif, produk aktif, serta pintasan langsung ke pesanan, transaksi, katalog, dan hak akses. Manajemen pesanan menampilkan rincian produk/alamat, pencarian, filter status, dan pembaruan status. Di tabel transaksi, super admin dapat mengubah status pembayaran secara manual menjadi menunggu, dibayar, gagal, atau dikembalikan; waktu dan akun yang terakhir mengubah status juga dicatat. Ringkasan pembayaran dan pendapatan dihitung dari status pembayaran, terpisah dari status pemrosesan pesanan. Aplikasi belum terhubung ke payment gateway, sehingga perubahan ini hanya pencatatan manual dan bukan konfirmasi otomatis dari bank atau penyedia pembayaran. Super admin dapat mengubah role user/admin dan mengaktifkan/nonaktifkan akun (kecuali akun super admin), serta menambah produk, mengubah detail/harga/gambar/stok, dan mengaktifkan/nonaktifkan produk. Katalog tidak menampilkan label promo atau harga diskon. Endpoint perubahan hak akses, status pembayaran, dan katalog memverifikasi role super admin di server.
Dashboard super admin juga menampilkan laporan 200 aktivitas transaksi terbaru, termasuk pesanan baru dan perubahan status pembayaran, dengan waktu, pelanggan, nilai transaksi, serta admin yang mencatat perubahan. Riwayat aktivitas mulai dicatat setelah tabel `transaction_activity` tersedia; aktivitas transaksi lama tidak direkonstruksi.

## Modul operasional super admin

Dashboard super admin menyediakan pengelolaan kupon, flash sale, draft notifikasi/email; laporan penjualan bulanan, produk terlaris, pelanggan teratas dan stok yang dapat diekspor sebagai CSV (dapat dibuka di Excel); serta antrean moderasi ulasan, komplain, dan laporan penyalahgunaan. Panel pembayaran/pengiriman, zona ongkir, banner promosi, halaman statis, dan artikel sudah dihapus dari dashboard.

Untuk database lama, jalankan pernyataan `CREATE TABLE IF NOT EXISTS` untuk tabel `coupons`, `flash_sales`, `notification_campaigns`, `product_reviews`, `customer_complaints`, dan `abuse_reports` dari `database.sql` melalui phpMyAdmin setelah memastikan skema dasar `users`, `products`, `orders`, dan `order_items` sudah tersedia. Tabel-tabel pembayaran/pengiriman, banner, halaman, dan artikel yang sudah ada tidak dihapus, tetapi kini tidak ditampilkan atau dikelola dari dashboard.

Kupon/flash sale belum mengubah harga checkout/katalog secara otomatis, dan notifikasi/email massal hanya menyimpan draft/jadwal (tidak mengirim). Form ulasan, komplain, dan penyalahgunaan pada dashboard digunakan untuk memasukkan laporan yang diterima; formulir publik belum tersedia. Laporan transaksi mengambil pesanan berstatus pembayaran `paid`; ekspor CSV mengikuti data ringkasan yang ditampilkan (200 stok, 10 teratas untuk produk dan pelanggan).

Saat checkout, pengguna masuk membuka form untuk mengisi nama penerima, email kontak, nomor telepon/WhatsApp, alamat lengkap, dan memilih metode pembayaran COD atau transfer bank; tidak ada prompt browser. Pilihan metode pembayaran tersimpan pada pesanan dan terlihat di dashboard admin/super admin serta halaman konfirmasi. COD dibayar saat barang diterima. Untuk transfer bank, pengguna perlu menghubungi admin untuk mendapatkan informasi rekening; aplikasi belum memproses pembayaran otomatis. Untuk database lama, jalankan migrasi satu kali melalui phpMyAdmin → database `gadgethub` → tab **SQL**:

```sql
ALTER TABLE orders
  ADD COLUMN recipient_name VARCHAR(120) NULL AFTER user_id,
  ADD COLUMN recipient_email VARCHAR(190) NULL AFTER recipient_name,
  ADD COLUMN recipient_phone VARCHAR(30) NULL AFTER recipient_email;
```

Kolom dibuat nullable saat migrasi agar pesanan lama tetap kompatibel. Pesanan baru menyimpan semua informasi pemesan.

Tambahkan kolom metode pembayaran pada database lama dengan migrasi satu kali berikut. Nilai `NULL` pada pesanan lama berarti metode pembayarannya belum tercatat; pesanan baru wajib memilih COD atau transfer bank:

```sql
ALTER TABLE orders
  ADD COLUMN payment_method ENUM('cod', 'bank_transfer') DEFAULT NULL AFTER status;
```

Untuk menyimpan laporan aktivitas transaksi pada database lama, jalankan migrasi berikut satu kali setelah tabel `orders` tersedia:

```sql
CREATE TABLE IF NOT EXISTS transaction_activity (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED DEFAULT NULL,
  activity_type ENUM('order_created', 'payment_status_changed') NOT NULL,
  previous_status VARCHAR(30) DEFAULT NULL,
  new_status VARCHAR(30) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_transaction_activity_created (created_at),
  KEY idx_transaction_activity_order (order_id, created_at),
  CONSTRAINT fk_transaction_activity_order FOREIGN KEY (order_id) REFERENCES orders (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_transaction_activity_actor FOREIGN KEY (actor_user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;
```

Tabel mencatat pesanan baru dan setiap perubahan status pembayaran yang dilakukan oleh super admin, termasuk nilai status sebelumnya dan sesudahnya. Perubahan yang tidak mengubah status tidak dicatat. Aktivitas lama tidak direkonstruksi; laporan mulai terisi setelah migrasi.

Untuk menambahkan status pembayaran terpisah pada database lama, jalankan migrasi satu kali melalui phpMyAdmin → database `gadgethub` → tab **SQL**. Migrasi ini juga mencatat admin super terakhir yang memperbarui status. Pesanan yang sebelumnya berstatus `paid`, `processing`, `shipped`, atau `completed` akan dianggap sudah dibayar; status lain tetap menunggu pembayaran:

```sql
ALTER TABLE orders
  ADD COLUMN payment_status ENUM('pending', 'paid', 'failed', 'refunded') NOT NULL DEFAULT 'pending' AFTER status,
  ADD COLUMN payment_status_updated_at DATETIME DEFAULT NULL AFTER payment_status,
  ADD COLUMN payment_status_updated_by BIGINT UNSIGNED DEFAULT NULL AFTER payment_status_updated_at,
  ADD KEY idx_orders_payment_status (payment_status, created_at),
  ADD CONSTRAINT fk_orders_payment_updated_by
    FOREIGN KEY (payment_status_updated_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL;

UPDATE orders
SET payment_status = 'paid'
WHERE status IN ('paid', 'processing', 'shipped', 'completed');
```

Jalankan migrasi sebelum memakai versi baru aplikasi; jangan jalankannya lagi jika kolom `payment_status` sudah tersedia.

## Yang tersimpan

- Akun pengguna tersimpan di tabel `users`; kata sandi di-hash oleh PHP `password_hash`.
- Peran `user`/`admin`/`super_admin` disimpan di database. Formulir pendaftaran tidak menerima pilihan peran.
- Katalog dan stok tersimpan di tabel `products`.
- Checkout tersimpan di tabel `orders` dan `order_items`. Harga dihitung ulang dari database, stok dikunci dan dikurangi dalam transaksi MySQL.
- Sesi login memakai cookie PHP dan token CSRF; frontend dan API harus diakses dari origin yang sama melalui Apache.

Kredensial demo super admin bersifat publik dan hanya untuk penggunaan lokal/testing. Ganti kata sandi atau hapus akun demo sebelum situs dipublikasikan. Kata sandi tidak disimpan dalam teks biasa; database hanya menyimpan hash yang dibuat dengan `password_hash`, dan proses login memeriksanya dengan `password_verify`. Konfigurasi bawaan `root` tanpa kata sandi hanya sesuai instalasi XAMPP lokal standar. Atur kredensial MySQL yang aman sebelum menaruh situs di server publik. Checkout mencatat pesanan berstatus `pending`; belum memproses pembayaran.
