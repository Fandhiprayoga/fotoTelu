<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */

// ---------------------------------------------------------------
// Auth Routes (Shield)
// ---------------------------------------------------------------
service('auth')->routes($routes);

// ---------------------------------------------------------------
// Public Routes
// ---------------------------------------------------------------
$routes->get('/', 'AuthController::login');
$routes->get('maintenance', static function () {
    return view('errors/maintenance');
});

// ---------------------------------------------------------------
// Protected Routes (require login)
// ---------------------------------------------------------------
$routes->group('', ['filter' => 'session'], static function ($routes) {

    $routes->get('photos/search', 'PhotoSearchController::index', ['filter' => 'activepermission:photos.search']);
    $routes->post('photos/search', 'PhotoSearchController::search', ['filter' => 'activepermission:photos.search']);
    $routes->post('photos/selfie', 'PhotoSearchController::saveSelfie', ['filter' => 'activepermission:selfies.manage']);
    $routes->post('photos/selfie/delete', 'PhotoSearchController::deleteSelfie', ['filter' => 'activepermission:selfies.manage']);
    $routes->get('photo-files/(:segment)/(:segment)', 'PhotoFileController::show/$1/$2');

    $routes->group('admin/photos', ['filter' => 'activepermission:photos.manage'], static function ($routes) {
        $routes->get('/', 'PhotoManagerController::index');
        $routes->get('album/(:num)', 'PhotoManagerController::album/$1');
        $routes->post('albums', 'PhotoManagerController::createAlbum');
        $routes->post('albums/(:num)/update', 'PhotoManagerController::updateAlbum/$1');
        $routes->post('albums/(:num)/delete', 'PhotoManagerController::deleteAlbum/$1');
        $routes->post('folders', 'PhotoManagerController::createFolder');
        $routes->post('folders/(:num)/update', 'PhotoManagerController::updateFolder/$1');
        $routes->post('folders/(:num)/delete', 'PhotoManagerController::deleteFolder/$1');
        $routes->post('upload', 'PhotoManagerController::upload');
        $routes->post('items/(:num)/update', 'PhotoManagerController::updatePhoto/$1');
        $routes->post('items/(:num)/delete', 'PhotoManagerController::deletePhoto/$1');
        $routes->post('items/(:num)/retry', 'PhotoManagerController::retryPhoto/$1');
    });

    // Dashboard
    $routes->get('dashboard', 'DashboardController::index');

    // Switch Active Group
    $routes->post('switch-group', 'GroupSwitchController::switch');

    // Profile
    $routes->get('profile', 'ProfileController::index');
    $routes->post('profile/update', 'ProfileController::update');

    // ---------------------------------------------------------------
    // Admin Routes (require admin.access permission)
    // ---------------------------------------------------------------
    $routes->group('admin', ['filter' => 'permission:admin.access'], static function ($routes) {

        // User Management
        $routes->group('users', static function ($routes) {
            $routes->get('/', 'UserController::index', ['filter' => 'permission:users.list']);
            $routes->get('create', 'UserController::create', ['filter' => 'permission:users.create']);
            $routes->post('store', 'UserController::store', ['filter' => 'permission:users.create']);
            $routes->get('edit/(:num)', 'UserController::edit/$1', ['filter' => 'permission:users.edit']);
            $routes->post('update/(:num)', 'UserController::update/$1', ['filter' => 'permission:users.edit']);
            $routes->post('delete/(:num)', 'UserController::delete/$1', ['filter' => 'permission:users.delete']);
            $routes->post('assign-role/(:num)', 'UserController::assignRole/$1', ['filter' => 'permission:users.manage-roles']);
        });

        // Role Management (superadmin only)
        $routes->group('roles', ['filter' => 'role:superadmin'], static function ($routes) {
            $routes->get('/', 'RoleController::index');
            $routes->get('permissions', 'RoleController::permissions');
        });

        // Settings
        $routes->group('settings', ['filter' => 'permission:admin.settings'], static function ($routes) {
            $routes->get('/', 'SettingController::index');
            $routes->post('update/general', 'SettingController::updateGeneral');
            $routes->post('update/branding', 'SettingController::updateBranding');
            $routes->post('update/appearance', 'SettingController::updateAppearance');
            $routes->post('update/auth', 'SettingController::updateAuth');
            $routes->post('update/mail', 'SettingController::updateMail');
            $routes->post('test-email', 'SettingController::testEmail');
            $routes->post('reset', 'SettingController::resetDefaults');
        });
    });
});
