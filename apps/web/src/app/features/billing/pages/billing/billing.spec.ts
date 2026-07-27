import { ComponentFixture, TestBed } from '@angular/core/testing';
import { of } from 'rxjs';

import { Patient } from '../../../../core/models/app.models';
import { PatientsService } from '../../../../core/services/patients';
import { SessionService } from '../../../../core/services/session';
import { InvoiceApiService, InvoiceRecord } from '../../data-access/invoice-api';
import { Billing } from './billing';

const invoice: InvoiceRecord = {
  id: 'invoice-1',
  version: 1,
  number: 'D-202607-0001',
  patientId: 'patient-1',
  patientDisplayName: 'Lucía Martín',
  status: 'issued',
  currency: 'EUR',
  subtotalMinor: 8000,
  taxMinor: 0,
  totalMinor: 8000,
  balanceMinor: 8000,
  issuedOn: '2026-07-26',
  dueOn: null,
  items: [
    {
      serviceCode: null,
      description: 'Consulta de seguimiento',
      quantity: '1',
      unitPriceMinor: 8000,
      taxMinor: 0,
      totalMinor: 8000,
    },
  ],
};

const patient: Patient = {
  id: 'patient-1',
  displayName: 'Lucía Martín',
  initials: 'LM',
  ageRange: '30–39',
  phoneHint: '•••',
  emailHint: 'l•••@test',
  coverageLabel: 'Privado',
  lastVisitLabel: 'Sin visitas',
  consentStatus: 'current',
  assignedPractitioner: 'Dra. Vega',
  alertLabels: [],
};

let createCalls = 0;
const invoiceApi = {
  list: () => of({ invoices: [invoice], total: 1 }),
  create: () => {
    createCalls += 1;
    return of({
      ...invoice,
      id: 'invoice-2',
      number: 'D-202607-0002',
      status: 'draft' as const,
      issuedOn: null,
    });
  },
  issue: () => of(invoice),
  recordPayment: () => of({ ...invoice, status: 'paid' as const, balanceMinor: 0 }),
  downloadInvoice: () => of({ blob: new Blob(['pdf']), filename: 'factura.pdf' }),
};

describe('Billing', () => {
  let fixture: ComponentFixture<Billing>;
  let root: HTMLElement;

  beforeEach(async () => {
    createCalls = 0;
    await TestBed.configureTestingModule({
      imports: [Billing],
      providers: [
        { provide: InvoiceApiService, useValue: invoiceApi },
        {
          provide: PatientsService,
          useValue: { search: () => of({ items: [patient], total: 1 }) },
        },
        { provide: SessionService, useValue: { tenant: () => ({ clinicId: 'clinic-1' }) } },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(Billing);
    root = fixture.nativeElement as HTMLElement;
    await fixture.whenStable();
  });

  it('renders invoices returned by the protected backend instead of static billing data', () => {
    expect(root.textContent).toContain('D-202607-0001');
    expect(root.textContent).toContain('Lucía Martín');
    expect(root.textContent).not.toContain('F-2026-0184');
  });

  it('creates a server-backed draft after validating the authorized patient and billable line', async () => {
    const button = [...root.querySelectorAll('button')].find((item) =>
      item.textContent?.includes('Nueva factura'),
    );
    button?.click();
    await fixture.whenStable();

    const component = fixture.componentInstance as unknown as {
      invoiceForm: { setValue(value: unknown): void };
    };
    component.invoiceForm.setValue({
      patientId: patient.id,
      description: 'Consulta de seguimiento',
      quantity: '1',
      unitPriceEuro: '80',
      taxRatePercent: '0',
      dueOn: '',
      notes: '',
    });
    const submit = [...root.querySelectorAll('button')].find((item) =>
      item.textContent?.includes('Crear borrador'),
    );
    submit?.click();
    await fixture.whenStable();

    expect(createCalls).toBe(1);
    expect(root.textContent).toContain('D-202607-0002');
  });
});
