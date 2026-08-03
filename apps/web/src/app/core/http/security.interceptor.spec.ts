import { safePath } from './security.interceptor';

describe('safePath', () => {
  it('removes query strings and redacts resource identifiers from telemetry paths', () => {
    expect(
      safePath(
        'https://nexasalud.test/api/v1/patients/123e4567-e89b-12d3-a456-426614174000?full=true',
      ),
    ).toBe('/api/v1/patients/:id');
    expect(safePath('/api/v1/appointments/opaque-resource-id/documents/abc')).toBe(
      '/api/v1/appointments/:id/documents/:id',
    );
  });

  it('keeps collection routes useful for diagnostics', () => {
    expect(safePath('/api/v1/patients')).toBe('/api/v1/patients');
    expect(safePath('/health')).toBe('/health');
  });

  it('redacts identifiers for communication and waitlist resources', () => {
    expect(safePath('/api/v1/communications/communication-1/queue')).toBe(
      '/api/v1/communications/:id/queue',
    );
    expect(safePath('/api/v1/waitlist-entries/wait-1/offer')).toBe(
      '/api/v1/waitlist-entries/:id/offer',
    );
  });
});
