# Fitur FotoTelu

Panduan file manager foto, mesin wajah lokal, worker, privasi, dan deployment
tersedia di [PHOTOS.md](PHOTOS.md). Layanan Python dan worker harus berjalan agar
foto yang baru diunggah dapat dicari.

## Catatan deployment dan worker FotoTelu

Detail keamanan, konfigurasi layanan wajah, backup, dan batasan produksi ada di
[PHOTOS.md](PHOTOS.md) serta [README layanan wajah](face-service/README.md).
Ringkasan penerapan berikut melengkapi panduan tersebut:

1. Deploy aplikasi CodeIgniter dengan document root mengarah ke direktori `public/`;
    jangan sajikan root proyek, `writable/`, model, atau database SQLite melalui web.
    Pasang dependency PHP produksi (`composer install --no-dev --optimize-autoloader`),
    aktifkan ekstensi PHP yang dibutuhkan, dan siapkan database serta direktori privat
    persisten dengan hak akses akun aplikasi.
2. Atur konfigurasi `production` dan kredensial database secara privat. Terapkan
    migrasi dengan `php spark migrate --all`. Untuk deployment pertama, siapkan
    rahasia menggunakan `php spark photos:setup` **atau** injeksikan `FACE_SERVICE_TOKEN`
    yang sama secara aman ke PHP dan layanan Python, serta atur `encryption.key`.
    Jangan menjalankan setup untuk merotasi kunci yang sudah digunakan; perubahan kunci
    tidak mengenkripsi ulang selfie lama.
3. Siapkan layanan Python mengikuti bagian VPS di README layanan wajah: instal hanya
    `face-service/requirements.txt`, unduh model terverifikasi, jalankan Uvicorn di
    `127.0.0.1:8090` dengan satu worker di bawah supervisor. Jangan buka port 8090 ke
    Internet. Uji health terautentikasi sebelum mengaktifkan pencarian.
4. Jalankan antrean PHP sebagai **proses CLI tersendiri** di bawah supervisor, bukan
    dari request web. Contoh unit systemd (ganti path, user, group, dan PHP binary
    sesuai server):

    ```ini
    [Unit]
    Description=FotoTelu photo queue worker
    After=network.target mariadb.service

    [Service]
    Type=simple
    User=fototelu
    Group=www-data
    WorkingDirectory=/srv/fototelu
    Environment=CI_ENVIRONMENT=production
    ExecStart=/usr/bin/php /srv/fototelu/spark photos:work
    Restart=on-failure
    RestartSec=3
    UMask=0077
    NoNewPrivileges=true

    [Install]
    WantedBy=multi-user.target
    ```

    Worker terus memeriksa antrean dan menangani upload serta penghapusan; SIGTERM
    atau Ctrl+C menghentikannya setelah pekerjaan aktif selesai. Pastikan worker dan
    web memakai versi kode, konfigurasi, database, serta storage foto yang sama.
    Gunakan satu worker Python untuk inferensi; jumlah worker PHP dapat ditambah hanya
    setelah kapasitas dan perilaku konkurensi database/storage diuji.
5. Jadwalkan `php spark photos:cleanup` secara berkala (misalnya setiap menit),
    pantau log dan pekerjaan/penghapusan tertunda, lalu siapkan backup terenkripsi
    database, storage, kunci, dan indeks SQLite sesuai prosedur di [PHOTOS.md](PHOTOS.md).

Untuk menjalankan satu pekerjaan secara manual saat diagnosis, gunakan
`php spark photos:work --once`; jangan jadwalkan mode ini sebagai pengganti worker
persisten tanpa mekanisme polling terjadwal.

# CodeIgniter 4 + Shield RBAC Boilerplate

Boilerplate project CodeIgniter 4 dengan **CodeIgniter Shield** untuk autentikasi dan **Role-Based Access Control (RBAC)**, menggunakan template dashboard **Stisla**.

