import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of } from 'rxjs';

import { DashboardApiService } from '../../data-access/dashboard-api';
import { Dashboard } from './dashboard';

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
            load: () =>
              of({
                date: '2026-07-26',
                generatedAt: '2026-07-26T09:15:00.000Z',
                timezone: 'Europe/Madrid',
                clinic: { id: 'clinic-1', name: 'Centro Norte' },
                metrics: {
                  appointments: 1,
                  active: 1,
                  completed: 0,
                  cancelledOrNoShow: 0,
                  waitingList: 0,
                  occupancyPercent: 100,
                },
                dayAppointments: [
                  {
                    id: 'appointment-1',
                    startsAt: '2026-07-26T08:00:00.000Z',
                    endsAt: '2026-07-26T08:30:00.000Z',
                    patientDisplayName: 'Paciente Autorizado',
                    patientInitials: 'PA',
                    roomName: 'Consulta 1',
                    typeName: 'Seguimiento',
                    status: 'confirmed',
                  },
                ],
              }),
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
});
