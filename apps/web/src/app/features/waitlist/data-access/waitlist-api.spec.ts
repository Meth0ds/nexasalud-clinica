import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';

import { WaitlistApiService } from './waitlist-api';

describe('WaitlistApiService', () => {
  let service: WaitlistApiService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    service = TestBed.inject(WaitlistApiService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('loads the protected operational queue', async () => {
    const result = firstValueFrom(service.list());
    const request = http.expectOne(
      (candidate) =>
        candidate.url === '/api/v1/waitlist-entries' && candidate.params.get('perPage') === '100',
    );
    expect(request.request.method).toBe('GET');
    request.flush({ data: [], meta: { total: 0 } });

    await expect(result).resolves.toEqual({ entries: [], total: 0 });
  });

  it('creates and offers requests with separate idempotency keys', async () => {
    const created = firstValueFrom(
      service.create({
        clinicId: 'clinic-1',
        patientId: 'patient-1',
        appointmentTypeId: 'type-1',
        desiredFrom: '2026-07-27T09:00:00.000Z',
        desiredTo: '2026-07-27T12:00:00.000Z',
        priority: 3,
      }),
    );
    const createRequest = http.expectOne('/api/v1/waitlist-entries');
    expect(createRequest.request.headers.has('Idempotency-Key')).toBe(true);
    createRequest.flush({ data: { id: 'wait-1', version: 1, status: 'waiting' } });
    await expect(created).resolves.toMatchObject({ id: 'wait-1', status: 'waiting' });

    const offered = firstValueFrom(
      service.offer(
        {
          id: 'wait-1',
          version: 1,
          clinicId: 'clinic-1',
          appointmentTypeId: 'type-1',
          patientId: 'patient-1',
          patientDisplayName: 'Paciente autorizado',
          patientInitials: 'PA',
          requestedType: 'Revisión',
          preferredWindow: [null, null],
          preferredDays: [],
          priority: 3,
          status: 'waiting',
          waitingSince: null,
          offeredAt: null,
          offerExpiresAt: null,
          offeredSlot: null,
        },
        {
          startsAt: '2026-07-27T10:00:00.000Z',
          endsAt: '2026-07-27T10:30:00.000Z',
          mode: 'in-person',
          roomId: 'room-1',
        },
      ),
    );
    const offerRequest = http.expectOne('/api/v1/waitlist-entries/wait-1/offer');
    expect(offerRequest.request.headers.has('Idempotency-Key')).toBe(true);
    expect(offerRequest.request.body).toEqual({
      version: 1,
      expiresInMinutes: 20,
      startsAt: '2026-07-27T10:00:00.000Z',
      endsAt: '2026-07-27T10:30:00.000Z',
      mode: 'in-person',
      roomId: 'room-1',
    });
    offerRequest.flush({ data: { id: 'wait-1', version: 2, status: 'offered' } });

    await expect(offered).resolves.toMatchObject({ id: 'wait-1', version: 2, status: 'offered' });
  });
});