## Fitur

- ✅ Autentikasi (Login, Register, Logout) menggunakan CodeIgniter Shield
- ✅ Role-Based Access Control (RBAC) dengan 4 role default
- ✅ Template Dashboard Stisla yang sudah di-slice
- ✅ Manajemen User (CRUD)
- ✅ Manajemen Role & Permission Matrix
- ✅ Profil User
- ✅ Pengaturan Sistem
- ✅ Filter berdasarkan Role dan Permission
- ✅ Dynamic Sidebar berdasarkan permission user

## Roles Default

| Role | Deskripsi |
|------|-----------|
| **Super Admin** | Kontrol penuh terhadap seluruh sistem |
| **Admin** | Administrator harian sistem |
| **Manager** | Melihat laporan dan mengelola data |
| **User** | Pengguna umum dengan akses terbatas |

## Instalasi

### 1. Clone / Copy Project

```bash
cd ci4-app
```

### 2. Install Dependencies

```bash
composer install
```

### 3. Konfigurasi Environment

Copy file `.env` dan sesuaikan konfigurasi database:

```env
database.default.hostname = localhost
database.default.database = ci4_shield_rbac
database.default.username = root
database.default.password =
database.default.DBDriver = MySQLi
database.default.port = 3306
```

### 4. Buat Database

Buat database MySQL dengan nama `ci4_shield_rbac` (atau sesuai konfigurasi).

### 5. Jalankan Migration

```bash
php spark migrate --all
```

### 6. Jalankan Seeder

```bash
php spark db:seed UserSeeder
```

### 7. Jalankan Server

```bash
php spark serve
```

Akses di browser: `http://localhost:8080`

## Akun Default

| Role | Email | Password |
|------|-------|----------|
| Super Admin | superadmin@example.com | password123 |
| Admin | admin@example.com | password123 |
| Manager | manager@example.com | password123 |
| User | user@example.com | password123 |

## Struktur Folder

```
app/
├── Config/
│   ├── Auth.php              # Konfigurasi Shield
│   ├── AuthGroups.php        # Definisi Role, Permission, Matrix
│   ├── Filters.php           # Filter aliases
│   └── Routes.php            # Routing aplikasi
├── Controllers/
│   ├── BaseController.php    # Base controller dengan renderView()
│   ├── AuthController.php    # Override login/register Shield
│   ├── DashboardController.php
│   ├── UserController.php    # CRUD User
│   ├── RoleController.php    # View Roles & Permissions
│   ├── ProfileController.php
│   └── SettingController.php
├── Database/
│   └── Seeds/
│       └── UserSeeder.php    # Seeder user default
├── Filters/
│   ├── RoleFilter.php        # Filter berdasarkan role
│   └── PermissionFilter.php  # Filter berdasarkan permission
└── Views/
    ├── layouts/
    │   ├── app.php           # Layout utama dashboard
    │   └── auth.php          # Layout halaman auth
    ├── partials/
    │   ├── navbar.php        # Navbar dengan user dropdown
    │   ├── sidebar.php       # Sidebar dinamis per permission
    │   └── footer.php        # Footer
    ├── auth/
    │   ├── login.php
    │   └── register.php
    ├── dashboard/
    │   └── index.php
    ├── users/
    │   ├── index.php
    │   ├── create.php
    │   └── edit.php
    ├── roles/
    │   ├── index.php
    │   └── permissions.php
    ├── profile/
    │   └── index.php
    └── settings/
        └── index.php
public/
└── assets/                   # Asset template Stisla
    ├── css/
    ├── js/
    ├── img/
    └── fonts/
```

## Penggunaan RBAC

### Melindungi Route dengan Role

```php
// Hanya superadmin dan admin yang bisa akses
$routes->get('admin/panel', 'Admin::index', ['filter' => 'role:superadmin,admin']);
```

### Melindungi Route dengan Permission

