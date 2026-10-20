<?php
/** @var array $album */
/** @var list<array> $folders */
/** @var list<array> $breadcrumbs */
/** @var list<array> $photos */
/** @var array<string, bool> $permissions */
/** @var int $folderId */
/** @var int $total */
/** @var int $page */
/** @var int $pages */
/** @var string $q */
/** @var bool $engineReady */
?>
<link rel="stylesheet" href="<?= base_url('assets/css/photos.css') ?>">
<section class="photos-app" aria-label="Pengelola album">
    <header class="ph-hero"><div><a class="ph-back" href="<?= site_url('admin/photos') ?>">← Semua album</a><h1><?= esc($album['name']) ?></h1>
        <p>Ruang kerja foto Anda. Susun folder, unggah momen, lalu terbitkan ketika siap.</p></div>
        <div class="ph-hero-status"><span class="ph-badge <?= $album['status'] === 'published' ? 'ph-badge-ready' : '' ?>"><?= $album['status'] === 'published' ? 'Album terbit' : 'Draf privat' ?></span>
            <span class="ph-engine <?= $engineReady ? 'is-ready' : '' ?>">● <?= $engineReady ? 'Layanan wajah siap' : 'Layanan wajah belum terhubung' ?></span></div>
    </header>
    <?php if (! $engineReady): ?><div class="ph-notice" role="status">Unggahan tetap dapat disimpan. Pengindeksan dan pencarian menunggu konfigurasi layanan wajah serta worker aktif.</div><?php endif ?>
    <?php if ($report = session()->getFlashdata('photo_upload_report')): ?>
        <section class="ph-panel" aria-label="Laporan unggahan" role="status"><h2>Laporan unggahan</h2><ul class="ph-upload-report">
            <?php foreach ($report as $entry): ?><li><span class="<?= $entry['ok'] ? 'ph-ok' : 'ph-error' ?>"><?= $entry['ok'] ? '✓' : '!' ?></span><strong><?= esc($entry['name']) ?></strong><span><?= esc($entry['message']) ?></span></li><?php endforeach ?>
        </ul></section>
    <?php endif ?>
    <div class="ph-tools-grid">
        <?php if ($permissions['photos.upload']): ?>
            <details class="ph-panel ph-upload-panel" open><summary>↑ Unggah foto ke lokasi ini</summary>
                <form method="post" action="<?= site_url('admin/photos/upload') ?>" enctype="multipart/form-data" class="ph-form">
                    <?= csrf_field() ?><input type="hidden" name="album_id" value="<?= (int) $album['id'] ?>"><input type="hidden" name="folder_id" value="<?= $folderId ?>">
                    <div class="ph-dropzone"><label for="gallery-photos">Pilih foto dari perangkat</label><input type="file" id="gallery-photos" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required><p>JPEG, PNG, WebP · maksimal 20 foto per batch, <?= number_format(config('Photos')->maxUploadBytes / 1048576, 0) ?> MB/foto.</p></div>
                    <label class="ph-checkbox"><input type="checkbox" name="consent" value="1" required><span>Saya berhak mengunggah foto ini dan memiliki dasar hukum serta persetujuan yang diperlukan untuk penyimpanan dan pemrosesan wajah demi pencarian foto.</span></label>
                    <button class="ph-btn ph-btn-primary" type="submit">Unggah &amp; antrekan pemrosesan</button>
                </form>
            </details>
        <?php endif ?>
        <?php if ($permissions['albums.manage']): ?>
            <div class="ph-panel"><details class="ph-details"><summary>+ Folder baru</summary><form class="ph-form" method="post" action="<?= site_url('admin/photos/folders') ?>">
                <?= csrf_field() ?><input type="hidden" name="album_id" value="<?= (int) $album['id'] ?>"><input type="hidden" name="parent_id" value="<?= $folderId ?>">
                <label for="folder-name">Nama folder</label><input id="folder-name" name="name" maxlength="120" required placeholder="Contoh: Sesi panggung"><button class="ph-btn" type="submit">Buat di lokasi ini</button>
            </form></details>
            <details class="ph-details"><summary>Pengaturan &amp; penerbitan album</summary><form class="ph-form" method="post" action="<?= site_url('admin/photos/albums/' . (int) $album['id'] . '/update') ?>">
                <?= csrf_field() ?><label for="edit-album-name">Nama album</label><input id="edit-album-name" name="name" value="<?= esc($album['name'], 'attr') ?>" maxlength="120" required>
                <?php if ($permissions['albums.publish']): ?><label for="album-status">Akses album</label><select id="album-status" name="status"><option value="draft" <?= $album['status'] === 'draft' ? 'selected' : '' ?>>Draf — hanya pengelola</option><option value="published" <?= $album['status'] === 'published' ? 'selected' : '' ?>>Terbit — tersedia untuk pencarian</option></select>
                    <label class="ph-checkbox"><input type="checkbox" name="consent" value="1"><span>Saat menerbitkan, saya memastikan hak penggunaan dan persetujuan pemrosesan wajah telah dipenuhi.</span></label>
                <?php else: ?><input type="hidden" name="status" value="<?= esc($album['status'], 'attr') ?>"><?php endif ?>
                <p class="ph-meta">Perubahan mencabut hasil pencarian sebelumnya. Hanya foto siap proses yang dapat ditemukan.</p><button class="ph-btn" type="submit">Simpan album</button>
            </form></details>
            <?php if ($permissions['photos.delete'] && ($album['status'] !== 'published' || $permissions['albums.publish'])): ?>
                <details class="ph-details ph-danger-zone"><summary>Hapus album kosong</summary><form class="ph-form" method="post" action="<?= site_url('admin/photos/albums/' . (int) $album['id'] . '/delete') ?>"><?= csrf_field() ?><label class="ph-checkbox"><input type="checkbox" required><span>Hapus album ini secara permanen. Semua folder dan foto harus sudah kosong.</span></label><button class="ph-btn ph-btn-danger" type="submit">Hapus album</button></form></details>
            <?php endif ?></div>
        <?php endif ?>
    </div>
    <nav class="ph-breadcrumb" aria-label="Lokasi folder"><a href="<?= site_url('admin/photos/album/' . (int) $album['id']) ?>">Akar album</a>
        <?php foreach ($breadcrumbs as $crumb): ?><span aria-hidden="true">/</span><a href="<?= site_url('admin/photos/album/' . (int) $album['id']) . '?folder=' . (int) $crumb['id'] ?>" <?= (int) $crumb['id'] === $folderId ? 'aria-current="page"' : '' ?>><?= esc($crumb['name']) ?></a><?php endforeach ?>
    </nav>
    <?php $children = array_filter($folders, static fn (array $folder): bool => (int) $folder['parent_id'] === $folderId); ?>
    <?php if ($children !== []): ?><div class="ph-folder-grid">
        <?php foreach ($children as $folder): $fid = (int) $folder['id']; ?>
            <article class="ph-folder-card"><a class="ph-folder-link" href="<?= site_url('admin/photos/album/' . (int) $album['id']) . '?folder=' . $fid ?>"><span aria-hidden="true">▰</span><strong><?= esc($folder['name']) ?></strong><span aria-hidden="true">→</span></a>
                <?php if ($permissions['albums.manage']): ?><details class="ph-details"><summary>Kelola folder</summary><form class="ph-form" method="post" action="<?= site_url('admin/photos/folders/' . $fid . '/update') ?>">
                    <?= csrf_field() ?><input type="hidden" name="album_id" value="<?= (int) $album['id'] ?>"><label for="folder-name-<?= $fid ?>">Nama folder</label><input id="folder-name-<?= $fid ?>" name="name" value="<?= esc($folder['name'], 'attr') ?>" maxlength="120" required>
                    <label for="folder-parent-<?= $fid ?>">Folder induk</label><select id="folder-parent-<?= $fid ?>" name="parent_id"><?= view('photos/folder-options', ['folders' => $folders, 'selectedFolder' => (int) $folder['parent_id'], 'excludedFolder' => $fid]) ?></select><button class="ph-btn" type="submit">Simpan folder</button>
                </form>
                <?php if ($permissions['photos.delete']): ?><form class="ph-form ph-delete-form" method="post" action="<?= site_url('admin/photos/folders/' . $fid . '/delete') ?>"><?= csrf_field() ?><input type="hidden" name="album_id" value="<?= (int) $album['id'] ?>"><label class="ph-checkbox"><input type="checkbox" required><span>Konfirmasi hapus folder kosong.</span></label><button class="ph-btn ph-btn-danger" type="submit">Hapus folder</button></form><?php endif ?>
                </details><?php endif ?>
            </article>
        <?php endforeach ?>
    </div><?php endif ?>
    <div class="ph-toolbar"><div><h2>Foto di lokasi ini <span class="ph-count"><?= $total ?></span></h2><p>36 foto per halaman · segarkan untuk memperbarui status.</p></div>
        <form method="get" action="<?= site_url('admin/photos/album/' . (int) $album['id']) ?>" class="ph-search-bar"><input type="hidden" name="folder" value="<?= $folderId ?>"><label class="ph-sr-only" for="filename-search">Cari nama foto</label><input id="filename-search" name="q" maxlength="120" value="<?= esc($q, 'attr') ?>" placeholder="Cari nama foto…"><button class="ph-btn" type="submit">Cari</button></form>
    </div>
    <?php if ($photos === []): ?><div class="ph-empty"><span class="ph-empty-icon" aria-hidden="true">▧</span><h3><?= $q !== '' ? 'Belum ada nama yang cocok' : 'Belum ada foto di sini' ?></h3><p><?= $q !== '' ? 'Coba kata lain. Pencarian nama hanya berlaku di folder yang sedang dibuka.' : 'Unggah foto pertama Anda atau buka folder di atas.' ?></p></div>
    <?php else: ?><div class="ph-photo-grid"><?php foreach ($photos as $photo): ?><?= view('photos/photo-card', ['photo' => $photo, 'album' => $album, 'folders' => $folders, 'permissions' => $permissions]) ?><?php endforeach ?></div><?php endif ?>
    <?php if ($pages > 1): ?><nav class="ph-pagination" aria-label="Halaman foto">
        <?php $pageUrl = site_url('admin/photos/album/' . (int) $album['id']) . '?' . http_build_query(['folder' => $folderId, 'q' => $q]); ?>
        <?php if ($page > 1): ?><a class="ph-btn" href="<?= esc($pageUrl . '&page=' . ($page - 1), 'attr') ?>">← Sebelumnya</a><?php endif ?>
        <span>Halaman <?= $page ?> dari <?= $pages ?></span>
        <?php if ($page < $pages): ?><a class="ph-btn" href="<?= esc($pageUrl . '&page=' . ($page + 1), 'attr') ?>">Berikutnya →</a><?php endif ?>
    </nav><?php endif ?>
    <p class="ph-footnote">Folder maksimal 20 tingkat. Pemindahan folder dan foto hanya dalam album yang sama; pemindahan lintas album ditunda untuk versi berikutnya. Foto berstatus menunggu, gagal, tanpa wajah, atau dihapus tidak muncul pada hasil pencarian pengguna.</p>
</section>