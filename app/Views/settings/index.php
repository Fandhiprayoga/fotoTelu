<?php
/** @var array $settings */
/** @var array $groups */
/** @var string $activeTab */

$s = function (string $key) use ($settings) {
    return esc($settings[$key] ?? '');
};
?>

<style>
  .brand-preview {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    height: 72px;
    padding: 8px;
    margin-bottom: 8px;
    border: 1px solid var(--color-border);
    border-radius: 8px;
    background: var(--color-surface-2);
  }
  .brand-preview img {
    max-height: 100%;
    max-width: 100%;
    object-fit: contain;
  }
  .brand-preview--favicon img {
    max-height: 40px;
    max-width: 40px;
  }
  .brand-preview .badge {
    position: absolute;
    top: 6px;
    right: 6px;
  }
</style>

<div class="page__section">
  <div class="flex flex-col gap-4">
    <nav class="w-full overflow-x-auto" aria-label="Bagian pengaturan">
      <div class="btn-group" role="group" data-group="settings-tabs" aria-label="Pilih bagian pengaturan">
        <button type="button" class="btn btn-outline <?= $activeTab === 'general' ? 'active' : '' ?>" aria-controls="settingsTabs" aria-pressed="<?= $activeTab === 'general' ? 'true' : 'false' ?>" data-stisla-tabs-value="general">Umum</button>
        <button type="button" class="btn btn-outline <?= $activeTab === 'appearance' ? 'active' : '' ?>" aria-controls="settingsTabs" aria-pressed="<?= $activeTab === 'appearance' ? 'true' : 'false' ?>" data-stisla-tabs-value="appearance">Tampilan</button>
        <button type="button" class="btn btn-outline <?= $activeTab === 'auth' ? 'active' : '' ?>" aria-controls="settingsTabs" aria-pressed="<?= $activeTab === 'auth' ? 'true' : 'false' ?>" data-stisla-tabs-value="auth">Autentikasi</button>
        <button type="button" class="btn btn-outline <?= $activeTab === 'mail' ? 'active' : '' ?>" aria-controls="settingsTabs" aria-pressed="<?= $activeTab === 'mail' ? 'true' : 'false' ?>" data-stisla-tabs-value="mail">Email</button>
      </div>
    </nav>

    <div class="w-full">
      <div class="tabs" id="settingsTabs" data-stisla-tabs>

        <!-- ======================== TAB: UMUM ======================== -->
        <div class="tabs__panel" data-value="general" data-state="<?= $activeTab === 'general' ? 'active' : 'inactive' ?>">
          <div class="flex flex-col gap-6">
            <section>
              <form action="<?= base_url('admin/settings/update/general') ?>" method="post">
                <?= csrf_field() ?>
                <div class="card">
                  <div class="card-header">
                    <div>
                      <div class="card-title">Pengaturan Umum</div>
                      <div class="card-subtitle">Nama aplikasi, deskripsi, dan informasi dasar.</div>
                    </div>
                  </div>
                  <div class="card-body">
                    <div class="grid grid-cols-12 gap-4">
                      <div class="col-span-12 sm:col-span-6">
                        <div class="field">
                          <label for="site_name" class="field__label">Nama Aplikasi <span class="text-danger">*</span></label>
                          <input type="text" class="input" id="site_name" name="site_name"
                                 value="<?= old('site_name', $s('App.siteName')) ?>" required>
                        </div>
                      </div>
                      <div class="col-span-12 sm:col-span-6">
                        <div class="field">
                          <label for="site_name_short" class="field__label">Nama Pendek</label>
                          <input type="text" class="input" id="site_name_short" name="site_name_short"
                                 value="<?= old('site_name_short', $s('App.siteNameShort')) ?>" maxlength="10">
                          <small class="text-muted-foreground text-xs">Maks 10 karakter, ditampilkan saat sidebar diminimalkan.</small>
                        </div>
                      </div>
                      <div class="col-span-12">
                        <div class="field">
                          <label for="site_description" class="field__label">Deskripsi</label>
                          <textarea class="input" id="site_description" name="site_description" rows="2"><?= old('site_description', $s('App.siteDescription')) ?></textarea>
                        </div>
                      </div>
                      <div class="col-span-12 sm:col-span-8">
                        <div class="field">
                          <label for="site_footer" class="field__label">Teks Footer</label>
                          <input type="text" class="input" id="site_footer" name="site_footer"
                                 value="<?= old('site_footer', $s('App.siteFooter')) ?>">
                        </div>
                      </div>
                      <div class="col-span-12 sm:col-span-4">
                        <div class="field">
                          <label for="site_version" class="field__label">Versi</label>
                          <input type="text" class="input" id="site_version" name="site_version"
                                 value="<?= old('site_version', $s('App.siteVersion')) ?>">
                        </div>
                      </div>
                    </div>
                    <div class="flex justify-end mt-4">
                      <button type="submit" class="btn btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.5" d="M17 21H7a4 4 0 0 1-4-4V7a4 4 0 0 1 4-4h6l7 7v7a4 4 0 0 1-4 4z" /><path fill="none" stroke="currentColor" stroke-width="1.5" d="M13 3v4a2 2 0 0 0 2 2h4" /></svg>
                        Simpan
                      </button>
                    </div>
                  </div>
                </div>
              </form>
            </section>

            <section>
              <form action="<?= base_url('admin/settings/update/branding') ?>" method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <div class="card">
                  <div class="card-header">
                    <div>
                      <div class="card-title">Branding</div>
                      <div class="card-subtitle">Logo dan favicon aplikasi.</div>
                    </div>
                  </div>
                  <div class="card-body">
                    <div class="grid grid-cols-12 gap-4">
                      <div class="col-span-12 sm:col-span-6">
                        <div class="field">
                          <label class="field__label">Logo Aplikasi</label>
                          <?php
                            $currentLogo = $settings['App.siteLogo'] ?? '';
                            $logoUrl = ! empty($currentLogo) ? base_url($currentLogo) : base_url('assets/img/stisla-fill.svg');
                          ?>
                          <div class="brand-preview">
                            <img src="<?= $logoUrl ?>" alt="Current Logo" id="logoPreview">
                            <?php if (empty($currentLogo)): ?>
                              <span class="badge badge--soft badge--secondary">Default</span>
                            <?php endif; ?>
                          </div>
                          <input type="file" class="input" id="site_logo" name="site_logo" accept="image/*"
                                 onchange="previewImage(this, 'logoPreview')">
                          <small class="text-muted-foreground text-xs">PNG, JPG, SVG, WebP. Maks 2MB.</small>
                        </div>
                      </div>
                      <div class="col-span-12 sm:col-span-6">
                        <div class="field">
                          <label class="field__label">Favicon</label>
                          <?php
                            $currentFavicon = $settings['App.siteFavicon'] ?? '';
                            $faviconUrl = ! empty($currentFavicon) ? base_url($currentFavicon) : base_url('assets/img/stisla-fill.svg');
                          ?>
                          <div class="brand-preview brand-preview--favicon">
                            <img src="<?= $faviconUrl ?>" alt="Current Favicon" id="faviconPreview">
                            <?php if (empty($currentFavicon)): ?>
                              <span class="badge badge--soft badge--secondary">Default</span>
                            <?php endif; ?>
                          </div>
                          <input type="file" class="input" id="site_favicon" name="site_favicon" accept="image/*,.ico"
                                 onchange="previewImage(this, 'faviconPreview')">
                          <small class="text-muted-foreground text-xs">PNG, ICO, SVG, WebP. Maks 1MB.</small>
                        </div>
                      </div>
                    </div>
                    <div class="flex justify-end mt-4">
                      <button type="submit" class="btn btn-primary">Upload Branding</button>
                    </div>
                  </div>
                </div>
              </form>
            </section>

            <section>
              <form action="<?= base_url('admin/settings/reset') ?>" method="post"
                    onsubmit="return confirm('Reset pengaturan Umum & Branding ke default?')">
                <?= csrf_field() ?>
                <input type="hidden" name="tab" value="general">
                <button type="submit" class="btn btn-danger btn-sm">Reset ke Default</button>
              </form>
            </section>
          </div>
        </div>

        <!-- ======================== TAB: TAMPILAN ======================== -->
        <div class="tabs__panel" data-value="appearance" data-state="<?= $activeTab === 'appearance' ? 'active' : 'inactive' ?>">
          <div class="flex flex-col gap-6">
            <section>
              <form id="theme-settings" action="<?= base_url('admin/settings/update/appearance') ?>" method="post">
                <?= csrf_field() ?>
                <div class="theme-settings-layout">
                  <div class="theme-settings-controls">
                    <div class="card">
                      <div class="card-header"><div class="card-title">Warna utama</div></div>
                      <div class="card-body">
                        <div class="theme-settings-swatches" aria-label="Pilih warna utama">
                          <?php foreach (['#E56A4A' => 'Warm', '#1ABB9C' => 'Teal', '#066fd1' => 'Biru', '#4263eb' => 'Indigo', '#ae3ec9' => 'Ungu', '#d6336c' => 'Merah muda', '#d63939' => 'Merah', '#f76707' => 'Oranye', '#f59f00' => 'Kuning', '#2fb344' => 'Hijau', '#17a2b8' => 'Sian', '#0f1623' => 'Hitam'] as $color => $label): ?>
                            <button type="button" class="theme-settings-swatch" data-theme-color="<?= $color ?>" style="background:<?= $color ?>" title="<?= $label ?>" aria-label="<?= $label ?>" aria-pressed="false"></button>
                          <?php endforeach; ?>
                        </div>
                        <div class="form-group" style="margin:14px 0 0">
                          <label class="form-label" for="theme_primary">Warna kustom</label>
                          <div class="theme-settings-color-input">
                            <input type="color" id="theme_primary_picker" value="<?= esc(old('theme_primary', $s('App.themePrimary')), 'attr') ?>" aria-label="Pilih warna kustom">
                            <input type="text" id="theme_primary" name="theme_primary" class="form-control" value="<?= esc(old('theme_primary', $s('App.themePrimary')), 'attr') ?>" pattern="^#[0-9A-Fa-f]{6}$" maxlength="7" required>
                          </div>
                        </div>
                      </div>
                    </div>

                    <div class="card">
                      <div class="card-header"><div class="card-title">Sidebar</div></div>
                      <div class="card-body">
                        <div class="segmented theme-settings-options" role="radiogroup" aria-label="Gaya sidebar">
                          <?php foreach (['dark' => 'Gelap', 'black' => 'Hitam', 'light' => 'Terang', 'brand' => 'Brand'] as $value => $label): ?>
                            <label><input type="radio" name="theme_sidebar" value="<?= $value ?>" <?= old('theme_sidebar', $s('App.themeSidebar')) === $value ? 'checked' : '' ?>><span><?= $label ?></span></label>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    </div>

                    <div class="card">
                      <div class="card-header"><div class="card-title">Geometri</div></div>
                      <div class="card-body">
                        <div class="form-group">
                          <label class="form-label" for="theme_radius">Radius sudut <output for="theme_radius" id="theme_radius_value"></output></label>
                          <input class="slider" type="range" id="theme_radius" name="theme_radius" min="0" max="16" step="1" value="<?= esc(old('theme_radius', $s('App.themeRadius')), 'attr') ?>">
                        </div>
                        <div class="form-group">
                          <label class="form-label" for="theme_sidebar_width">Lebar sidebar <output for="theme_sidebar_width" id="theme_sidebar_width_value"></output></label>
                          <input class="slider" type="range" id="theme_sidebar_width" name="theme_sidebar_width" min="200" max="320" step="4" value="<?= esc(old('theme_sidebar_width', $s('App.themeSidebarWidth')), 'attr') ?>">
                        </div>
                        <div class="form-group" style="margin-bottom:0">
                          <label class="form-label" for="theme_font_size">Ukuran teks <output for="theme_font_size" id="theme_font_size_value"></output></label>
                          <input class="slider" type="range" id="theme_font_size" name="theme_font_size" min="13" max="16" step="0.5" value="<?= esc(old('theme_font_size', $s('App.themeFontSize')), 'attr') ?>">
                        </div>
                      </div>
                    </div>

                    <div class="card">
                      <div class="card-header"><div class="card-title">Mode</div></div>
                      <div class="card-body">
                        <div class="segmented theme-settings-options" role="radiogroup" aria-label="Mode tampilan">
                          <?php foreach (['system' => 'Sistem', 'light' => 'Terang', 'dark' => 'Gelap'] as $value => $label): ?>
                            <label><input type="radio" name="theme_mode" value="<?= $value ?>" <?= old('theme_mode', $s('App.themeMode')) === $value ? 'checked' : '' ?>><span><?= $label ?></span></label>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="theme-settings-preview">
                    <div class="card">
                      <div class="card-header"><div><div class="card-title">Pratinjau langsung</div><div class="card-subtitle">Perubahan terlihat sebelum disimpan.</div></div></div>
                      <div class="card-body">
                        <div class="theme-settings-sample">
                          <div class="theme-settings-sample-nav">Menu utama<br><span>Dashboard</span><span>Pengaturan</span></div>
                          <div class="theme-settings-sample-content">
                            <strong>Tampilan aplikasi</strong>
                            <p class="form-help">Warna, kontrol, dan bentuk komponen.</p>
                            <div class="theme-settings-sample-actions"><span class="btn btn-primary">Utama</span><span class="btn btn-outline">Sekunder</span></div>
                            <div class="form-group" style="margin:16px 0 0"><label class="form-label" for="theme_preview_field">Kolom input</label><input class="form-control" id="theme_preview_field" type="text" value="Contoh teks" readonly></div>
                            <span class="badge badge-teal" style="margin-top:12px">Aktif</span>
                          </div>
                        </div>
                      </div>
                    </div>
                    <div class="theme-settings-actions">
                      <button type="reset" class="btn btn-outline">Batalkan perubahan</button>
                      <button type="submit" class="btn btn-primary">Simpan tampilan</button>
                    </div>
                  </div>
                </div>

              </form>
            </section>

            <section>
                <form id="theme-settings-reset" action="<?= base_url('admin/settings/reset') ?>" method="post"
                  onsubmit="return confirm('Reset pengaturan Tampilan ke default?')">
                <?= csrf_field() ?>
                <input type="hidden" name="tab" value="appearance">
                <button type="submit" class="btn btn-danger btn-sm">Reset ke Default</button>
              </form>
            </section>
          </div>
        </div>

        <!-- ======================== TAB: AUTENTIKASI ======================== -->
        <div class="tabs__panel" data-value="auth" data-state="<?= $activeTab === 'auth' ? 'active' : 'inactive' ?>">
          <div class="flex flex-col gap-6">
            <section>
              <form action="<?= base_url('admin/settings/update/auth') ?>" method="post">
                <?= csrf_field() ?>
                <div class="card">
                  <div class="card-header">
                    <div>
                      <div class="card-title">Autentikasi & Registrasi</div>
                      <div class="card-subtitle">Pengaturan role default, registrasi, dan mode pemeliharaan.</div>
                    </div>
                  </div>
                  <div class="card-body">
                    <div class="flex flex-col gap-4">
                      <div class="field">
                        <label for="default_role" class="field__label">Default Role <span class="text-danger">*</span></label>
                        <select class="select" id="default_role" name="default_role">
                          <?php foreach ($groups as $key => $group): ?>
                            <option value="<?= $key ?>" <?= ($settings['App.defaultRole'] ?? 'user') === $key ? 'selected' : '' ?>>
                              <?= esc($group['title']) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                        <small class="text-muted-foreground text-xs">Role otomatis untuk user baru saat registrasi.</small>
                      </div>

                      <div class="flex items-center justify-between">
                        <div>
                          <label class="field__label mb-0" for="allow_registration">Izinkan Registrasi</label>
                          <small class="text-muted-foreground text-xs">User baru bisa mendaftar sendiri.</small>
                        </div>
                           <label class="switch">
                             <input type="checkbox" role="switch" id="allow_registration"
                               name="allow_registration" value="1"
                               <?= !empty($settings['Auth.allowRegistration']) ? 'checked' : '' ?>>
                             <span class="track" aria-hidden="true"></span>
                           </label>
                      </div>

                      <hr class="separator">

                      <h3 class="text-sm font-semibold">Mode Pemeliharaan</h3>

                      <div class="flex items-center justify-between">
                        <div>
                          <label class="field__label mb-0" for="maintenance_mode">Maintenance Mode</label>
                          <small class="text-muted-foreground text-xs">Hanya Super Admin yang bisa mengakses sistem.</small>
                        </div>
                           <label class="switch">
                             <input type="checkbox" role="switch" id="maintenance_mode"
                               name="maintenance_mode" value="1"
                               <?= ($settings['App.maintenanceMode'] ?? '0') === '1' ? 'checked' : '' ?>>
                             <span class="track" aria-hidden="true"></span>
                           </label>
                      </div>

                      <div class="field">
                        <label for="maintenance_msg" class="field__label">Pesan Maintenance</label>
                        <textarea class="input" id="maintenance_msg" name="maintenance_msg" rows="2"><?= old('maintenance_msg', $s('App.maintenanceMsg')) ?></textarea>
                      </div>
                    </div>

                    <div class="flex justify-end mt-4">
                      <button type="submit" class="btn btn-primary">Simpan</button>
                    </div>
                  </div>
                </div>
              </form>
            </section>

            <section>
              <form action="<?= base_url('admin/settings/reset') ?>" method="post"
                    onsubmit="return confirm('Reset pengaturan Autentikasi ke default?')">
                <?= csrf_field() ?>
                <input type="hidden" name="tab" value="auth">
                <button type="submit" class="btn btn-danger btn-sm">Reset ke Default</button>
              </form>
            </section>
          </div>
        </div>

        <!-- ======================== TAB: EMAIL ======================== -->
        <div class="tabs__panel" data-value="mail" data-state="<?= $activeTab === 'mail' ? 'active' : 'inactive' ?>">
          <div class="flex flex-col gap-6">
            <section>
              <form action="<?= base_url('admin/settings/update/mail') ?>" method="post">
                <?= csrf_field() ?>
                <div class="card">
                  <div class="card-header">
                    <div>
                      <div class="card-title">Konfigurasi Email</div>
                      <div class="card-subtitle">Pengaturan SMTP dan identitas pengirim.</div>
                    </div>
                  </div>
                  <div class="card-body">
                    <div class="flex flex-col gap-4">
                      <div class="field">
                        <label for="mail_protocol" class="field__label">Protokol <span class="text-danger">*</span></label>
                        <select class="select" id="mail_protocol" name="mail_protocol" onchange="toggleSmtp()">
                          <?php $proto = $settings['Mail.protocol'] ?? 'smtp'; ?>
                          <option value="smtp" <?= $proto === 'smtp' ? 'selected' : '' ?>>SMTP</option>
                          <option value="sendmail" <?= $proto === 'sendmail' ? 'selected' : '' ?>>Sendmail</option>
                          <option value="mail" <?= $proto === 'mail' ? 'selected' : '' ?>>PHP Mail</option>
                        </select>
                      </div>

                      <div id="smtp-settings" class="flex flex-col gap-4">
                        <div class="grid grid-cols-12 gap-4">
                          <div class="col-span-12 sm:col-span-8">
                            <div class="field">
                              <label for="mail_hostname" class="field__label">SMTP Host</label>
                              <input type="text" class="input" id="mail_hostname" name="mail_hostname"
                                     value="<?= old('mail_hostname', $s('Mail.hostname')) ?>" placeholder="smtp.gmail.com">
                            </div>
                          </div>
                          <div class="col-span-12 sm:col-span-4">
                            <div class="field">
                              <label for="mail_port" class="field__label">Port</label>
                              <input type="number" class="input" id="mail_port" name="mail_port"
                                     value="<?= old('mail_port', $s('Mail.port')) ?>" placeholder="587">
                            </div>
                          </div>
                        </div>

                        <div class="grid grid-cols-12 gap-4">
                          <div class="col-span-12 sm:col-span-4">
                            <div class="field">
                              <label for="mail_encryption" class="field__label">Enkripsi</label>
                              <?php $enc = $settings['Mail.encryption'] ?? 'tls'; ?>
                              <select class="select" id="mail_encryption" name="mail_encryption">
                                <option value="tls" <?= $enc === 'tls' ? 'selected' : '' ?>>TLS</option>
                                <option value="ssl" <?= $enc === 'ssl' ? 'selected' : '' ?>>SSL</option>
                                <option value="none" <?= $enc === 'none' ? 'selected' : '' ?>>Tanpa Enkripsi</option>
                              </select>
                            </div>
                          </div>
                          <div class="col-span-12 sm:col-span-8">
                            <div class="field">
                              <label for="mail_username" class="field__label">Username</label>
                              <input type="text" class="input" id="mail_username" name="mail_username"
                                     value="<?= old('mail_username', $s('Mail.username')) ?>" placeholder="email@gmail.com" autocomplete="off">
                            </div>
                          </div>
                        </div>

                        <div class="field">
                          <label for="mail_password" class="field__label">Password</label>
                          <input type="password" class="input" id="mail_password" name="mail_password"
                                 placeholder="Kosongkan jika tidak ingin mengubah" autocomplete="new-password">
                          <?php if (! empty($settings['Mail.password'])): ?>
                            <small class="text-success text-xs">✓ Password sudah diatur</small>
                          <?php endif; ?>
                        </div>
                      </div>

                      <hr class="separator">

                      <h3 class="text-sm font-semibold">Identitas Pengirim</h3>

                      <div class="grid grid-cols-12 gap-4">
                        <div class="col-span-12 sm:col-span-6">
                          <div class="field">
                            <label for="mail_from_email" class="field__label">Email Pengirim</label>
                            <input type="email" class="input" id="mail_from_email" name="mail_from_email"
                                   value="<?= old('mail_from_email', $s('Mail.fromEmail')) ?>" placeholder="noreply@example.com">
                          </div>
                        </div>
                        <div class="col-span-12 sm:col-span-6">
                          <div class="field">
                            <label for="mail_from_name" class="field__label">Nama Pengirim</label>
                            <input type="text" class="input" id="mail_from_name" name="mail_from_name"
                                   value="<?= old('mail_from_name', $s('Mail.fromName')) ?>" placeholder="My App">
                          </div>
                        </div>
                      </div>
                    </div>

                    <div class="flex justify-between items-center mt-4">
                      <button type="button" class="btn btn-outline btn-sm" onclick="openTestEmail()">
                        <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" aria-hidden="true"><g fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 12c0-3.771 0-5.657 1.172-6.828S6.229 4 10 4h4c3.771 0 5.657 0 6.828 1.172S22 8.229 22 12s0 5.657-1.172 6.828S17.771 20 14 20h-4c-3.771 0-5.657 0-6.828-1.172S2 15.771 2 12Z" /><path stroke-linecap="round" d="m6 8l2.159 1.8c1.837 1.53 2.755 2.295 3.841 2.295s2.005-.765 3.841-2.296L18 8" /></g></svg>
                        Test Email
                      </button>
                      <button type="submit" class="btn btn-primary">Simpan</button>
                    </div>
                  </div>
                </div>
              </form>
            </section>

            <section>
              <form action="<?= base_url('admin/settings/reset') ?>" method="post"
                    onsubmit="return confirm('Reset pengaturan Email ke default?')">
                <?= csrf_field() ?>
                <input type="hidden" name="tab" value="mail">
                <button type="submit" class="btn btn-danger btn-sm">Reset ke Default</button>
              </form>
            </section>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>

