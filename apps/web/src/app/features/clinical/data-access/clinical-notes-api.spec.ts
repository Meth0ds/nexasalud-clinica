import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';

import { ClinicalNotesApiService } from './clinical-notes-api';

describe('ClinicalNotesApiService', () => {
  let service: ClinicalNotesApiService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    service = TestBed.inject(ClinicalNotesApiService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('loads a patient-scoped timeline from the protected API', async () => {
    const result = firstValueFrom(service.list('patient/unsafe'));
    const request = http.expectOne((candidate) => candidate.url === '/api/v1/patients/patient%2Funsafe/clinical-notes' && candidate.params.get('perPage') === '50');
    expect(request.request.method).toBe('GET');
    request.flush({ data: [], meta: { total: 0 } });

    await expect(result).resolves.toEqual({ notes: [], total: 0 });
  });

  it('creates idempotent immutable drafts and uses the MFA signing endpoint', async () => {
    const draft = firstValueFrom(service.createDraft('patient-1', 'Evolución clínica relevante.'));
    const draftRequest = http.expectOne('/api/v1/patients/patient-1/clinical-notes');
    expect(draftRequest.request.headers.has('Idempotency-Key')).toBe(true);
    expect(draftRequest.request.body).toEqual({ content: 'Evolución clínica relevante.' });
    draftRequest.flush({ data: { id: 'note-1', status: 'draft' } });
    await expect(draft).resolves.toMatchObject({ id: 'note-1', status: 'draft' });

    const signed = firstValueFrom(service.sign('patient-1', 'Evolución clínica firmada y relevante.'));
    const signRequest = http.expectOne('/api/v1/patients/patient-1/clinical-notes/sign');
    expect(signRequest.request.headers.has('Idempotency-Key')).toBe(true);
    signRequest.flush({ data: { id: 'note-2', status: 'signed' } });
    await expect(signed).resolves.toMatchObject({ id: 'note-2', status: 'signed' });
  });
});
