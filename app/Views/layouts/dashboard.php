<?php
$currentUser = auth()->user();
$userGroups = $currentUser->getGroups();
$siteName = setting('App.siteName') ?? 'CI4 Shield RBAC';
$siteNameShort = setting('App.siteNameShort') ?? 'C4';
$logo = setting('App.siteLogo');
$logoUrl = ! empty($logo) ? base_url($logo) : '';
$favicon = setting('App.siteFavicon');
$faviconUrl = ! empty($favicon) ? base_url($favicon) : base_url('assets/img/stisla-fill.svg');
$currentUrl = uri_string();
$isCurrent = static fn (string $path): bool => $currentUrl === $path || str_starts_with($currentUrl, $path . '/');
$themePrimary = setting('App.themePrimary') ?? '#E56A4A';
$themePrimary = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $themePrimary) ? $themePrimary : '#E56A4A';
$themeSidebar = setting('App.themeSidebar') ?? 'dark';
$themeSidebar = in_array($themeSidebar, ['dark', 'black', 'light', 'brand'], true) ? $themeSidebar : 'dark';
$themeRadius = (int) (setting('App.themeRadius') ?? 10);
$themeRadius = max(0, min(16, $themeRadius));
$themeSidebarWidth = (int) (setting('App.themeSidebarWidth') ?? 288);
$themeSidebarWidth = max(200, min(320, $themeSidebarWidth));
$themeFontSize = (float) (setting('App.themeFontSize') ?? 14);
$themeFontSize = max(13, min(16, $themeFontSize));
$themeMode = setting('App.themeMode') ?? 'system';
$themeMode = in_array($themeMode, ['system', 'light', 'dark'], true) ? $themeMode : 'system';
?>
<!doctype html>
<html lang="id" data-sidebar-style="<?= esc($themeSidebar, 'attr') ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= esc($title ?? 'Dashboard') ?> | <?= esc($siteName) ?></title>
  <link rel="icon" href="<?= esc($faviconUrl, 'attr') ?>" type="image/x-icon">
  <script>
    (function () {
      try {
        var theme = localStorage.getItem('theme');
        var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        var defaultMode = <?= json_encode($themeMode) ?>;
        document.documentElement.setAttribute('data-theme', theme || (defaultMode === 'system' ? (prefersDark ? 'dark' : 'light') : defaultMode));
      } catch (error) {}
    })();
  </script>
  <script type="module" src="<?= base_url('gentelella/js/rolldown-runtime-_5RX-BWT.js') ?>"></script>
  <script type="module" src="<?= base_url('gentelella/js/toast-CBtjS_PZ.js') ?>"></script>
  <script type="module" src="<?= base_url('gentelella/js/main-v4-CmWkxJuV.js') ?>"></script>
  <link rel="stylesheet" href="<?= base_url('assets/css/style.css') ?>">
  <link rel="stylesheet" href="<?= base_url('gentelella/assets/main-v4-DB_ReeJG.css') ?>">
  <link rel="stylesheet" href="<?= base_url('gentelella/assets/dashboard-app.css') ?>">
  <link rel="stylesheet" href="<?= base_url('gentelella/assets/theme-9router.css') ?>">
  <style>
    :root {
      --primary: <?= esc($themePrimary) ?>;
      --primary-dk: color-mix(in srgb, var(--primary) 80%, black);
      --primary-lt: color-mix(in srgb, var(--primary) 10%, transparent);
      --radius: <?= $themeRadius ?>px;
      --radius-sm: <?= max(0, $themeRadius - 2) ?>px;
      --radius-lg: <?= $themeRadius + 2 ?>px;
      --sidebar-w: <?= $themeSidebarWidth ?>px;
      --font-size: <?= $themeFontSize / 16 ?>rem;
    }
    :root[data-theme="dark"] { --primary-lt: color-mix(in srgb, var(--primary) 14%, transparent); }
    .sidebar-brand img.brand-icon {
      object-fit: contain;
      border-radius: 6px;
      width: 28px;
      height: 28px;
    }
  </style>