<!-- Dialog: Test Email -->
<div class="dialog dialog--sm" id="testEmailDialog" data-stisla-dialog data-state="closed" role="dialog" aria-modal="true" aria-labelledby="testEmailDialogTitle" aria-hidden="true" tabindex="-1">
  <div class="dialog__backdrop" data-stisla-dialog-dismiss></div>
  <div class="dialog__panel">
    <div class="dialog__content">
      <div class="dialog__header">
        <div class="modal-dots" aria-hidden="true"><span></span><span></span><span></span></div>
        <h3 class="dialog__title" id="testEmailDialogTitle">Test Kirim Email</h3>
        <button type="button" class="dialog__close" aria-label="Tutup" data-stisla-dialog-dismiss>
          <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" aria-hidden="true">
            <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="2" d="M6 6l12 12M18 6L6 18" />
          </svg>
        </button>
      </div>
      <div class="dialog__body">
        <p class="text-muted-foreground text-sm mb-4">Kirim email percobaan menggunakan konfigurasi SMTP yang sudah disimpan.</p>

        <div id="testEmailResult" class="hidden"></div>

        <div class="flex flex-col gap-3">
          <div class="field">
            <label for="test_email_to" class="field__label">Alamat Email Tujuan <span class="text-danger">*</span></label>
            <input type="email" class="input" id="test_email_to" placeholder="contoh@email.com" required>
          </div>
          <div class="field">
            <label for="test_email_subject" class="field__label">Subjek</label>
            <input type="text" class="input" id="test_email_subject" value="Test Email - <?= esc(setting('App.siteName') ?? 'CI4 Shield RBAC') ?>">
          </div>
          <div class="field">
            <label for="test_email_message" class="field__label">Pesan</label>
            <textarea class="input" id="test_email_message" rows="3">Ini adalah email percobaan dari <?= esc(setting('App.siteName') ?? 'CI4 Shield RBAC') ?>. Jika Anda menerima email ini, konfigurasi SMTP sudah benar.</textarea>
          </div>
        </div>
      </div>
      <div class="dialog__footer">
        <button type="button" class="btn btn-outline" data-stisla-dialog-dismiss>Batal</button>
        <button type="button" class="btn btn-primary" id="btnSendTestEmail">Kirim</button>
      </div>
    </div>
  </div>
