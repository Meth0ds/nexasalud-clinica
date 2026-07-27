import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of } from 'rxjs';

import { ErrorNotificationService } from '../../../../core/services/error-notification';
import { PatientsService } from '../../../../core/services/patients';
import { Patients } from './patients';

describe('Patients', () => {
  let fixture: ComponentFixture<Patients>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [Patients],
      providers: [
        {
          provide: PatientsService,
          useValue: {
            search: () =>
              of({
                items: [
                  {
                    id: 'patient-1',
                    displayName: 'Marina Torres',
                    initials: 'MT',
                    ageRange: '35 a\u00f1os',
                    phoneHint: '*** 42 18',
                    emailHint: 'm***@example.test',
                    coverageLabel: 'Particular',
                    lastVisitLabel: '12 jul. 2026',
                    nextAppointmentLabel: '29 jul. 2026',
                    consentStatus: 'current',
                    assignedPractitioner: 'Dr. Navarro',
                    alertLabels: [],
                  },
                ],
                total: 1,
              }),
            create: vi.fn(),
          },
        },
        { provide: ErrorNotificationService, useValue: { success: vi.fn(), error: vi.fn() } },
        provideRouter([]),
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(Patients);
    await fixture.whenStable();
    fixture.detectChanges();
  });

  it('renders authorized patient data without fabricated filters or pagination', () => {
    const text = fixture.nativeElement.textContent;

    expect(text).toContain('Marina Torres');
    expect(text).not.toContain('Con cita hoy');
    expect(text).not.toContain('Seguimiento 7');
    expect(text).not.toContain('Consentimientos 2');
    expect(text).not.toContain('Anterior');
    expect(text).not.toContain('Siguiente');
  });
});
