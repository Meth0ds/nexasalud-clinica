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
                prescriptions: [
                  {
                    id: 'prescription-1',
                    status: 'signed',
                    validUntil: '2026-12-31',
                    prescriberName: 'Profesional Autorizado',
                    itemCount: 1,
                  },
                ],
                documents: [],
                invoices: [
                  {
                    id: 'invoice-1',
                    number: 'SIM-2026-001',
                    status: 'issued',
                    currency: 'EUR',
                    totalMinor: 7500,
                    balanceMinor: 7500,
                  },
                ],
                demoCapabilities: {
                  medicationReview: true,
                  invoicePreview: true,
                },
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
            getWaitlistEntries: () => of([]),
            createWaitlistEntry: () =>
              of({
                id: 'waitlist-1',
                version: 1,
                patientId: 'patient-1',
                requestedType: 'Consulta',
                preferredWindow: [null, null],
                preferredDays: [],
                priority: 3,
                status: 'waiting',
                waitingSince: null,
                offeredAt: null,
                offerExpiresAt: null,
              }),
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

  it('opens an operational patient-owned waitlist section from navigation', () => {
    const waitlistNavigation = Array.from(
      fixture.nativeElement.querySelectorAll('button') as NodeListOf<HTMLButtonElement>,
    ).find((button) => button.textContent?.includes('Lista de espera')) as HTMLButtonElement;

    waitlistNavigation.click();
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain('Solicitar plaza en lista de espera');
    expect(fixture.nativeElement.textContent).not.toContain(
      'El backend del portal todavÃ­a no ofrece esta operaciÃ³n',
    );
  });

  it('does not expose unavailable actions as clickable portal controls', () => {
    const unavailableControls = fixture.nativeElement.querySelectorAll('[aria-disabled="true"]');

    expect(unavailableControls).toHaveLength(0);
    expect(fixture.nativeElement.textContent).not.toContain('Privacidad: no disponible');
  });

  it('connects the mobile menu control with its navigation landmark', () => {
    const menuButton = fixture.nativeElement.querySelector(
      '#portal-menu-toggle',
    ) as HTMLButtonElement;
    const navigation = fixture.nativeElement.querySelector('#portal-navigation') as HTMLElement;

    expect(menuButton).toBeTruthy();
    expect(menuButton.getAttribute('aria-controls')).toBe('portal-navigation');
    expect(menuButton.getAttribute('aria-expanded')).toBe('false');
    expect(navigation).toBeTruthy();
    expect(navigation.getAttribute('aria-label')).toBeTruthy();
  });

  it('exposes clearly labelled, enabled simulation actions for medication and invoice previews', () => {
    const healthNavigation = Array.from(
      fixture.nativeElement.querySelectorAll('button') as NodeListOf<HTMLButtonElement>,
    ).find((button) => button.textContent?.includes('documentos')) as HTMLButtonElement;

    healthNavigation.click();
    fixture.detectChanges();

    const medicationAction = Array.from(
      fixture.nativeElement.querySelectorAll('button') as NodeListOf<HTMLButtonElement>,
    ).find((button) => button.textContent?.includes('simulada')) as HTMLButtonElement;
    expect(medicationAction).toBeTruthy();
    expect(medicationAction.disabled).toBe(false);
    expect(fixture.nativeElement.textContent).toContain('sin efecto');

    const billingNavigation = Array.from(
      fixture.nativeElement.querySelectorAll('button') as NodeListOf<HTMLButtonElement>,
    ).find((button) => button.textContent?.includes('Facturas')) as HTMLButtonElement;
    billingNavigation.click();
    fixture.detectChanges();

    const invoiceAction = Array.from(
      fixture.nativeElement.querySelectorAll('button') as NodeListOf<HTMLButtonElement>,
    ).find((button) => button.textContent?.includes('Generar PDF simulado')) as HTMLButtonElement;
    expect(invoiceAction).toBeTruthy();
    expect(invoiceAction.disabled).toBe(false);
    expect(fixture.nativeElement.textContent).toContain('validez fiscal');
  });

  it('uses a patient-friendly label for the generated simulated invoice preview', () => {
    const portal = fixture.componentInstance as unknown as {
      documentLabel(document: { type: string }): string;
    };

    expect(portal.documentLabel({ type: 'demo_invoice_preview' })).toBe(
      'Vista previa de factura simulada',
    );
  });
});