</head>
<body data-shell="admin" data-page="<?= esc($currentUrl === '' ? 'dashboard' : str_replace('/', '-', $currentUrl), 'attr') ?>" data-breadcrumb="Home > <?= esc($page_title ?? 'Dashboard', 'attr') ?>">
  <a class="skip-link" href="#main-content">Langsung ke konten</a>

  <aside class="sidebar" id="sidebar" aria-label="Navigasi utama">
    <div class="traffic-lights" aria-hidden="true">
      <span class="tl-dot tl-red"></span>
      <span class="tl-dot tl-yellow"></span>
      <span class="tl-dot tl-green"></span>
    </div>
    <div class="sidebar-brand">
      <?php if (! empty($logoUrl)): ?>
      <img class="brand-icon" src="<?= esc($logoUrl, 'attr') ?>" alt="<?= esc($siteName) ?>">
      <?php else: ?>
      <div class="brand-icon"><?= esc(! empty($siteNameShort) ? strtoupper(substr($siteNameShort, 0, 1)) : strtoupper(substr($siteName, 0, 1))) ?></div>
      <?php endif; ?>
      <div class="brand-name"><?= esc($siteName) ?><small>v<?= esc(setting('App.siteVersion') ?? '1.0.0') ?></small></div>
    </div>
    <nav class="sidebar-nav">
      <div class="nav-group">
        <div class="nav-label">Menu</div>
        <a class="nav-link <?= $isCurrent('dashboard') ? 'active' : '' ?>" href="<?= base_url('dashboard') ?>" <?= $isCurrent('dashboard') ? 'aria-current="page"' : '' ?>>
          <svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="3" width="7" height="4" rx="1.5"></rect><rect x="3" y="14" width="7" height="7" rx="1.5"></rect><rect x="14" y="10" width="7" height="11" rx="1.5"></rect></svg>
          <span class="nav-text">Dashboard</span>
        </a>
      </div>

      <?php if (activeGroupCan('photos.manage') || activeGroupCan('photos.search')): ?>
      <div class="nav-group">
        <div class="nav-label">FotoTelu</div>
        <?php if (activeGroupCan('photos.manage')): ?>
        <a class="nav-link <?= $isCurrent('admin/photos') ? 'active' : '' ?>" href="<?= site_url('admin/photos') ?>">
          <svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M3 7h7l2-3h9v16H3z"/><path d="M3 7V4h6"/></svg>
          <span class="nav-text">File Manager Foto</span>
        </a>
        <?php endif; ?>
        <?php if (activeGroupCan('photos.search')): ?>
        <a class="nav-link <?= $isCurrent('photos/search') ? 'active' : '' ?>" href="<?= site_url('photos/search') ?>">
          <svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="10" cy="10" r="6"/><path d="m15 15 6 6M8 9h4M8 12h4"/></svg>
          <span class="nav-text">Temukan Foto Saya</span>
        </a>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if (activeGroupCan('admin.access')): ?>
      <div class="nav-group">
        <div class="nav-label">Administrasi</div>
        <?php if (activeGroupCan('users.list')): ?>
        <a class="nav-link <?= $isCurrent('admin/users') ? 'active' : '' ?>" href="<?= base_url('admin/users') ?>">
          <svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="9" cy="8" r="4"></circle><path d="M3 21v-2a6 6 0 0112 0v2M16 4a4 4 0 010 8M18 15a5 5 0 013 4v2"></path></svg>
          <span class="nav-text">Manajemen User</span>
        </a>
        <?php endif; ?>
        <?php if (activeGroupIs('superadmin')): ?>
        <?php $isPermissionMatrix = $currentUrl === 'admin/roles/permissions'; ?>
        <a class="nav-link <?= $isCurrent('admin/roles') && ! $isPermissionMatrix ? 'active' : '' ?>" href="<?= base_url('admin/roles') ?>" <?= $isCurrent('admin/roles') && ! $isPermissionMatrix ? 'aria-current="page"' : '' ?>>
          <svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7l8-4z"></path><path d="M9 12l2 2 4-4"></path></svg>
          <span class="nav-text">Daftar Role</span>
        </a>
        <a class="nav-link <?= $isPermissionMatrix ? 'active' : '' ?>" href="<?= base_url('admin/roles/permissions') ?>" <?= $isPermissionMatrix ? 'aria-current="page"' : '' ?>>
          <svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"></rect><path d="M3 9h18M8 9v11m5-11v11m5-11v11"></path></svg>
          <span class="nav-text">Permission Matrix</span>
        </a>
        <?php endif; ?>
        <?php if (activeGroupCan('admin.settings')): ?>
        <a class="nav-link <?= $isCurrent('admin/settings') ? 'active' : '' ?>" href="<?= base_url('admin/settings') ?>">
          <svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.7 1.7 0 00.3 1.9l.1.1-1.8 3.1-.2-.1a1.7 1.7 0 00-1.9.3l-.1.1h-3.6l-.1-.2a1.7 1.7 0 00-1.6-1.1 1.7 1.7 0 00-.9.3l-.2.1-3.1-1.8.1-.2a1.7 1.7 0 00-.3-1.9l-.1-.1v-3.6l.2-.1a1.7 1.7 0 001.1-1.6 1.7 1.7 0 00-.3-.9l-.1-.2 1.8-3.1.2.1a1.7 1.7 0 001.9-.3l.1-.1h3.6l.1.2a1.7 1.7 0 001.6 1.1 1.7 1.7 0 00.9-.3l.2-.1 3.1 1.8-.1.2a1.7 1.7 0 00.3 1.9l.1.1z"></path></svg>
          <span class="nav-text">Pengaturan</span>
        </a>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="nav-group">
        <div class="nav-label">Akun</div>
        <a class="nav-link <?= $isCurrent('profile') ? 'active' : '' ?>" href="<?= base_url('profile') ?>">
          <svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0116 0"></path></svg>
          <span class="nav-text">Profil Saya</span>
        </a>
        <button class="nav-link" type="button" id="logoutTrigger" aria-haspopup="dialog" aria-controls="logoutModal">
          <svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3"></path><path d="M12 3h7a2 2 0 012 2v14a2 2 0 01-2 2h-7"></path></svg>
          <span class="nav-text">Keluar</span>
        </button>
      </div>
    </nav>

    <div class="sidebar-footer">
      <div class="sidebar-user">
        <div class="avatar"><?= esc(strtoupper(substr($currentUser->username ?? 'U', 0, 1))) ?><span class="online"></span></div>
        <div class="sidebar-user-info">
          <div class="name"><?= esc($currentUser->username ?? 'Pengguna') ?></div>
          <div class="role"><?= esc(activeGroupTitle()) ?></div>
        </div>
      </div>
    </div>
  </aside>

  <header class="topbar">
    <div class="topbar-left">
      <button class="sidebar-toggle" type="button" aria-label="Buka menu" aria-controls="sidebar" aria-expanded="false">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"></path></svg>
      </button>
      <nav class="breadcrumb" aria-label="Breadcrumb"><a href="<?= base_url('dashboard') ?>">Beranda</a><span class="sep" aria-hidden="true">›</span><span class="current" aria-current="page"><?= esc($page_title ?? 'Dashboard') ?></span></nav>
    </div>
    <div class="topbar-right">
      <?php if (count($userGroups) > 1): ?>
      <form action="<?= base_url('switch-group') ?>" method="post" class="role-switcher">
        <?= csrf_field() ?>
        <label class="sr-only" for="dashboard-role">Ganti role</label>
        <select id="dashboard-role" name="group" onchange="this.form.submit()">
          <?php foreach ($userGroups as $group): ?>
          <option value="<?= esc($group, 'attr') ?>" <?= $group === activeGroup() ? 'selected' : '' ?>><?= esc(config('AuthGroups')->groups[$group]['title'] ?? ucfirst($group)) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php endif; ?>
      <button class="tb-btn theme-toggle" type="button" title="Ganti tema" aria-label="Ganti tema" aria-pressed="false">
        <svg class="theme-icon-light" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32l1.41 1.41M2 12h2m16 0h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"></path></svg>
        <svg class="theme-icon-dark" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"></path></svg>
      </button>
      <button class="tb-avatar" type="button" data-account-menu
              aria-haspopup="menu" aria-expanded="false"
              aria-label="Menu akun <?= esc($currentUser->username ?? 'Pengguna', 'attr') ?>">
        <?= esc(strtoupper(substr($currentUser->username ?? 'U', 0, 1))) ?>
      </button>
    </div>
  </header>

  <main id="main-content" tabindex="-1" class="main">
    <div class="page-wrapper">
      <div class="page-header">
        <div class="page-header-row">
          <div>
            <div class="page-pretitle"><?= esc($page_pretitle ?? 'Ringkasan sistem') ?></div>
            <h1 class="page-title"><?= esc($page_title ?? 'Dashboard') ?></h1>
          </div>
          <?php if (!empty($page_actions)): ?>
          <div class="page-actions"><?= $page_actions ?></div>
          <?php endif; ?>
        </div>
      </div>

      <?php foreach (['success' => 'alert-success', 'error' => 'alert-error'] as $flashType => $alertClass): ?>
        <?php if ($message = session()->getFlashdata($flashType)): ?>
        <div class="alert <?= $alertClass ?>" role="<?= $flashType === 'success' ? 'status' : 'alert' ?>">
          <svg class="alert-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
            <?php if ($flashType === 'success'): ?>
              <circle cx="8" cy="8" r="6" />
              <path d="m5 8 2 2 4-4" />
            <?php else: ?>
              <circle cx="8" cy="8" r="6" />
              <path d="m6 6 4 4m0-4-4 4" />
            <?php endif; ?>
          </svg>
          <div class="alert-body"><?= esc($message) ?></div>
        </div>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if ($errors = session()->getFlashdata('errors')): ?>
      <div class="alert alert-error" role="alert">
        <svg class="alert-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
          <circle cx="8" cy="8" r="6" />
          <path d="m6 6 4 4m0-4-4 4" />
        </svg>
        <div class="alert-body">
          <ul style="margin:0;padding-left:16px"><?php foreach ($errors as $error): ?><li><?= esc($error) ?></li><?php endforeach; ?></ul>
        </div>
      </div>
      <?php endif; ?>

      <?= $content ?? '' ?>
    </div>
    <footer class="footer">
      <span><?= esc(setting('App.siteFooter') ?? 'CI4 Shield RBAC Boilerplate') ?></span>
      <span><?= date('Y') ?></span>
    </footer>
  </main>
  <div class="modal-backdrop" id="logoutModal" style="display:none" aria-hidden="true">
    <div class="modal-dialog modal-sm" role="dialog" aria-modal="true" aria-labelledby="logoutModalTitle" aria-describedby="logoutModalDescription">
      <div class="modal-header">
        <div class="modal-dots" aria-hidden="true"><span></span><span></span><span></span></div>
        <h2 class="modal-title" id="logoutModalTitle">Keluar dari akun?</h2>
        <button type="button" class="modal-close" data-logout-dismiss aria-label="Tutup">
          <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M3 3l8 8M11 3l-8 8"></path></svg>
        </button>
      </div>
      <div class="modal-body" id="logoutModalDescription">Sesi Anda akan diakhiri.</div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" data-logout-dismiss>Batal</button>
        <a class="btn btn-primary" href="<?= base_url('logout') ?>">Keluar</a>
      </div>
    </div>
  </div>
  <?php
    $accountMenu = [];
    $accountMenu[] = ['label' => 'Profil Saya', 'href' => base_url('profile')];
    if (activeGroupCan('admin.settings')) {
      $accountMenu[] = ['label' => 'Pengaturan', 'href' => base_url('admin/settings')];
    }
  ?>
  <script>
    (function () {
      var trigger = document.querySelector('[data-account-menu]');
      var logoutTrigger = document.getElementById('logoutTrigger');
      var items = <?= json_encode($accountMenu, JSON_UNESCAPED_SLASHES) ?>;

      function buildMenu() {
        var popover = document.createElement('div');
        popover.className = 'menu-popover';
        popover.setAttribute('role', 'menu');

        items.forEach(function (item) {
          var el = document.createElement('a');
          el.className = 'menu-item';
          el.setAttribute('role', 'menuitem');
          el.style.display = 'block';
          el.style.textDecoration = 'none';
          el.href = item.href;
          el.textContent = item.label;
          popover.appendChild(el);
        });

        var separator = document.createElement('div');
        separator.className = 'menu-separator';
        popover.appendChild(separator);

        var logout = document.createElement('button');
        logout.type = 'button';
        logout.className = 'menu-item';
        logout.setAttribute('role', 'menuitem');
        logout.textContent = 'Keluar';
        logout.addEventListener('click', function () {
          close();
          if (logoutTrigger) { logoutTrigger.click(); }
        });
        popover.appendChild(logout);

        return popover;
      }

      function position(popover) {
        popover.style.visibility = 'hidden';
        document.body.appendChild(popover);
        var rect = trigger.getBoundingClientRect();
        var top = rect.bottom + 6;
        var left = rect.right - popover.offsetWidth;
        if (top + popover.offsetHeight > window.innerHeight - 8) {
          top = rect.top - popover.offsetHeight - 6;
        }
        left = Math.max(8, Math.min(left, window.innerWidth - popover.offsetWidth - 8));
        popover.style.top = Math.round(top) + 'px';
        popover.style.left = Math.round(left) + 'px';
        popover.style.visibility = '';
      }

      function close() {
        if (menu) { menu.remove(); menu = null; }
        trigger.setAttribute('aria-expanded', 'false');
      }

      function open() {
        close();
        menu = buildMenu();
        position(menu);
        trigger.setAttribute('aria-expanded', 'true');
        menu.querySelector('.menu-item').focus();
      }

      var menu = null;

      if (trigger) {
        // Capture phase: ambil alih sebelum module JS demo mengikat menu hardcoded.
        trigger.addEventListener('click', function (event) {
          event.preventDefault();
          event.stopPropagation();
          event.stopImmediatePropagation();
          if (menu) { close(); } else { open(); }
        }, true);

        document.addEventListener('click', function (event) {
          if (menu && !menu.contains(event.target) && event.target !== trigger) { close(); }
        }, true);

        document.addEventListener('keydown', function (event) {
          if (event.key === 'Escape' && menu) { close(); }
        });

        window.addEventListener('resize', function () { if (menu) { close(); } });
        window.addEventListener('scroll', function () { if (menu) { close(); } }, true);
      }
    })();
  </script>
  <script>
    (function () {
      var trigger = document.getElementById('logoutTrigger');
      var modal = document.getElementById('logoutModal');
      var dialog = modal.querySelector('.modal-dialog');
      var cancel = modal.querySelector('[data-logout-dismiss]');

      function closeLogout() {
        modal.classList.remove('show');
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
        trigger.focus();
      }

      trigger.addEventListener('click', function () {
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
        requestAnimationFrame(function () { modal.classList.add('show'); });
        cancel.focus();
      });
      modal.querySelectorAll('[data-logout-dismiss]').forEach(function (button) {
        button.addEventListener('click', closeLogout);
      });
      modal.addEventListener('click', function (event) {
        if (event.target === modal) closeLogout();
      });
      dialog.addEventListener('keydown', function (event) {
        if (event.key !== 'Tab') return;
        var controls = dialog.querySelectorAll('button, a[href]');
        if (event.shiftKey && document.activeElement === controls[0]) {
          event.preventDefault();
          controls[controls.length - 1].focus();
        } else if (!event.shiftKey && document.activeElement === controls[controls.length - 1]) {
          event.preventDefault();
          controls[0].focus();
        }
      });
      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.style.display !== 'none') closeLogout();
      });
    })();
  </script>
</body>
</html>