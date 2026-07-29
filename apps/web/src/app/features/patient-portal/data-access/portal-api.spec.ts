import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';

import { PortalApiService, PortalAvailabilitySlot } from './portal-api';

describe('PortalApiService', () => {
  let service: PortalApiService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    service = TestBed.inject(PortalApiService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('loads and unwraps the patient overview', async () => {
    const result = firstValueFrom(service.getOverview());
    const request = http.expectOne('/api/v1/portal/overview');
    expect(request.request.method).toBe('GET');
    request.flush({
      data: {
        patient: { id: 'patient-1', displayName: 'Paciente', initials: 'P', relationship: 'self' },
        appointments: [],
        prescriptions: [],
        documents: [],
        invoices: [],
      },
    });
    await expect(result).resolves.toMatchObject({ patient: { id: 'patient-1' } });
  });

  it('loads secure messages through the patient-owned portal endpoint', async () => {
    const result = firstValueFrom(service.getMessages());
    const request = http.expectOne('/api/v1/portal/messages');
    expect(request.request.method).toBe('GET');
    request.flush({
      data: [
        {
          id: 'message-1',
          subject: 'Información disponible',
          body: 'Revise la información desde su portal seguro.',
          contentClass: 'clinical_link',
          authorDisplayName: 'Equipo asistencial',
          publishedAt: '2026-07-26T09:15:00.000Z',
        },
      ],
    });

    await expect(result).resolves.toMatchObject([
      { id: 'message-1', subject: 'Información disponible' },
    ]);
  });

  it('lists and creates patient-owned waitlist entries through portal endpoints', async () => {
    const listed = firstValueFrom(service.getWaitlistEntries());
    const listRequest = http.expectOne('/api/v1/portal/waitlist-entries');
    expect(listRequest.request.method).toBe('GET');
    listRequest.flush({ data: [] });
    await expect(listed).resolves.toEqual([]);

    const created = firstValueFrom(
      service.createWaitlistEntry({
        clinicId: 'clinic-1',
        appointmentTypeId: 'type-1',
        desiredFrom: '2026-07-30T08:00:00.000Z',
        desiredTo: '2026-08-20T17:00:00.000Z',
        preferredDays: [1, 3, 5],
        notes: 'Disponibilidad por la maÃ±ana.',
      }),
    );
    const createRequest = http.expectOne('/api/v1/portal/waitlist-entries');
    expect(createRequest.request.method).toBe('POST');
    expect(createRequest.request.headers.has('Idempotency-Key')).toBe(true);
    expect(createRequest.request.body).toEqual({
      clinicId: 'clinic-1',
      appointmentTypeId: 'type-1',
      desiredFrom: '2026-07-30T08:00:00.000Z',
      desiredTo: '2026-08-20T17:00:00.000Z',
      preferredDays: [1, 3, 5],
      notes: 'Disponibilidad por la maÃ±ana.',
    });
    createRequest.flush({ data: { id: 'waitlist-1', status: 'waiting', priority: 3 } });
    await expect(created).resolves.toMatchObject({ id: 'waitlist-1', priority: 3 });
  });

  it('sends availability filters as query parameters', async () => {
    const result = firstValueFrom(
      service.getAvailability({
        clinicId: 'clinic-1',
        appointmentTypeId: 'type-1',
        mode: 'video',
        days: 14,
      }),
    );
    const request = http.expectOne(
      (candidate) =>
        candidate.url === '/api/v1/portal/availability' &&
        candidate.params.get('clinicId') === 'clinic-1' &&
        candidate.params.get('appointmentTypeId') === 'type-1' &&
        candidate.params.get('mode') === 'video' &&
        candidate.params.get('days') === '14',
    );
    request.flush({
      data: [],
      meta: {
        timezone: 'Europe/Madrid',
        generatedAt: '2026-07-19T08:00:00+02:00',
        holdDurationSeconds: 300,
      },
    });
    await expect(result).resolves.toMatchObject({ timezone: 'Europe/Madrid', slots: [] });
  });

  it('creates an idempotent slot hold from the selected slot', async () => {
    const slot: PortalAvailabilitySlot = {
      clinicId: 'clinic-1',
      appointmentTypeId: 'type-1',
      practitionerId: 'user-1',
      practitionerName: 'Profesional',
      roomId: 'room-1',
      roomName: 'Consulta 1',
      startsAt: '2026-07-29T08:00:00Z',
      endsAt: '2026-07-29T08:30:00Z',
      mode: 'in-person',
    };
    const result = firstValueFrom(service.createSlotHold(slot));
    const request = http.expectOne('/api/v1/portal/slot-holds');
    expect(request.request.method).toBe('POST');
    expect(request.request.headers.has('Idempotency-Key')).toBe(true);
    expect(request.request.body).toEqual({
      clinicId: 'clinic-1',
      appointmentTypeId: 'type-1',
      practitionerId: 'user-1',
      roomId: 'room-1',
      startsAt: '2026-07-29T08:00:00Z',
      mode: 'in-person',
    });
    request.flush({
      data: {
        holdId: 'hold-1',
        startsAt: slot.startsAt,
        endsAt: slot.endsAt,
        expiresAt: '2026-07-29T07:05:00Z',
        version: 1,
      },
    });
    await expect(result).resolves.toMatchObject({ holdId: 'hold-1' });
  });

  it('uses the dedicated booking, cancellation and rescheduling endpoints', async () => {
    const booking = firstValueFrom(service.createBooking('hold-1', 'Seguimiento'));
    const bookingRequest = http.expectOne('/api/v1/portal/bookings');
    expect(bookingRequest.request.body).toEqual({ holdId: 'hold-1', reason: 'Seguimiento' });
    expect(bookingRequest.request.headers.has('Idempotency-Key')).toBe(true);
    bookingRequest.flush({ data: { id: 'appointment-1' } });
    await booking;

    const cancellation = firstValueFrom(
      service.cancelAppointment('appointment/unsafe', 3, 'Ya no es necesaria'),
    );
    const cancellationRequest = http.expectOne(
      '/api/v1/portal/appointments/appointment%2Funsafe/cancel',
    );
    expect(cancellationRequest.request.body).toEqual({ version: 3, reason: 'Ya no es necesaria' });
    cancellationRequest.flush({ data: { id: 'appointment/unsafe' } });
    await cancellation;

    const reschedule = firstValueFrom(
      service.rescheduleAppointment('appointment-1', 4, 'hold-2', 'Cambio de horario'),
    );
    const rescheduleRequest = http.expectOne(
      '/api/v1/portal/appointments/appointment-1/reschedule',
    );
    expect(rescheduleRequest.request.body).toEqual({
      version: 4,
      holdId: 'hold-2',
      reason: 'Cambio de horario',
    });
    rescheduleRequest.flush({ data: { id: 'appointment-1' } });
    await reschedule;
  });

  it('downloads a PDF as a private blob and keeps the server filename', async () => {
    const result = firstValueFrom(service.downloadDocument('document-1'));
    const request = http.expectOne('/api/v1/documents/document-1/download');
    expect(request.request.method).toBe('GET');
    expect(request.request.responseType).toBe('blob');
    request.flush(new Blob(['pdf'], { type: 'application/pdf' }), {
      headers: { 'Content-Disposition': `attachment; filename*=UTF-8''informe-seguro.pdf` },
    });
    await expect(result).resolves.toMatchObject({ filename: 'informe-seguro.pdf' });
  });
});
