<?php
/** @var list<array> $albums */
/** @var array|null $selectedAlbum */
/** @var array|null $selfieStatus */
/** @var list<array> $results */
/** @var bool $submitted */
/** @var bool $engineReady */
/** @var bool $canManageSelfie */
/** @var bool $canDownload */
?>
<link rel="stylesheet" href="<?= base_url('assets/css/photos.css') ?>">
<script src="<?= base_url('assets/js/selfie-search.js') ?>" defer></script>
<section class="photos-app" aria-label="Pencarian foto pribadi">
    <header class="ph-hero"><div><span class="ph-eyebrow">MOMEN ANDA, LEBIH DEKAT</span><h1>Temukan diri Anda<br>di antara cerita.</h1><p>Simpan selfie, pilih album, lalu temukan foto yang mungkin memuat wajah Anda. Tanpa menelusuri seluruh galeri.</p></div><div class="ph-hero-art" aria-hidden="true"><span>◎</span><span>▧</span><span>✦</span></div></header>
    <?php if (! $engineReady): ?><div class="ph-notice" role="status"><strong>Layanan wajah belum terhubung.</strong> Penyimpanan selfie dan pencarian memerlukan konfigurasi layanan wajah yang siap. Hubungi administrator bila pesan ini berlanjut.</div><?php endif ?>
    <div class="ph-search-layout">
        <aside class="ph-panel ph-selfie-panel"><span class="ph-step">01 / SELFIE PRIBADI</span><h2>Satu selfie untuk memulai.</h2><p>Gunakan foto terang dengan tepat satu wajah, menghadap kamera, tanpa penghalang.</p>
            <?php if ($selfieStatus !== null): ?><div class="ph-selfie-state <?= $selfieStatus['usable'] ? 'ph-selfie-ready' : '' ?>"><strong><?= $selfieStatus['usable'] ? '✓ Selfie tersimpan' : 'Selfie perlu diperbarui / dibersihkan' ?></strong><span>Diperbarui <?= esc($selfieStatus['updated_at']) ?> UTC</span></div><?php else: ?><div class="ph-selfie-state"><strong>Belum ada selfie tersimpan</strong><span>Selfie Anda tidak ditampilkan kepada pengelola galeri.</span></div><?php endif ?>
            <?php if ($canManageSelfie): ?>
                <form method="post" action="<?= site_url('photos/selfie') ?>" enctype="multipart/form-data" class="ph-form" data-selfie-form data-auto-module="<?= base_url('assets/js/selfie-auto.mjs') ?>">
                    <?= csrf_field() ?><label for="selfie-file"><?= $selfieStatus !== null ? 'Ganti selfie' : 'Unggah selfie' ?></label><input id="selfie-file" name="selfie" type="file" accept="image/jpeg,image/png,image/webp" required data-selfie-file>
                    <p class="ph-meta">JPEG, PNG, WebP · maksimal <?= number_format(config('Photos')->maxUploadBytes / 1048576, 0) ?> MB. Unggahan biasa tetap dapat digunakan tanpa kamera.</p>
                    <button class="ph-btn" type="button" data-camera-open hidden>◎ Gunakan kamera</button>
                    <div class="ph-camera" data-camera-panel hidden>
                        <div class="ph-camera-stage">
                            <video data-camera-video autoplay muted playsinline aria-label="Pratinjau kamera selfie" aria-describedby="selfie-camera-guide"></video>
                            <div class="ph-camera-guide" aria-hidden="true"><span class="ph-camera-oval"></span><span class="ph-camera-countdown" data-camera-countdown hidden></span></div>
                        </div>
                        <p class="ph-camera-hint" id="selfie-camera-guide">Posisikan seluruh wajah di dalam oval. Foto otomatis diambil setelah satu wajah stabil selama 2 detik. Deteksi berlangsung di perangkat, tanpa mengirim video. Pemeriksaan kualitas akhir tetap dilakukan saat disimpan.</p>
                        <label class="ph-checkbox ph-camera-hint"><input type="checkbox" data-camera-auto checked><span>Ambil otomatis saat satu wajah stabil. Matikan untuk memakai tombol manual.</span></label>
                        <div class="ph-camera-actions"><button class="ph-btn ph-btn-primary" type="button" data-camera-capture disabled>Menyiapkan kamera…</button><button class="ph-btn" type="button" data-camera-close>Tutup kamera</button></div>
                    </div>
                    <img class="ph-selfie-preview" data-selfie-preview alt="Pratinjau selfie yang akan disimpan" hidden>
                    <p class="ph-camera-status" data-camera-status role="status" aria-live="polite"></p>
                    <label class="ph-checkbox"><input type="checkbox" name="consent" value="1" required><span>Saya menyetujui penyimpanan selfie dan data wajah terenkripsi untuk pencarian foto. Data disimpan tanpa batas waktu sampai saya menghapusnya atau akun saya dihapus. Mengganti atau menghapus selfie mencabut hasil pencarian lama.</span></label>
                    <button class="ph-btn ph-btn-primary" type="submit"><?= $selfieStatus !== null ? 'Simpan selfie pengganti' : 'Setujui & simpan selfie' ?></button>
                </form>
                <?php if ($selfieStatus !== null): ?><details class="ph-details ph-danger-zone"><summary>Hapus selfie &amp; cabut persetujuan</summary><form class="ph-form" method="post" action="<?= site_url('photos/selfie/delete') ?>"><?= csrf_field() ?><label class="ph-checkbox"><input type="checkbox" required><span>Hapus selfie dan data wajah saya, serta cabut akses hasil pencarian.</span></label><button class="ph-btn ph-btn-danger" type="submit">Hapus data selfie saya</button></form></details><?php endif ?>
            <?php else: ?><p class="ph-notice">Peran aktif Anda tidak memiliki izin mengelola selfie.</p><?php endif ?>
        </aside>
        <div class="ph-search-main"><section class="ph-panel"><span class="ph-step">02 / PILIH MOMEN</span><h2>Di album mana Anda hadir?</h2><p>Pencarian hanya dilakukan di satu album terbit, termasuk folder di dalamnya.</p>
            <?php if ($albums === []): ?><div class="ph-empty ph-empty-small"><h3>Belum ada album terbit</h3><p>Silakan kembali setelah pengelola menerbitkan album.</p></div><?php else: ?>
                <form method="post" action="<?= site_url('photos/search') ?>" class="ph-form ph-album-search">
                    <?= csrf_field() ?><label for="search-album">Album acara</label><select id="search-album" name="album_id" required><option value="">Pilih album…</option><?php foreach ($albums as $choice): ?><option value="<?= (int) $choice['id'] ?>" <?= (int) ($selectedAlbum['id'] ?? 0) === (int) $choice['id'] ? 'selected' : '' ?>><?= esc($choice['name']) ?></option><?php endforeach ?></select>
                    <button class="ph-btn ph-btn-primary" type="submit" <?= empty($selfieStatus['usable']) ? 'disabled' : '' ?>>Temukan foto saya →</button>
                    <?php if (empty($selfieStatus['usable'])): ?><p class="ph-meta">Simpan selfie yang dapat digunakan terlebih dahulu.</p><?php endif ?>
                </form>
            <?php endif ?>
        </section>
        <section class="ph-results" aria-label="Hasil pencarian"><div class="ph-toolbar"><div><span class="ph-step">03 / HASIL PENCARIAN</span><h2><?= $submitted ? 'Momen yang mungkin memuat Anda' : 'Cerita Anda menunggu' ?></h2><?php if ($submitted): ?><p><?= count($results) ?> foto · <?= esc($selectedAlbum['name']) ?></p><?php endif ?></div></div>
            <?php if (! $submitted): ?><div class="ph-empty"><span class="ph-empty-icon" aria-hidden="true">◎</span><h3>Mulai dari selfie dan sebuah album</h3><p>Hasil pencarian pribadi akan muncul di sini. Memilih album tidak memberikan akses ke seluruh galeri.</p></div>
            <?php elseif ($results === []): ?><div class="ph-empty"><span class="ph-empty-icon" aria-hidden="true">⌕</span><h3>Belum ada hasil yang dapat dibuka</h3><p>Foto mungkin belum diproses, belum cocok, atau izin hasil sudah kedaluwarsa. Coba selfie lebih jelas atau cari kembali.</p></div>
            <?php else: ?><p class="ph-result-note">Hasil dapat keliru. Periksa foto secara visual. Izin hasil berlaku hingga 15 menit dan dapat dicabut lebih awal saat album atau selfie berubah.</p><div class="ph-photo-grid ph-result-grid">
                <?php foreach ($results as $result): ?><article class="ph-photo-card"><a class="ph-result-image" href="<?= site_url('photo-files/' . $result['uuid'] . '/original') ?>" target="_blank" rel="noopener" aria-label="<?= esc('Lihat ' . $result['name'], 'attr') ?>"><img src="<?= site_url('photo-files/' . $result['uuid'] . '/thumbnail') ?>" loading="lazy" decoding="async" alt="<?= esc($result['name'], 'attr') ?>"></a><div class="ph-card-body"><h3><?= esc($result['name']) ?></h3><p class="ph-meta"><?= (int) $result['width'] ?> × <?= (int) $result['height'] ?></p><div class="ph-card-actions"><a class="ph-text-link" href="<?= site_url('photo-files/' . $result['uuid'] . '/original') ?>" target="_blank" rel="noopener">Lihat foto ↗</a><?php if ($canDownload): ?><a class="ph-btn ph-btn-small" href="<?= site_url('photo-files/' . $result['uuid'] . '/download') ?>">Unduh ↓</a><?php endif ?></div></div></article><?php endforeach ?>
            </div><?php endif ?>
        </section></div>
    </div>
    <div class="ph-privacy-note"><strong>Privasi &amp; batasan teknologi</strong><p>Pencocokan wajah dapat menghasilkan kecocokan keliru atau melewatkan foto Anda. Fitur ini bukan verifikasi identitas; tidak ada pemeriksaan keaktifan wajah (liveness), sehingga foto orang lain dapat disalahgunakan. Gunakan hanya selfie diri sendiri dan jangan mengandalkan hasil sebagai bukti identitas.</p></div>
</section>