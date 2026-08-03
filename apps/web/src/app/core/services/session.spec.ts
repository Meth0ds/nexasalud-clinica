import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';

import { AppEventBus } from '../events/app-events';
import { securityHttpInterceptor } from '../http/security.interceptor';
import { ErrorNotificationService } from './error-notification';
import { SessionService } from './session';

describe('SessionService', () => {
  let service: SessionService;
  let http: HttpTestingController;
  let events: AppEventBus;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([securityHttpInterceptor])),
        provideHttpClientTesting(),
        { provide: ErrorNotificationService, useValue: { notify: vi.fn() } },
        { provide: Router, useValue: { navigate: vi.fn() } },
      ],
    });
    service = TestBed.inject(SessionService);
    http = TestBed.inject(HttpTestingController);
    events = TestBed.inject(AppEventBus);
  });

  afterEach(() => http.verify());

  it('emits a metadata-only event when session loading fails', () => {
    const received: unknown[] = [];
    events.ofType('auth.session.load.failed').subscribe((event) => received.push(event));

    service.ensureLoaded().subscribe();
    const request = http.expectOne('/api/session');
    request.flush({ title: 'not exposed' }, { status: 418, statusText: 'Unavailable' });

    expect(service.state()).toMatchObject({ status: 'error' });
    expect(received).toEqual([
      {
        type: 'auth.session.load.failed',
        status: 418,
        code: 'HTTP_418',
      },
    ]);
  });

  it('records logout failure diagnostics and still clears the local session', () => {
    const received: unknown[] = [];
    events.ofType('auth.logout.failed').subscribe((event) => received.push(event));

    service.logout();
    const request = http.expectOne('/auth/logout');
    request.flush({ detail: 'not exposed' }, { status: 503, statusText: 'Unavailable' });

    expect(service.state()).toEqual({ status: 'anonymous' });
    expect(received).toEqual([
      {
        type: 'auth.logout.failed',
        status: 503,
        code: 'HTTP_503',
      },
    ]);
  });
});
