import { ComponentFixture, TestBed } from '@angular/core/testing';
import { of } from 'rxjs';

import { AuditApiService } from '../../data-access/audit-api';
import { Audit } from './audit';

const auditApi = {
  list: () =>
    of({
      total: 2,
      events: [
        {
          id: 'audit-1',
          occurredAt: '2026-07-26T10:20:00+00:00',
          actorDisplayName: 'Dra. Alicia Vega',
          actionLabel: 'appointment.updated',
          resourceType: 'Appointment',
          resourceIdHint: '••E2A1',
          outcome: 'allowed' as const,
          traceId: 'trace-0001',
        },
        {
          id: 'audit-2',
          occurredAt: '2026-07-26T10:21:00+00:00',
          actorDisplayName: 'Sistema',
          actionLabel: 'authorization.blocked',
          resourceType: 'Prescription',
          resourceIdHint: '••F4B7',
          outcome: 'blocked' as const,
          traceId: 'trace-0002',
        },
      ],
    }),
};

describe('Audit', () => {
  let fixture: ComponentFixture<Audit>;
  let root: HTMLElement;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [Audit],
      providers: [{ provide: AuditApiService, useValue: auditApi }],
    }).compileComponents();
    fixture = TestBed.createComponent(Audit);
    root = fixture.nativeElement as HTMLElement;
    await fixture.whenStable();
  });

  it('renders server-backed minimized audit evidence', () => {
    expect(root.textContent).toContain('appointment.updated');
    expect(root.textContent).toContain('••E2A1');
    expect(root.textContent).toContain('Registro de auditoría de solo inserción');
    expect(root.textContent).not.toContain('Dra. Elena Soler');
  });

  it('filters loaded events without exposing additional data', async () => {
    const search = root.querySelector<HTMLInputElement>('input[type="search"]');
    expect(search).toBeTruthy();
    search!.value = 'blocked';
    search!.dispatchEvent(new Event('input', { bubbles: true }));
    await fixture.whenStable();

    expect(root.querySelectorAll('.audit-event')).toHaveLength(1);
    expect(root.textContent).toContain('Bloqueado');
    expect(root.textContent).not.toContain('appointment.updated');
  });
});
