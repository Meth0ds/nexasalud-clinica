import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';

import { CommunicationsApiService, PatientCommunicationRecord } from './communications-api';

describe('CommunicationsApiService', () => {
  let service: CommunicationsApiService;
  let http: HttpTestingController;

  const record: PatientCommunicationRecord = {
    id: 'communication-1', version: 1, patientId: 'patient-1', patientDisplayName: 'Paciente autorizado', patientInitials: 'PA',
    channel: 'portal', contentClass: 'administrative', status: 'draft', subject: 'Mensaje', preview: 'Contenido seguro',
    templateKey: null, scheduledAt: null, queuedAt: null, cancelledAt: null, authorDisplayName: 'Coordinación', createdAt: null,
  };

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    service = TestBed.inject(CommunicationsApiService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('lists the server-authorized communication timeline', async () => {
    const result = firstValueFrom(service.list());
    const request = http.expectOne((candidate) => candidate.url === '/api/v1/communications' && candidate.params.get('perPage') === '100');
    expect(request.request.method).toBe('GET');
    request.flush({ data: [record], meta: { total: 1 } });

    await expect(result).resolves.toEqual({ communications: [record], total: 1 });
  });

  it('uses different idempotency keys for create and state transitions', async () => {
    const created = firstValueFrom(service.create({
      patientId: 'patient-1', channel: 'portal', contentClass: 'administrative', mode: 'draft', subject: 'Mensaje', body: 'Contenido seguro',
    }));
    const create = http.expectOne('/api/v1/communications');
    expect(create.request.headers.has('Idempotency-Key')).toBe(true);
    create.flush({ data: record });
    await expect(created).resolves.toEqual(record);

    const queued = firstValueFrom(service.queue(record));
    const queue = http.expectOne('/api/v1/communications/communication-1/queue');
    expect(queue.request.headers.has('Idempotency-Key')).toBe(true);
    expect(queue.request.body).toEqual({ version: 1 });
    queue.flush({ data: { ...record, version: 2, status: 'queued' } });
    await expect(queued).resolves.toMatchObject({ status: 'queued', version: 2 });
  });
});
