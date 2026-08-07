import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of } from 'rxjs';

import {
  DashboardApiService,
  DashboardAppointment,
  DashboardData,
} from '../../data-access/dashboard-api';
import { Dashboard, countInProgress, countWaiting } from './dashboard';

function appointment(overrides: Partial<DashboardAppointment>): DashboardAppointment {
  return {
    id: 'appointment-1',
    startsAt: '2026-07-26T08:00:00.000Z',
    endsAt: '2026-07-26T08:30:00.000Z',
    patientDisplayName: 'Paciente Autorizado',
    patientInitials: 'PA',
    roomName: 'Consulta 1',
    typeName: 'Seguimiento',
    status: 'confirmed',
    ...overrides,
  };
}

function dashboardData(dayAppointments: readonly DashboardAppointment[]): DashboardData {
  return {
    date: '2026-07-26',
    generatedAt: '2026-07-26T09:15:00.000Z',
    timezone: 'Europe/Madrid',
    clinic: { id: 'clinic-1', name: 'Centro Norte' },
    metrics: {
      appointments: dayAppointments.length,
      active: dayAppointments.length,
      completed: 0,
      cancelledOrNoShow: 0,
      waitingList: 0,
      occupancyPercent: 100,
    },
    dayAppointments,
  };
}

describe('Dashboard active-state semantics', () => {
  const now = new Date('2026-07-26T08:10:00.000Z');

  it('counts an appointment genuinely in progress (started, within window)', () => {
    const appointments = [
      appointment({ status: 'in-progress' }),
    ];
    expect(countInProgress(appointments, now)).toBe(1);
    expect(countWaiting(appointments)).toBe(0);
  });

  it('never counts a future waiting appointment as in progress', () => {
    const appointments = [
      appointment({ status: 'waiting', startsAt: '2026-07-26T08:19:00.000Z' }),
    ];
    expect(countInProgress(appointments, now)).toBe(0);
    expect(countWaiting(appointments)).toBe(1);
  });

  it('does not count finished appointments as in progress', () => {
    const appointments = [
      appointment({ status: 'completed', startsAt: '2026-07-26T07:00:00.000Z' }),
    ];
    expect(countInProgress(appointments, now)).toBe(0);
  });

  it('ignores rows without valid dates', () => {
    const appointments = [
      appointment({ status: 'in-progress', startsAt: null, endsAt: null }),
      appointment({ id: 'broken', status: 'in-progress', startsAt: 'not-a-date' }),
    ];
    expect(countInProgress(appointments, now)).toBe(0);
  });

  it('does not report an in-progress row whose window ended long ago', () => {
    const appointments = [
      appointment({ status: 'in-progress', startsAt: '2026-07-26T06:00:00.000Z', endsAt: '2026-07-26T06:30:00.000Z' }),
    ];
    expect(countInProgress(appointments, now)).toBe(0);
  });

  it('counts checked-in rows as waiting regardless of start time', () => {
    const appointments = [
      appointment({ status: 'waiting', startsAt: null }),
      appointment({ id: 'b', status: 'waiting', startsAt: '2026-07-26T08:19:00.000Z' }),
    ];
    expect(countWaiting(appointments)).toBe(2);
  });
});

describe('Dashboard', () => {
  let component: Dashboard;
  let fixture: ComponentFixture<Dashboard>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [Dashboard],
      providers: [
        provideRouter([]),
        {
          provide: DashboardApiService,
          useValue: {
            load: () => of(dashboardData([appointment({})])),
          },
        },
      ],
    }).compileComponents();
    fixture = TestBed.createComponent(Dashboard);
    component = fixture.componentInstance;
    await fixture.whenStable();
  });

  it('renders the active-clinic data instead of fixture appointments', () => {
    expect(component).toBeTruthy();
    expect(fixture.nativeElement.textContent).toContain('Paciente Autorizado');
    expect(fixture.nativeElement.textContent).toContain('Actualizado a las');
    expect(fixture.nativeElement.textContent).toContain('11:15');
    expect(fixture.nativeElement.textContent).not.toContain('Lucas M.');
  });

  it('labels a future waiting appointment as “En espera”, never “En curso ahora”', async () => {
    const futureWaiting = appointment({
      status: 'waiting',
      startsAt: '2026-07-26T10:00:00.000Z',
      endsAt: '2026-07-26T10:30:00.000Z',
    });
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [Dashboard],
      providers: [
        provideRouter([]),
        {
          provide: DashboardApiService,
          useValue: { load: () => of(dashboardData([futureWaiting])) },
        },
      ],
    });
    fixture = TestBed.createComponent(Dashboard);
    component = fixture.componentInstance;
    await fixture.whenStable();

    const label = fixture.nativeElement.querySelector('.journey-hero__stats .journey-hero__zone-label');
    expect(label?.textContent?.trim()).toBe('En espera');
    expect(fixture.nativeElement.textContent).not.toContain('En curso ahora');
  });

  it('uses the exact “En curso ahora” label only when an appointment is in progress', async () => {
    const nowMs = Date.now();
    const running = appointment({
      status: 'in-progress',
      startsAt: new Date(nowMs - 5 * 60_000).toISOString(),
      endsAt: new Date(nowMs + 25 * 60_000).toISOString(),
    });
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [Dashboard],
      providers: [
        provideRouter([]),
        {
          provide: DashboardApiService,
          useValue: { load: () => of(dashboardData([running])) },
        },
      ],
    });
    fixture = TestBed.createComponent(Dashboard);
    component = fixture.componentInstance;
    await fixture.whenStable();

    const label = fixture.nativeElement.querySelector('.journey-hero__stats .journey-hero__zone-label');
    expect(label?.textContent?.trim()).toBe('En curso ahora');
  });

  it('shows capacity with a real denominator (“X de Y huecos”)', async () => {
    await fixture.whenStable();
    const text = fixture.nativeElement.textContent;
    expect(text).toContain('1 de 1 huecos reservados');
  });
});

