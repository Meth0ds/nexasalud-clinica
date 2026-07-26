import { ComponentFixture, TestBed } from '@angular/core/testing';
import { of } from 'rxjs';

import { PatientsService } from '../../../../core/services/patients';
import { ReferenceDataService } from '../../../../core/services/reference-data';
import { PrescriptionApiService } from '../../data-access/prescription-api';
import { Prescriptions } from './prescriptions';

const prescriptionApi = {
  list: () => of({
    total: 1,
    prescriptions: [{
      id: 'rx-1', version: 2, patientId: 'patient-1', patientDisplayName: 'Lucas Martín', patientInitials: 'LM',
      prescriberId: 'clinician-1', prescriberName: 'Dra. Alicia Vega', status: 'pending_review' as const,
      clinicalIndication: 'Dolor agudo', validUntil: null, signedAt: null, sentAt: null, createdAt: '2026-07-26T10:00:00+00:00',
      safetyAlerts: [{ code: 'PEN', label: 'Penicilinas', criticality: 'high' }],
      items: [{ codeSystem: 'CIMA', code: '123', display: 'Paracetamol', doseAmount: 500, doseUnitCode: 'mg', routeCode: 'oral', frequencyCode: 'every-8-hours', durationValue: 7, durationUnit: 'day', quantity: 21, quantityUnitCode: 'tablet', instructions: null }],
    }],
  }),
  create: () => of({}),
  transition: () => of({
    id: 'rx-1', version: 3, patientId: 'patient-1', patientDisplayName: 'Lucas Martín', patientInitials: 'LM',
    prescriberId: 'clinician-1', prescriberName: 'Dra. Alicia Vega', status: 'signed' as const,
    clinicalIndication: 'Dolor agudo', validUntil: null, signedAt: '2026-07-26T10:05:00+00:00', sentAt: null, createdAt: '2026-07-26T10:00:00+00:00',
    safetyAlerts: [{ code: 'PEN', label: 'Penicilinas', criticality: 'high' }],
    items: [{ codeSystem: 'CIMA', code: '123', display: 'Paracetamol', doseAmount: 500, doseUnitCode: 'mg', routeCode: 'oral', frequencyCode: 'every-8-hours', durationValue: 7, durationUnit: 'day', quantity: 21, quantityUnitCode: 'tablet', instructions: null }],
  }),
  generateMedicationPlan: () => of({}),
  downloadDocument: () => of({}),
};

const patientsApi = {
  search: () => of({
    total: 1,
    items: [{ id: 'patient-1', displayName: 'Lucas Martín', initials: 'LM', ageRange: '30–39', phoneHint: '•••', emailHint: 'l•••@test', coverageLabel: 'Privado', lastVisitLabel: 'Sin visitas', consentStatus: 'current' as const, assignedPractitioner: 'Dra. Alicia Vega', alertLabels: [] }],
  }),
};

const referenceDataApi = {
  load: () => of({ clinics: [{ id: 'clinic-1', code: 'MAD', name: 'Madrid', timezone: 'Europe/Madrid' }], rooms: [], appointmentTypes: [], practitioners: [], insurers: [] }),
};

describe('Prescriptions', () => {
  let fixture: ComponentFixture<Prescriptions>;
  let root: HTMLElement;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [Prescriptions],
      providers: [
        { provide: PrescriptionApiService, useValue: prescriptionApi },
        { provide: PatientsService, useValue: patientsApi },
        { provide: ReferenceDataService, useValue: referenceDataApi },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(Prescriptions);
    root = fixture.nativeElement as HTMLElement;
    await fixture.whenStable();
  });

  it('renders server-backed prescriptions and safety alerts', () => {
    expect(root.textContent).toContain('Lucas Martín');
    expect(root.textContent).toContain('Pendiente de firma');
    expect(root.textContent).toContain('1 alerta(s) clínica(s)');
    expect(root.textContent).not.toContain('Dra. Elena Soler');
  });

  it('opens a draft builder backed by authorized patients and clinics', async () => {
    const button = [...root.querySelectorAll('button')].find((item) => item.textContent?.includes('Nueva prescripción'));
    button?.click();
    await fixture.whenStable();

    expect(root.textContent).toContain('Borrador protegido');
    expect(root.querySelector('mat-select')).not.toBeNull();
    expect(root.textContent).toContain('Sistema de código');
  });

  it('requires a documented clinical reason before signing an allergy warning', async () => {
    const button = [...root.querySelectorAll('button')].find((item) => item.textContent?.includes('Firmar con MFA'));
    button?.click();
    await fixture.whenStable();

    expect(root.textContent).toContain('Advertencia de alergia coincidente');
    expect(root.textContent).toContain('Motivo clínico de la excepción');
    expect(root.textContent).toContain('Mínimo 8 caracteres');
  });
});
