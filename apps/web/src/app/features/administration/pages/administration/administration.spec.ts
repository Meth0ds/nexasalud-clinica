import { ComponentFixture, TestBed } from '@angular/core/testing';
import { of } from 'rxjs';

import { AdministrationApiService } from '../../data-access/administration-api';
import { Administration } from './administration';

const administrationApi = {
  overview: () => of({
    users: { total: 2, active: 1, suspended: 1 },
    roles: 2,
    auditEventsLast24Hours: 7,
    mfaPolicy: { required: true, methods: ['totp'], minimumAcr: 2 },
  }),
  users: () => of([
    {
      membershipId: 'membership-1',
      userId: 'user-1',
      displayName: 'Dra. Lara Vega',
      emailHint: 'l•••@nexasalud.test',
      active: true,
      roles: [{ id: 'role-admin', code: 'admin', name: 'Administración clínica' }],
      lastLoginAt: '2026-07-26T09:26:00+00:00',
      sessionsRevokedAt: null,
      mfaPolicy: 'required' as const,
    },
    {
      membershipId: 'membership-2',
      userId: 'user-2',
      displayName: 'Nicolás Ortega',
      emailHint: 'n•••@nexasalud.test',
      active: false,
      roles: [{ id: 'role-reception', code: 'reception', name: 'Recepción' }],
      lastLoginAt: null,
      sessionsRevokedAt: '2026-07-25T09:26:00+00:00',
      mfaPolicy: 'required' as const,
    },
  ]),
  roles: () => of([
    {
      id: 'role-admin',
      code: 'admin',
      name: 'Administración clínica',
      permissions: ['admin.users.read', 'admin.users.write'],
      system: true,
      memberCount: 1,
    },
    {
      id: 'role-reception',
      code: 'reception',
      name: 'Recepción',
      permissions: ['appointments.read'],
      system: true,
      memberCount: 1,
    },
  ]),
  updateMembership: () => of({}),
  revokeSessions: () => of({ revoked: true as const, effectiveAt: '2026-07-26T12:00:00+00:00' }),
};

describe('Administration', () => {
  let fixture: ComponentFixture<Administration>;
  let root: HTMLElement;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [Administration],
      providers: [{ provide: AdministrationApiService, useValue: administrationApi }],
    }).compileComponents();
    fixture = TestBed.createComponent(Administration);
    root = fixture.nativeElement as HTMLElement;
    await fixture.whenStable();
  });

  const findButton = (label: string): HTMLButtonElement => {
    const button = [...root.querySelectorAll('button')].find((item) => item.textContent?.includes(label));
    if (!(button instanceof HTMLButtonElement)) {
      throw new Error(`No se encontró el botón: ${label}`);
    }
    return button;
  };

  it('loads protected administration data instead of synthetic records', () => {
    expect(root.textContent).toContain('API verificada');
    expect(root.textContent).toContain('Dra. Lara Vega');
    expect(root.textContent).toContain('l•••@nexasalud.test');
    expect(root.textContent).not.toContain('Entorno sintético');
  });

  it('opens a protected management action for a selected user', async () => {
    findButton('Gestionar').click();
    await fixture.whenStable();

    findButton('Suspender').click();
    await fixture.whenStable();

    expect(root.textContent).toContain('Cambio protegido');
    expect(root.textContent).toContain('Motivo de seguridad');
    expect(root.textContent).toContain('MFA completada en los últimos 15 minutos');
  });

  it('renders the server role matrix as read-only', async () => {
    findButton('Roles y permisos').click();
    await fixture.whenStable();

    expect(root.textContent).toContain('Matriz de privilegio mínimo');
    expect(root.textContent).toContain('Consultar usuarios');
    expect(root.textContent).toContain('Solo lectura');
  });
});
