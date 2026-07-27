import { ComponentFixture, TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { of } from 'rxjs';

import { ErrorNotificationService } from '../../../../core/services/error-notification';
import { SessionService } from '../../../../core/services/session';
import { PortalApiService } from '../../data-access/portal-api';
import { PatientPortal } from './patient-portal';

describe('PatientPortal', () => {
  let fixture: ComponentFixture<PatientPortal>;

  beforeEach(async () => {
    vi.stubGlobal('scrollTo', vi.fn());
    await TestBed.configureTestingModule({
      imports: [PatientPortal],
      providers: [
        {
          provide: PortalApiService,
          useValue: {
            getOverview: () =>
              of({
                patient: {
                  id: 'patient-1',
                  displayName: 'Paciente Autorizado',
                  initials: 'PA',
                  relationship: 'self',
                },
                appointments: [],
                prescriptions: [],
                documents: [],
                invoices: [],
              }),
            getBookingCatalog: () => of({ clinics: [], appointmentTypes: [] }),
            getMessages: () =>
              of([
                {
                  id: 'message-1',
                  subject: 'Información disponible',
                  body: 'Revise la información desde su portal seguro.',
                  contentClass: 'clinical_link' as const,
                  authorDisplayName: 'Equipo asistencial',
                  publishedAt: '2026-07-26T09:15:00.000Z',
                },
              ]),
          },
        },
        {
          provide: SessionService,
          useValue: {
            tenant: signal({ clinicId: 'clinic-1' }),
            assuranceLevel: signal('mfa'),
          },
        },
        { provide: ErrorNotificationService, useValue: { success: vi.fn(), error: vi.fn() } },
      ],
    }).compileComponents();
    fixture = TestBed.createComponent(PatientPortal);
    fixture.detectChanges();
    await fixture.whenStable();
  });

  it('renders patient-owned secure messages when the navigation item is selected', () => {
    const messageNavigation = Array.from(
      fixture.nativeElement.querySelectorAll('button') as NodeListOf<HTMLButtonElement>,
    ).find((button) => button.textContent?.includes('Mensajes seguros')) as HTMLButtonElement;

    messageNavigation.click();
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain('Información disponible');
    expect(fixture.nativeElement.textContent).toContain(
      'Revise la información desde su portal seguro.',
    );
    expect(fixture.nativeElement.textContent).not.toContain('Mensajes no disponibles');
  });
});
