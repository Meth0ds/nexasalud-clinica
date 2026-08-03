import { AppEventBus } from './app-events';

describe('AppEventBus', () => {
  it('publishes metadata-only events and exposes the latest event for diagnostics', () => {
    const bus = new AppEventBus();
    const received: string[] = [];

    bus.ofType('auth.login.started').subscribe((event) => received.push(event.returnTo));
    bus.emit({ type: 'auth.login.started', returnTo: '/inicio' });

    expect(received).toEqual(['/inicio']);
    expect(bus.latest()?.event).toEqual({ type: 'auth.login.started', returnTo: '/inicio' });
    expect(bus.latest()?.occurredAt).toEqual(expect.any(Number));
  });

  it('filters context-selection failures without exposing request payloads', () => {
    const bus = new AppEventBus();
    const received: unknown[] = [];

    bus.ofType('auth.context.selection.failed').subscribe((event) => received.push(event));
    bus.emit({
      type: 'auth.context.selection.failed',
      status: 403,
      code: 'CONTEXT_FORBIDDEN',
      correlationId: 'trace-123',
    });

    expect(received).toEqual([
      {
        type: 'auth.context.selection.failed',
        status: 403,
        code: 'CONTEXT_FORBIDDEN',
        correlationId: 'trace-123',
      },
    ]);
  });

  it('keeps UI notices semantic and free of user-facing copy', () => {
    const bus = new AppEventBus();
    const received: unknown[] = [];

    bus.ofType('ui.notice').subscribe((event) => received.push(event));
    bus.emit({ type: 'ui.notice', level: 'success', code: 'ui.operation.succeeded' });

    expect(received).toEqual([
      { type: 'ui.notice', level: 'success', code: 'ui.operation.succeeded' },
    ]);
  });

  it('keeps authentication failures diagnostic without carrying request data', () => {
    const bus = new AppEventBus();
    const received: unknown[] = [];

    bus.ofType('auth.logout.failed').subscribe((event) => received.push(event));
    bus.emit({
      type: 'auth.logout.failed',
      status: 503,
      code: 'HTTP_503',
      correlationId: 'trace-logout-1',
    });

    expect(received).toEqual([
      {
        type: 'auth.logout.failed',
        status: 503,
        code: 'HTTP_503',
        correlationId: 'trace-logout-1',
      },
    ]);
  });
});
