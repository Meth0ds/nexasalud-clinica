import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';

import { InvoiceApiService, InvoiceRecord } from './invoice-api';

const invoice: InvoiceRecord = {
  id: 'invoice-1', version: 2, number: 'D-202607-0001', patientId: 'patient-1', patientDisplayName: 'Lucía Martín',
  status: 'issued', currency: 'EUR', subtotalMinor: 8000, taxMinor: 0, totalMinor: 8000, balanceMinor: 8000,
  issuedOn: '2026-07-26', dueOn: null, items: [],
};

describe('InvoiceApiService', () => {
  let service: InvoiceApiService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    service = TestBed.inject(InvoiceApiService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('unwraps the paginated invoice collection returned by Laravel', async () => {
    const result = firstValueFrom(service.list());
    const request = http.expectOne((candidate) => candidate.url === '/api/v1/invoices' && candidate.params.get('perPage') === '100');
    expect(request.request.method).toBe('GET');
    request.flush({ data: [invoice], meta: { total: 1 } });

    await expect(result).resolves.toMatchObject({ total: 1, invoices: [{ id: invoice.id }] });
  });

  it('uses versioned and idempotent mutation requests', async () => {
    const issue = firstValueFrom(service.issue(invoice));
    const issueRequest = http.expectOne(`/api/v1/invoices/${invoice.id}/issue`);
    expect(issueRequest.request.body).toEqual({ version: invoice.version });
    expect(issueRequest.request.headers.has('Idempotency-Key')).toBe(true);
    issueRequest.flush({ data: invoice });
    await expect(issue).resolves.toMatchObject({ id: invoice.id });

    const payment = firstValueFrom(service.recordPayment(invoice, { amountMinor: 1200, methodCode: 'insurer', externalReference: 'opaque-ref' }));
    const paymentRequest = http.expectOne(`/api/v1/invoices/${invoice.id}/payments`);
    expect(paymentRequest.request.body).toEqual({ version: invoice.version, amountMinor: 1200, methodCode: 'insurer', externalReference: 'opaque-ref' });
    expect(paymentRequest.request.headers.has('Idempotency-Key')).toBe(true);
    paymentRequest.flush({ data: invoice });
    await expect(payment).resolves.toMatchObject({ id: invoice.id });
  });

  it('generates then downloads private invoice documents as blobs', async () => {
    const result = firstValueFrom(service.downloadInvoice(invoice));
    const generationRequest = http.expectOne(`/api/v1/invoices/${invoice.id}/documents/invoice`);
    expect(generationRequest.request.headers.has('Idempotency-Key')).toBe(true);
    generationRequest.flush({ data: { downloadPath: '/api/v1/documents/document-1/download' } });

    const downloadRequest = http.expectOne('/api/v1/documents/document-1/download');
    expect(downloadRequest.request.responseType).toBe('blob');
    downloadRequest.flush(new Blob(['pdf'], { type: 'application/pdf' }), {
      headers: { 'Content-Disposition': `attachment; filename*=UTF-8''factura-segura.pdf` },
    });
    await expect(result).resolves.toMatchObject({ filename: 'factura-segura.pdf' });
  });
});