```php
// Hanya yang punya permission users.create
$routes->get('users/create', 'User::create', ['filter' => 'permission:users.create']);
```

### Cek Permission di Controller

```php
$user = auth()->user();

if ($user->can('users.edit')) {
    // boleh edit
}

if ($user->inGroup('superadmin')) {
    // adalah superadmin
}
```

### Cek Permission di View

```php
<?php if (auth()->user()->can('users.create')): ?>
    <a href="/admin/users/create" class="btn btn-primary">Tambah User</a>
<?php endif; ?>
```

### Menambah Role Baru

Edit file `app/Config/AuthGroups.php`:

```php
public array $groups = [
    // ... role existing ...
    'editor' => [
        'title'       => 'Editor',
        'description' => 'Can manage content.',
    ],
];

public array $matrix = [
    // ... matrix existing ...
    'editor' => [
        'content.create',
        'content.edit',
        'content.delete',
    ],
];
```

### Menambah Permission Baru

```php
public array $permissions = [
    // ... permissions existing ...
    'content.create' => 'Dapat membuat konten',
    'content.edit'   => 'Dapat mengedit konten',
    'content.delete' => 'Dapat menghapus konten',
];
```

## Panduan Membuat Menu/Modul Baru

Berikut langkah-langkah lengkap untuk menambah menu/modul baru dengan RBAC. Contoh: membuat modul **Artikel** (`articles`).

### Langkah 1 — Daftarkan Permission di `app/Config/AuthGroups.php`

Tambahkan permission baru di `$permissions`:

```php
public array $permissions = [
    // ... existing ...
    'articles.list'   => 'Dapat melihat daftar artikel',
    'articles.create' => 'Dapat membuat artikel baru',
    'articles.edit'   => 'Dapat mengedit artikel',
    'articles.delete' => 'Dapat menghapus artikel',
];
```

Lalu assign permission ke role yang sesuai di `$matrix`:

```php
public array $matrix = [
    'superadmin' => [
        'admin.*', 'users.*', 'roles.*', 'dashboard.*', 'reports.*',
        'articles.*',   // <-- tambahkan
    ],
    'admin' => [
        'admin.access', 'users.list', 'users.create', 'users.edit', 'users.delete',
        'dashboard.*', 'reports.*',
        'articles.*',   // <-- tambahkan
    ],
    'manager' => [
        'admin.access', 'users.list', 'dashboard.*', 'reports.*',
        'articles.list', // <-- hanya bisa lihat
    ],
    'user' => [
        'dashboard.access',
    ],
];
```

### Langkah 2 — Buat Migration

```bash
php spark make:migration CreateArticlesTable
```

Edit file migration yang dihasilkan di `app/Database/Migrations/`:

```php
public function up()
{
    $this->forge->addField([
        'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
        'title'      => ['type' => 'VARCHAR', 'constraint' => 255],
        'slug'       => ['type' => 'VARCHAR', 'constraint' => 255],
        'content'    => ['type' => 'TEXT', 'null' => true],
        'author_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
        'status'     => ['type' => 'ENUM', 'constraint' => ['draft', 'published'], 'default' => 'draft'],
        'created_at' => ['type' => 'DATETIME', 'null' => true],
        'updated_at' => ['type' => 'DATETIME', 'null' => true],
    ]);
    $this->forge->addKey('id', true);
    $this->forge->createTable('articles');
}
```

Lalu jalankan:

```bash
php spark migrate
```

### Langkah 3 — Buat Model

Buat file `app/Models/ArticleModel.php`:

```php
<?php

namespace App\Models;

use CodeIgniter\Model;

class ArticleModel extends Model
{
    protected $table         = 'articles';
    protected $primaryKey    = 'id';
    protected $allowedFields = ['title', 'slug', 'content', 'author_id', 'status'];
    protected $useTimestamps = true;
}
```

### Langkah 4 — Buat Controller

Buat file `app/Controllers/ArticleController.php`:

```php
<?php

namespace App\Controllers;

use App\Models\ArticleModel;

class ArticleController extends BaseController
{
    protected ArticleModel $articleModel;

    public function __construct()
    {
        $this->articleModel = new ArticleModel();
    }

    public function index()
    {
        $data = [
            'title'      => 'Daftar Artikel',
            'page_title' => 'Daftar Artikel',
            'articles'   => $this->articleModel->findAll(),
        ];

        return $this->renderView('articles/index', $data);
    }

    public function create()
    {
        $data = [
            'title'      => 'Tambah Artikel',
            'page_title' => 'Tambah Artikel',
        ];

        return $this->renderView('articles/create', $data);
    }

    public function store()
    {
        // validasi & simpan
    }

    public function edit($id)
    {
        // tampilkan form edit
    }

    public function update($id)
    {
        // validasi & update
    }

    public function delete($id)
    {
        // hapus artikel
    }
}
```

### Langkah 5 — Tambah Route di `app/Config/Routes.php`

Tambahkan di dalam group `admin` yang sudah ada:

```php
$routes->group('admin', ['filter' => 'permission:admin.access'], static function ($routes) {

    // ... route existing ...

    // Artikel
    $routes->group('articles', static function ($routes) {
        $routes->get('/', 'ArticleController::index', ['filter' => 'permission:articles.list']);
        $routes->get('create', 'ArticleController::create', ['filter' => 'permission:articles.create']);
        $routes->post('store', 'ArticleController::store', ['filter' => 'permission:articles.create']);
        $routes->get('edit/(:num)', 'ArticleController::edit/$1', ['filter' => 'permission:articles.edit']);
        $routes->post('update/(:num)', 'ArticleController::update/$1', ['filter' => 'permission:articles.edit']);
        $routes->post('delete/(:num)', 'ArticleController::delete/$1', ['filter' => 'permission:articles.delete']);
    });
});
```

### Langkah 6 — Buat View

Buat file view di `app/Views/articles/index.php`:

```php
<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h4>Daftar Artikel</h4>
        <div class="card-header-action">
          <?php if (auth()->user()->can('articles.create')): ?>
          <a href="<?= base_url('admin/articles/create') ?>" class="btn btn-primary">
            <i class="fas fa-plus"></i> Tambah Artikel
          </a>
          <?php endif; ?>
        </div>
      </div>
      <div class="card-body">
        <!-- isi tabel artikel -->
      </div>
    </div>
  </div>
</div>
```

### Langkah 7 — Tambah Menu di Sidebar

Edit file `app/Views/partials/sidebar.php`, tambahkan di dalam section **Administrasi**:

```php
<?php if ($currentUser->can('articles.list')): ?>
<li class="<?= isMenuActive('admin/articles') ?>">
  <a class="nav-link" href="<?= base_url('admin/articles') ?>">
    <i class="fas fa-newspaper"></i> <span>Artikel</span>
  </a>
</li>
<?php endif; ?>
```

### Ringkasan Checklist

| # | File yang diubah/dibuat | Apa yang ditambah |
|---|-------------------------|-------------------|
| 1 | `app/Config/AuthGroups.php` | Permission baru + assign ke matrix role |
| 2 | `app/Database/Migrations/` | Migration tabel baru |
| 3 | `app/Models/` | Model baru |
| 4 | `app/Controllers/` | Controller baru (extend `BaseController`, pakai `renderView()`) |
| 5 | `app/Config/Routes.php` | Route baru dengan filter permission |
| 6 | `app/Views/` | View files (index, create, edit) |
| 7 | `app/Views/partials/sidebar.php` | Menu baru dibungkus `$currentUser->can()` |

> **Prinsip utama:** Permission didaftarkan dulu → assign ke role di matrix → gunakan filter di route → cek di view untuk tampilkan/sembunyikan elemen UI.

---

## Lisensi

MIT License
