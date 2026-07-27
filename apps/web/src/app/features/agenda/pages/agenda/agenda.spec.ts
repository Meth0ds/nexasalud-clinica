import { ComponentFixture, TestBed } from '@angular/core/testing';
import { of } from 'rxjs';

import { AppointmentsService } from '../../../../core/services/appointments';
import { ErrorNotificationService } from '../../../../core/services/error-notification';
import { PatientsService } from '../../../../core/services/patients';
import { ReferenceDataService } from '../../../../core/services/reference-data';
import { SessionService } from '../../../../core/services/session';
import { Agenda } from './agenda';

describe('Agenda', () => {
  let fixture: ComponentFixture<Agenda>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [Agenda],
      providers: [
        {
          provide: AppointmentsService,
          useValue: {
            list: () =>
              of({
                items: [
                  {
                    id: 'appointment-authorized',
                    version: 1,
                    patientId: 'patient-1',
                    patientDisplayName: 'Paciente autorizado',
                    patientInitials: 'PA',
                    practitionerId: 'practitioner-1',
                    practitionerName: 'Profesional autorizado',
                    roomId: 'room-1',
                    roomName: 'Consulta 1',
                    startsAt: '2026-07-26T09:30:00.000Z',
                    endsAt: '2026-07-26T10:00:00.000Z',
                    timezone: 'Europe/Madrid',
                    typeName: 'Seguimiento',
                    appointmentTypeId: 'type-1',
                    mode: 'in-person',
                    status: 'confirmed',
                  },
                ],
                total: 1,
                timezone: 'Europe/Madrid',
                range: { from: '2026-07-26T00:00:00.000Z', to: '2026-07-27T00:00:00.000Z' },
              }),
          },
        },
        { provide: PatientsService, useValue: { search: () => of({ items: [] }) } },
        {
          provide: ReferenceDataService,
          useValue: {
            load: () =>
              of({
                clinics: [
                  {
                    id: 'clinic-1',
                    code: 'CENTRO',
                    name: 'Centro autorizado',
                    timezone: 'Europe/Madrid',
                  },
                ],
                practitioners: [
                  {
                    id: 'practitioner-1',
                    displayName: 'Profesional autorizado',
                    roles: ['Medicina'],
                  },
                ],
                rooms: [{ id: 'room-1', clinicId: 'clinic-1', name: 'Consulta 1', capacity: 1 }],
                appointmentTypes: [
                  {
                    id: 'type-1',
                    code: 'FOLLOW_UP',
                    name: 'Seguimiento',
                    durationMinutes: 30,
                    requiresRoom: true,
                    allowsVideo: false,
                  },
                ],
                insurers: [],
              }),
          },
        },
        { provide: SessionService, useValue: { tenant: () => ({ clinicId: 'clinic-1' }) } },
        {
          provide: ErrorNotificationService,
          useValue: { success: () => undefined, error: () => undefined },
        },
      ],
    }).compileComponents();
    fixture = TestBed.createComponent(Agenda);
    fixture.detectChanges();
    await fixture.whenStable();
  });

  it('renders authorized appointments without fictional dates or availability', () => {
    const text = fixture.nativeElement.textContent;

    expect(text).toContain('Paciente autorizado');
    expect(text).toContain('Europe/Madrid');
    expect(text).not.toContain('Siguiente hueco');
    expect(text).not.toContain('Domingo, 19 de julio');
  });
});
