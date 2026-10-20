<?php
/** @var list<array> $albums */
/** @var array<string, bool> $permissions */
/** @var bool $available */
/** @var bool $engineReady */
?>
<link rel="stylesheet" href="<?= base_url('assets/css/photos.css') ?>">
<section class="photos-app" aria-label="Pustaka foto">
    <header class="ph-hero">
        <div><span class="ph-eyebrow">FOTOTELU / PUSTAKA PRIVAT</span><h1>Setiap momen, tertata.</h1>
            <p>Kelola album, susun folder, dan siapkan foto agar mudah ditemukan pemilik momennya.</p></div>
        <span class="ph-engine <?= $engineReady ? 'is-ready' : '' ?>"><span aria-hidden="true">●</span> <?= $engineReady ? 'Layanan wajah siap' : 'Layanan wajah belum terhubung' ?></span>
    </header>
    <div class="ph-toolbar"><div><h2>Album Anda <span class="ph-count"><?= count($albums) ?></span></h2><p>Album baru selalu privat sebagai draf.</p></div>
        <?php if ($permissions['albums.manage'] && $available): ?><a class="ph-btn ph-btn-primary" href="#buat-album">+ Buat album</a><?php endif ?>
    </div>
    <?php if (! $available): ?>
        <div class="ph-notice" role="status">Pustaka belum tersedia. Minta administrator memeriksa koneksi database dan menjalankan migrasi foto.</div>
    <?php elseif ($albums === []): ?>
        <div class="ph-empty"><span class="ph-empty-icon" aria-hidden="true">▧</span><h3>Ruang untuk cerita pertama Anda</h3><p>Mulai dengan sebuah album. Foto akan disimpan secara privat, bukan di direktori publik.</p></div>
    <?php else: ?>
        <div class="ph-album-grid">
            <?php foreach ($albums as $album): ?>
                <a class="ph-album-card" href="<?= site_url('admin/photos/album/' . (int) $album['id']) ?>">
                    <div class="ph-album-cover" aria-hidden="true"><span>▧</span><span class="ph-cover-lines"></span></div>
                    <div class="ph-card-body"><span class="ph-badge <?= $album['status'] === 'published' ? 'ph-badge-ready' : '' ?>"><?= $album['status'] === 'published' ? 'Terbit' : 'Draf privat' ?></span>
                        <h3><?= esc($album['name']) ?></h3><div class="ph-meta"><span><?= (int) $album['photo_count'] ?> foto</span><span>Buka album →</span></div>
                    </div>
                </a>
            <?php endforeach ?>
        </div>
    <?php endif ?>
    <?php if ($permissions['albums.manage'] && $available): ?>
        <section class="ph-panel ph-create" id="buat-album"><div><span class="ph-eyebrow">MULAI CERITA BARU</span><h2>Buat album</h2><p>Gunakan nama acara, perjalanan, atau koleksi. Maksimal 120 karakter.</p></div>
            <form action="<?= site_url('admin/photos/albums') ?>" method="post" class="ph-form">
                <?= csrf_field() ?><label for="album-name">Nama album</label>
                <input id="album-name" name="name" maxlength="120" required placeholder="Contoh: Wisuda Oktober 2026" autocomplete="off">
                <button class="ph-btn ph-btn-primary" type="submit">Buat sebagai draf</button>
            </form>
        </section>
    <?php endif ?>
    <p class="ph-footnote">Draf hanya dapat dilihat pengelola. Album terbit dapat dipilih untuk pencarian, tetapi pengguna hanya dapat membuka foto dengan izin hasil pencarian yang masih berlaku.</p>
</section>