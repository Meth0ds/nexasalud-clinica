import { LiveAnnouncer } from '@angular/cdk/a11y';
import { Injectable, inject } from '@angular/core';
import { MatSnackBar } from '@angular/material/snack-bar';

import { AppError, toAppError } from '../errors/app-error';
import { AppEventBus } from '../events/app-events';

@Injectable({ providedIn: 'root' })
export class ErrorNotificationService {
  private readonly liveAnnouncer = inject(LiveAnnouncer);
  private readonly snackBar = inject(MatSnackBar);
  private readonly events = inject(AppEventBus);
  private lastErrorKey = '';
  private lastErrorAt = 0;

  notify(error: unknown, source: 'http' | 'runtime' | 'manual' = 'manual'): AppError {
    const appError = toAppError(error);
    const errorKey = `${appError.status}:${appError.code}:${appError.correlationId ?? ''}`;
    const now = Date.now();
    this.events.emit({
      type: 'app.error.presented',
      source,
      status: appError.status,
      code: appError.code,
      correlationId: appError.correlationId,
      retryable: appError.retryable,
    });

    if (errorKey === this.lastErrorKey && now - this.lastErrorAt < 1800) {
      return appError;
    }

    this.lastErrorKey = errorKey;
    this.lastErrorAt = now;
    const reference = appError.correlationId ? ` · Ref. ${appError.correlationId}` : '';
    this.snackBar.open(`${appError.message}${reference}`, 'Cerrar', {
      duration: appError.retryable ? 9000 : 7000,
      horizontalPosition: 'end',
      verticalPosition: 'top',
      panelClass: ['nexa-snackbar', 'nexa-snackbar--error'],
      politeness: 'assertive',
    });
    this.announce(`${appError.message}${reference}`, 'assertive');
    return appError;
  }

  success(message: string, code = 'ui.operation.succeeded'): void {
    this.events.emit({ type: 'ui.notice', level: 'success', code });
    this.snackBar.open(message, 'Cerrar', {
      duration: 4500,
      horizontalPosition: 'end',
      verticalPosition: 'top',
      panelClass: ['nexa-snackbar', 'nexa-snackbar--success'],
      politeness: 'polite',
    });
    this.announce(message, 'polite');
  }

  error(message: string, code = 'ui.operation.rejected'): void {
    this.events.emit({ type: 'ui.notice', level: 'warning', code });
    this.snackBar.open(message, 'Cerrar', {
      duration: 7000,
      horizontalPosition: 'end',
      verticalPosition: 'top',
      panelClass: ['nexa-snackbar', 'nexa-snackbar--error'],
      politeness: 'assertive',
    });
    this.announce(message, 'assertive');
  }

  info(message: string, code = 'ui.information'): void {
    this.events.emit({ type: 'ui.notice', level: 'info', code });
    this.snackBar.open(message, 'Cerrar', {
      duration: 5500,
      horizontalPosition: 'end',
      verticalPosition: 'top',
      panelClass: ['nexa-snackbar', 'nexa-snackbar--info'],
      politeness: 'polite',
    });
    this.announce(message, 'polite');
  }

  private announce(message: string, politeness: 'polite' | 'assertive'): void {
    void this.liveAnnouncer.announce(message, politeness).catch(() => undefined);
  }
}
