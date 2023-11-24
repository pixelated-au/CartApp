<?php

use App\Http\Controllers\AdminCheckForUpdateController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminRunSoftwareUpdateController;
use App\Http\Controllers\AvailableShiftsController;
use App\Http\Controllers\GetAdminUsersController;
use App\Http\Controllers\GetAvailableUsersForShiftController;
use App\Http\Controllers\GetReportTagsController;
use App\Http\Controllers\LocationsController;
use App\Http\Controllers\MissingReportsForUserController;
use App\Http\Controllers\MoveUserToNewShiftController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\ReportTagsController;
use App\Http\Controllers\ReportTagsSortOrderController;
use App\Http\Controllers\ResendWelcomeEmailController;
use App\Http\Controllers\SaveShiftReportController;
use App\Http\Controllers\SetUserAvailabilityController;
use App\Http\Controllers\SetUserPasswordController;
use App\Http\Controllers\ShiftsController;
use App\Http\Controllers\ShowUserAvailabilityController;
use App\Http\Controllers\ToggleShiftReservationController;
use App\Http\Controllers\ToggleUserOntoShiftReservationController;
use App\Http\Controllers\UpdateAllowedSettingsUsersController;
use App\Http\Controllers\UpdateGeneralSettingsController;
use App\Http\Controllers\UpdateUserRegularAvailabilityController;
use App\Http\Controllers\UpdateUserVacationsController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\UsersImportController;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

/*Route::get('/', static function () {
    return Inertia::render('Welcome', [
        'canLogin'       => Route::has('login'),
        'canRegister'    => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion'     => PHP_VERSION,
    ]);
});*/

Route::get('/', static fn() => Inertia::render('Auth/Login'));

Route::get('/set-password/{user}/{hashedEmail}', [SetUserPasswordController::class, 'show'])->name('set.password.show');
Route::post('/set-password', [SetUserPasswordController::class, 'update'])->name('set.password.update');

//Route::get('/mail', static fn() => new App\Mail\UserAccountCreated(App\Models\User::find(1)));

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified', 'user-enabled'])->group(function () {
    Route::get('/', static fn() => Inertia::render('Dashboard'))->name('dashboard');
    Route::get('/shifts/{canViewHistorical?}', AvailableShiftsController::class);
    Route::get('/outstanding-reports', MissingReportsForUserController::class);
    Route::post('/reserve-shift', ToggleShiftReservationController::class);
    Route::post('/save-report', SaveShiftReportController::class)->name('save.report');
    Route::get('/get-report-tags', GetReportTagsController::class);
    Route::post('/set-viewed-availability', fn (Request $request) => $request->user()->availability->touch())->name('set.viewed-availability');

    Route::get('/user/availability', ShowUserAvailabilityController::class)->name('user.availability');
    Route::put('/user/availability', UpdateUserRegularAvailabilityController::class)->name('update.user.availability');
    Route::put('/user/vacations', UpdateUserVacationsController::class)->name('update.user.vacations');

    Route::prefix('admin')
        ->middleware('is-admin')
        ->group(static function () {
            Route::get('/', AdminDashboardController::class)->name('admin.dashboard');

            Route::get('/users/import', [UsersImportController::class, 'show'])->name('admin.users.import.show');
            Route::post('/users/import', [UsersImportController::class, 'import'])->name('admin.users.import.import');

            Route::resource('/users', UsersController::class)->names([
                'index'   => 'admin.users.index',
                'create'  => 'admin.users.create',
                'store'   => 'admin.users.store',
                'show'    => 'admin.users.show',
                'edit'    => 'admin.users.edit',
                'update'  => 'admin.users.update',
                'destroy' => 'admin.users.destroy',
            ]);

            Route::resource('/locations', LocationsController::class)->names([
                'index'   => 'admin.locations.index',
                'create'  => 'admin.locations.create',
                'store'   => 'admin.locations.store',
                'show'    => 'admin.locations.show',
                'edit'    => 'admin.locations.edit',
                'update'  => 'admin.locations.update',
                'destroy' => 'admin.locations.destroy',
            ]);

            Route::resource('/reports', ReportsController::class)->names([
                'index' => 'admin.reports.index',
                'show'  => 'admin.reports.show',
            ])->only(['index', 'show']);

            Route::resource('/report-tags', ReportTagsController::class)->parameter('report-tags', 'tag')->names([
                'index'   => 'admin.report-tags.index',
                'store'   => 'admin.report-tags.store',
                'update'  => 'admin.report-tags.update',
                'destroy' => 'admin.report-tags.destroy',
            ]);
            Route::put('/report-tag-sort-order', ReportTagsSortOrderController::class);
            Route::post('/resend-welcome-email', ResendWelcomeEmailController::class)->name('admin.resend-welcome-email');

            Route::resource('shifts', ShiftsController::class)->only(['destroy'])->names([
                'destroy' => 'admin.shifts.destroy',
            ]);

            Route::put('/move-volunteer-to-shift', MoveUserToNewShiftController::class);

            Route::get('/available-users-for-shift/{shift}', GetAvailableUsersForShiftController::class);
            Route::match(['put', 'delete'], '/toggle-shift-for-user', ToggleUserOntoShiftReservationController::class);

            Route::get('/settings', static fn(GeneralSettings $settings) => Inertia::render('Admin/Settings/Show', [
                'settings' => $settings->toArray(),
            ]))->name('admin.settings');

            Route::put('/general-settings', UpdateGeneralSettingsController::class)->name('admin.general-settings.update');
            Route::put('/allowed-settings-users', UpdateAllowedSettingsUsersController::class)->name('admin.allowed-settings-users.update');
            Route::get('/admin-users', GetAdminUsersController::class)->name('admin.admin-users.get');
            Route::get('/check-update', AdminCheckForUpdateController::class)->name('admin.check-update');
            Route::post('/do-update', AdminRunSoftwareUpdateController::class)->name('admin.do-update');

            //Route::get('/', static fn() => Inertia::render('Admin/Dashboard'))->name('admin.dashboard');

            //Route::resource('locations', 'Admin\LocationsController');
            //Route::resource('users', 'Admin\UsersController');
            //Route::resource('roles', 'Admin\RolesController');
            //Route::resource('permissions', 'Admin\PermissionsController');
            //Route::resource('reports', 'Admin\ReportsController');
            //Route::resource('reservations', 'Admin\ReservationsController');
        });
});