</div>

<script>
  (function() {
    var tabs = document.getElementById('settingsTabs');
    if (!tabs) return;

    var buttons = document.querySelectorAll('[data-stisla-tabs-value][aria-controls="settingsTabs"]');
    var panels = tabs.querySelectorAll('.tabs__panel[data-value]');

    function activateTab(value) {
      var selectedButton = Array.prototype.find.call(buttons, function(button) {
        return button.dataset.stislaTabsValue === value;
      });
      if (!selectedButton) selectedButton = buttons[0];
      if (!selectedButton) return;

      value = selectedButton.dataset.stislaTabsValue;
      buttons.forEach(function(button) {
        var isSelected = button === selectedButton;
        button.classList.toggle('active', isSelected);
        button.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
      });
      panels.forEach(function(panel) {
        panel.dataset.state = panel.dataset.value === value ? 'active' : 'inactive';
      });
    }

    buttons.forEach(function(button) {
      button.addEventListener('click', function() {
        var value = button.dataset.stislaTabsValue;
        activateTab(value);

        var url = new URL(window.location.href);
        url.searchParams.set('tab', value);
        window.history.pushState({ tab: value }, '', url);
      });
    });

    window.addEventListener('popstate', function() {
      activateTab(new URL(window.location.href).searchParams.get('tab'));
    });

    activateTab(new URL(window.location.href).searchParams.get('tab') || <?= json_encode($activeTab) ?>);
  })();

  function previewImage(input, previewId) {
    if (input.files && input.files[0]) {
      var reader = new FileReader();
      reader.onload = function(e) { document.getElementById(previewId).src = e.target.result; };
      reader.readAsDataURL(input.files[0]);
    }
  }

  function toggleSmtp() {
    var proto = document.getElementById('mail_protocol').value;
    var smtp = document.getElementById('smtp-settings');
    if (smtp) smtp.style.display = proto === 'smtp' ? '' : 'none';
  }

  function openTestEmail() {
    var dialog = document.getElementById('testEmailDialog');
    if (!dialog) return;
    var resultDiv = document.getElementById('testEmailResult');
    var btn = document.getElementById('btnSendTestEmail');
    if (resultDiv) {
      resultDiv.className = 'hidden';
      resultDiv.textContent = '';
    }
    if (btn) {
      btn.disabled = false;
      btn.textContent = 'Kirim';
    }
    dialog.dataset.state = 'open';
    dialog.setAttribute('aria-hidden', 'false');
  }

  function closeTestEmail() {
    var dialog = document.getElementById('testEmailDialog');
    if (dialog) {
      dialog.dataset.state = 'closed';
      dialog.setAttribute('aria-hidden', 'true');
    }
  }

  document.querySelectorAll('[data-stisla-dialog-dismiss]').forEach(function(el) {
    el.addEventListener('click', closeTestEmail);
  });

  document.getElementById('btnSendTestEmail').addEventListener('click', function() {
    var btn = this;
    var resultDiv = document.getElementById('testEmailResult');
    var emailTo = document.getElementById('test_email_to').value.trim();
    var subject = document.getElementById('test_email_subject').value.trim();
    var message = document.getElementById('test_email_message').value.trim();

    if (!emailTo) {
      resultDiv.className = 'mb-3 p-3 rounded text-sm';
      resultDiv.style.background = 'var(--color-warning-subtle, #fff3cd)';
      resultDiv.style.color = 'var(--color-warning, #856404)';
      resultDiv.textContent = 'Alamat email tujuan wajib diisi.';
      resultDiv.classList.remove('hidden');
      return;
    }

    btn.disabled = true;
    btn.textContent = 'Mengirim...';
    resultDiv.classList.add('hidden');

    var tokenInput = document.querySelector('input[name="<?= csrf_token() ?>"]');
    fetch('<?= site_url('admin/settings/test-email') ?>', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        '<?= csrf_header() ?>': tokenInput ? tokenInput.value : '<?= csrf_hash() ?>'
      },
      body: JSON.stringify({ email: emailTo, subject: subject, message: message })
    })
    .then(function(r) {
      var freshToken = r.headers.get('<?= csrf_header() ?>');
      if (freshToken) {
        document.querySelectorAll('input[name="<?= csrf_token() ?>"]').forEach(function(input) { input.value = freshToken; });
      }
      return r.json();
    })
    .then(function(data) {
      resultDiv.classList.remove('hidden');
      resultDiv.className = 'mb-3 p-3 rounded text-sm';
      if (data.success) {
        resultDiv.style.background = 'var(--color-success-subtle, #d4edda)';
        resultDiv.style.color = 'var(--color-success, #155724)';
      } else {
        resultDiv.style.background = 'var(--color-danger-subtle, #f8d7da)';
        resultDiv.style.color = 'var(--color-danger, #721c24)';
      }
      resultDiv.textContent = data.message;
    })
    .catch(function() {
      resultDiv.classList.remove('hidden');
      resultDiv.className = 'mb-3 p-3 rounded text-sm';
      resultDiv.style.background = 'var(--color-danger-subtle, #f8d7da)';
      resultDiv.style.color = 'var(--color-danger, #721c24)';
      resultDiv.textContent = 'Terjadi kesalahan saat mengirim email.';
    })
    .finally(function() {
      btn.disabled = false;
      btn.textContent = 'Kirim';
    });
  });

  toggleSmtp();
