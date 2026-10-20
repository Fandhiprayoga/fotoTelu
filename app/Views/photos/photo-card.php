<?php
/** @var array $photo */
/** @var array $album */
/** @var list<array> $folders */
/** @var array<string, bool> $permissions */
$labels = ['pending' => 'Menunggu proses', 'ready' => 'Siap dicari', 'no_face' => 'Tanpa wajah', 'failed' => 'Gagal diproses', 'deleting' => 'Sedang dihapus'];
$photoId = (int) $photo['id'];
?>
<article class="ph-photo-card">
    <div class="ph-photo-image">
        <?php if ($photo['status'] !== 'deleting'): ?>
            <a href="<?= site_url('photo-files/' . $photo['uuid'] . '/original') ?>" target="_blank" rel="noopener" aria-label="<?= esc('Lihat ' . $photo['name'], 'attr') ?>">
                <img src="<?= site_url('photo-files/' . $photo['uuid'] . '/thumbnail') ?>" alt="<?= esc($photo['name'], 'attr') ?>" loading="lazy" decoding="async">
            </a>
        <?php else: ?><span class="ph-deleting">Penghapusan diantrekan</span><?php endif ?>
        <span class="ph-badge ph-status-<?= esc($photo['status'], 'attr') ?>"><?= esc($labels[$photo['status']] ?? 'Belum tersedia') ?></span>
    </div>
    <div class="ph-card-body"><h3 title="<?= esc($photo['name'], 'attr') ?>"><?= esc($photo['name']) ?></h3>
        <p class="ph-meta"><?= (int) $photo['width'] ?> × <?= (int) $photo['height'] ?> <span>·</span> <?= number_format((int) $photo['bytes'] / 1048576, 1, ',', '.') ?> MB</p>
        <?php if (! empty($photo['error'])): ?><p class="ph-inline-warning">Pemrosesan belum selesai. Periksa worker atau coba ulang.</p><?php endif ?>
        <?php if ($photo['status'] === 'no_face'): ?><p class="ph-meta">Tidak ada wajah yang dapat diindeks.</p><?php endif ?>
        <?php if ($photo['status'] !== 'deleting'): ?>
            <details class="ph-details"><summary>Ubah nama / pindahkan</summary>
                <form class="ph-form" method="post" action="<?= site_url('admin/photos/items/' . $photoId . '/update') ?>">
                    <?= csrf_field() ?><input type="hidden" name="album_id" value="<?= (int) $album['id'] ?>">
                    <label for="photo-name-<?= $photoId ?>">Nama foto</label><input id="photo-name-<?= $photoId ?>" name="name" maxlength="120" value="<?= esc($photo['name'], 'attr') ?>" required>
                    <label for="photo-folder-<?= $photoId ?>">Lokasi dalam album</label><select id="photo-folder-<?= $photoId ?>" name="folder_id"><?= view('photos/folder-options', ['folders' => $folders, 'selectedFolder' => (int) $photo['folder_id'], 'excludedFolder' => 0]) ?></select>
                    <button class="ph-btn" type="submit">Simpan perubahan</button>
                </form>
            </details>
        <?php endif ?>
        <div class="ph-card-actions">
            <?php if ($permissions['photos.download'] && $photo['status'] !== 'deleting'): ?><a class="ph-text-link" href="<?= site_url('photo-files/' . $photo['uuid'] . '/download') ?>">Unduh asli ↓</a><?php endif ?>
            <?php if ($permissions['photos.upload'] && (in_array($photo['status'], ['failed', 'no_face'], true) || ($photo['status'] === 'deleting' && $permissions['photos.delete']))): ?>
                <form method="post" action="<?= site_url('admin/photos/items/' . $photoId . '/retry') ?>"><?= csrf_field() ?><input type="hidden" name="album_id" value="<?= (int) $album['id'] ?>"><button class="ph-link-button" type="submit">Coba ulang</button></form>
            <?php endif ?>
        </div>
        <?php if ($permissions['photos.delete'] && $photo['status'] !== 'deleting'): ?>
            <details class="ph-details ph-danger-zone"><summary>Hapus foto</summary><form class="ph-form" method="post" action="<?= site_url('admin/photos/items/' . $photoId . '/delete') ?>">
                <?= csrf_field() ?><input type="hidden" name="album_id" value="<?= (int) $album['id'] ?>">
                <label class="ph-checkbox"><input type="checkbox" required> <span>Saya memahami foto dan indeks wajah akan dihapus permanen.</span></label>
                <button class="ph-btn ph-btn-danger" type="submit">Hapus permanen</button>
            </form></details>
        <?php endif ?>
    </div>
</article>