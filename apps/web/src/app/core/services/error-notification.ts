import { Injectable, inject } from '@angular/core';
import { MatSnackBar } from '@angular/material/snack-bar';

import { AppError, toAppError } from '../errors/app-error';

@Injectable({ providedIn: 'root' })
export class ErrorNotificationService {
  private readonly snackBar = inject(MatSnackBar);

  notify(error: unknown): AppError {
    const appError = toAppError(error);
    const reference = appError.correlationId ? ` · Ref. ${appError.correlationId}` : '';
    this.snackBar.open(`${appError.message}${reference}`, 'Cerrar', {
      duration: appError.retryable ? 9000 : 7000,
      horizontalPosition: 'end',
      verticalPosition: 'top',
      panelClass: ['nexa-snackbar', 'nexa-snackbar--error'],
      politeness: 'assertive',
    });
    return appError;
  }

  success(message: string): void {
    this.snackBar.open(message, 'Cerrar', {
      duration: 4500,
      horizontalPosition: 'end',
      verticalPosition: 'top',
      panelClass: ['nexa-snackbar', 'nexa-snackbar--success'],
      politeness: 'polite',
    });
  }

  error(message: string): void {
    this.snackBar.open(message, 'Cerrar', {
      duration: 7000,
      horizontalPosition: 'end',
      verticalPosition: 'top',
      panelClass: ['nexa-snackbar', 'nexa-snackbar--error'],
      politeness: 'assertive',
    });
  }
}
