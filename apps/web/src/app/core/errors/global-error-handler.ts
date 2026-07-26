import { ErrorHandler, Injectable, inject } from '@angular/core';

import { ErrorNotificationService } from '../services/error-notification';

@Injectable()
export class GlobalErrorHandler implements ErrorHandler {
  private readonly notifications = inject(ErrorNotificationService);

  handleError(error: unknown): void {
    this.notifications.notify(error);
  }
}
