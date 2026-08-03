import { ErrorHandler, Injectable, inject } from '@angular/core';
import { Router } from '@angular/router';

import { toAppError } from './app-error';
import { ErrorNotificationService } from '../services/error-notification';

@Injectable()
export class GlobalErrorHandler implements ErrorHandler {
  private readonly notifications = inject(ErrorNotificationService);
  private readonly router = inject(Router);

  handleError(error: unknown): void {
    this.notifications.notify(error, 'runtime');
    const appError = toAppError(error);

    if (appError.status >= 500 && this.router.url !== '/indisponible') {
      void this.router.navigate(['/indisponible'], {
        state: { correlationId: appError.correlationId },
        replaceUrl: true,
      });
    }
  }
}
