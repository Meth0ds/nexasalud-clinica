import { HttpErrorResponse } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';
import { vi } from 'vitest';

import { ErrorNotificationService } from '../services/error-notification';
import { GlobalErrorHandler } from './global-error-handler';

describe('GlobalErrorHandler', () => {
  const router = { url: '/inicio', navigate: vi.fn().mockResolvedValue(true) };
  const notifications = { notify: vi.fn() };

  beforeEach(() => {
    router.url = '/inicio';
    router.navigate.mockClear();
    notifications.notify.mockClear();
    TestBed.configureTestingModule({
      providers: [
        GlobalErrorHandler,
        { provide: Router, useValue: router },
        { provide: ErrorNotificationService, useValue: notifications },
      ],
    });
  });

  it('keeps the notification and routes unexpected server failures to a safe recovery page', () => {
    TestBed.inject(GlobalErrorHandler).handleError(new HttpErrorResponse({ status: 500 }));

    expect(notifications.notify).toHaveBeenCalledTimes(1);
    expect(router.navigate).toHaveBeenCalledWith(['/indisponible'], {
      state: { correlationId: undefined },
      replaceUrl: true,
    });
  });

  it('does not redirect a handled validation failure away from its form', () => {
    TestBed.inject(GlobalErrorHandler).handleError(new HttpErrorResponse({ status: 422 }));

    expect(notifications.notify).toHaveBeenCalledTimes(1);
    expect(router.navigate).not.toHaveBeenCalled();
  });
});
