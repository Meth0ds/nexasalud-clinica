<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AdministrationController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\ClinicalNoteController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\PatientCommunicationController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\PortalController;
use App\Http\Controllers\Api\V1\PrescriptionController;
use App\Http\Controllers\Api\V1\ReferenceDataController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\WaitlistController;
use App\Http\Middleware\EnforceAbsoluteSessionLifetime;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware([
        'auth:web',
        EnforceAbsoluteSessionLifetime::class,
        EnsureUserIsActive::class,
        'tenant.context',
        'throttle:clinical-api',
    ])
    ->group(function (): void {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::get('/reference-data', ReferenceDataController::class)->name('reference-data');
        Route::get('/team', TeamController::class)->name('team');
        Route::get('/analytics', AnalyticsController::class)->name('analytics');
        Route::get('/audit-events', AuditController::class)->name('audit-events.index');

        Route::get('/settings', [SettingsController::class, 'show'])->name('settings.show');
        Route::put('/settings', [SettingsController::class, 'update'])
            ->middleware('throttle:clinical-mutation')
            ->name('settings.update');

        Route::apiResource('appointments', AppointmentController::class)
            ->middlewareFor(['store', 'update', 'destroy'], 'throttle:clinical-mutation')
            ->middlewareFor('store', 'idempotent');
        Route::post('/appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule'])
            ->middleware(['throttle:clinical-mutation', 'idempotent'])
            ->name('appointments.reschedule');
        Route::post('/appointments/{appointment}/transition', [AppointmentController::class, 'transition'])
            ->middleware('throttle:clinical-mutation')
            ->name('appointments.transition');
        Route::post('/appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])
            ->middleware(['throttle:clinical-mutation', 'idempotent'])
            ->name('appointments.cancel');

        Route::apiResource('patients', PatientController::class)
            ->middlewareFor(['store', 'update', 'destroy'], 'throttle:clinical-mutation')
            ->middlewareFor('store', 'idempotent');
        Route::get('/patients/{patient}/clinical-notes', [ClinicalNoteController::class, 'index'])
            ->name('patients.clinical-notes.index');
        Route::post('/patients/{patient}/clinical-notes', [ClinicalNoteController::class, 'store'])
            ->middleware(['throttle:clinical-mutation', 'idempotent'])
            ->name('patients.clinical-notes.store');
        Route::post('/patients/{patient}/clinical-notes/sign', [ClinicalNoteController::class, 'sign'])
            ->middleware(['throttle:clinical-mutation', 'idempotent'])
            ->name('patients.clinical-notes.sign');

        Route::apiResource('waitlist-entries', WaitlistController::class)
            ->parameters(['waitlist-entries' => 'waitlistEntry'])
            ->middlewareFor(['store', 'update', 'destroy'], 'throttle:clinical-mutation')
            ->middlewareFor('store', 'idempotent');
        Route::post('/waitlist-entries/{waitlistEntry}/offer', [WaitlistController::class, 'offer'])
            ->middleware(['throttle:clinical-mutation', 'idempotent'])
            ->name('waitlist-entries.offer');

        Route::get('/communications', [PatientCommunicationController::class, 'index'])
            ->name('communications.index');
        Route::post('/communications', [PatientCommunicationController::class, 'store'])
            ->middleware(['throttle:clinical-mutation', 'idempotent'])
            ->name('communications.store');
        Route::get('/communications/{communication}', [PatientCommunicationController::class, 'show'])
            ->name('communications.show');
        Route::post('/communications/{communication}/queue', [PatientCommunicationController::class, 'queue'])
            ->middleware(['throttle:clinical-mutation', 'idempotent'])
            ->name('communications.queue');
        Route::post('/communications/{communication}/cancel', [PatientCommunicationController::class, 'cancel'])
            ->middleware(['throttle:clinical-mutation', 'idempotent'])
            ->name('communications.cancel');

        Route::apiResource('prescriptions', PrescriptionController::class)
            ->middlewareFor(['store', 'update', 'destroy'], 'throttle:clinical-mutation')
            ->middlewareFor('store', 'idempotent');
        Route::post('/prescriptions/{prescription}/transition', [PrescriptionController::class, 'transition'])
            ->middleware(['throttle:clinical-mutation', 'idempotent'])
            ->name('prescriptions.transition');

        Route::apiResource('invoices', InvoiceController::class)
            ->middlewareFor(['store', 'update', 'destroy'], 'throttle:clinical-mutation')
            ->middlewareFor('store', 'idempotent');
        Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])
            ->middleware(['throttle:clinical-mutation', 'idempotent'])
            ->name('invoices.issue');
        Route::post('/invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment'])
            ->middleware(['throttle:clinical-mutation', 'idempotent'])
            ->name('invoices.payments.store');

        Route::post('/appointments/{appointment}/documents/confirmation', [DocumentController::class, 'generateAppointmentConfirmation'])
            ->middleware(['throttle:clinical-pdf', 'idempotent'])
            ->name('appointments.documents.confirmation');
        Route::post('/prescriptions/{prescription}/documents/plan', [DocumentController::class, 'generatePrescriptionPlan'])
            ->middleware(['throttle:clinical-pdf', 'idempotent'])
            ->name('prescriptions.documents.plan');
        Route::post('/invoices/{invoice}/documents/invoice', [DocumentController::class, 'generateInvoice'])
            ->middleware(['throttle:clinical-pdf', 'idempotent'])
            ->name('invoices.documents.invoice');
        Route::get('/documents/{document}/download', [DocumentController::class, 'download'])
            ->middleware('throttle:clinical-pdf')
            ->name('documents.download');

        Route::prefix('administration')->name('administration.')->group(function (): void {
            Route::get('/overview', [AdministrationController::class, 'overview'])->name('overview');
            Route::get('/users', [AdministrationController::class, 'users'])->name('users.index');
            Route::patch('/users/{user}', [AdministrationController::class, 'updateMembership'])
                ->middleware('throttle:clinical-mutation')
                ->name('users.update');
            Route::post('/users/{user}/revoke-sessions', [AdministrationController::class, 'revokeSessions'])
                ->middleware('throttle:clinical-mutation')
                ->name('users.revoke-sessions');
            Route::get('/roles', [AdministrationController::class, 'roles'])->name('roles.index');
        });

        Route::prefix('portal')->name('portal.')->group(function (): void {
            Route::get('/overview', [PortalController::class, 'overview'])->name('overview');
            Route::get('/availability', [PortalController::class, 'availability'])->name('availability');
            Route::post('/slot-holds', [PortalController::class, 'hold'])
                ->middleware(['throttle:clinical-mutation', 'idempotent'])
                ->name('slot-holds.store');
            Route::post('/bookings', [PortalController::class, 'book'])
                ->middleware(['throttle:clinical-mutation', 'idempotent'])
                ->name('bookings.store');
            Route::post('/appointments/{appointment}/cancel', [PortalController::class, 'cancel'])
                ->middleware(['throttle:clinical-mutation', 'idempotent'])
                ->name('appointments.cancel');
            Route::post('/appointments/{appointment}/reschedule', [PortalController::class, 'reschedule'])
                ->middleware(['throttle:clinical-mutation', 'idempotent'])
                ->name('appointments.reschedule');
        });
    });