</script>
<script>
  (function () {
    var form = document.getElementById('theme-settings');
    var root = document.documentElement;
    var primary = document.getElementById('theme_primary');
    var picker = document.getElementById('theme_primary_picker');
    var swatches = form.querySelectorAll('[data-theme-color]');
    var sliders = ['theme_radius', 'theme_sidebar_width', 'theme_font_size'];

    function refreshTheme() {
      var color = primary.value.trim();
      if (/^#[0-9a-f]{6}$/i.test(color)) {
        root.style.setProperty('--primary', color);
        picker.value = color;
      }
      swatches.forEach(function (swatch) {
        swatch.setAttribute('aria-pressed', String(swatch.dataset.themeColor.toLowerCase() === color.toLowerCase()));
      });
      root.dataset.sidebarStyle = form.querySelector('[name="theme_sidebar"]:checked').value;
      sliders.forEach(function (id) {
        var slider = document.getElementById(id);
        document.getElementById(id + '_value').value = slider.value + ' px';
      });
      var radius = Number(document.getElementById('theme_radius').value);
      root.style.setProperty('--radius', radius + 'px');
      root.style.setProperty('--radius-sm', Math.max(0, radius - 2) + 'px');
      root.style.setProperty('--radius-lg', radius + 2 + 'px');
      root.style.setProperty('--sidebar-w', document.getElementById('theme_sidebar_width').value + 'px');
      root.style.setProperty('--font-size', Number(document.getElementById('theme_font_size').value) / 16 + 'rem');
      var mode = form.querySelector('[name="theme_mode"]:checked').value;
      root.dataset.theme = mode === 'system' ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : mode;
    }

    swatches.forEach(function (swatch) {
      swatch.addEventListener('click', function () {
        primary.value = swatch.dataset.themeColor;
        refreshTheme();
      });
    });
    picker.addEventListener('input', function () { primary.value = picker.value; refreshTheme(); });
    primary.addEventListener('input', refreshTheme);
    form.querySelectorAll('input[name^="theme_"]').forEach(function (input) {
      input.addEventListener('input', refreshTheme);
      input.addEventListener('change', refreshTheme);
    });
    form.addEventListener('reset', function () { requestAnimationFrame(function () { refreshTheme(); }); });
    form.addEventListener('submit', function () {
      var mode = form.querySelector('[name="theme_mode"]:checked').value;
      try {
        if (mode === 'system') localStorage.removeItem('theme');
        else localStorage.setItem('theme', mode);
      } catch (error) {}
    });
    document.getElementById('theme-settings-reset').addEventListener('submit', function (event) {
      if (!event.defaultPrevented) {
        try { localStorage.removeItem('theme'); } catch (error) {}
      }
    });
    refreshTheme();
  })();
</script>
