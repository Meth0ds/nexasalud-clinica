import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';

import { Appointment, CreateAppointmentInput } from '../models/app.models';
import { AppointmentsService } from './appointments';

const appointment: Appointment = {
  id: 'appointment-1',
  version: 1,
  patientId: 'patient-1',
  patientDisplayName: 'Lucía Martín',
  patientInitials: 'LM',
  practitionerId: 'clinician-1',
  practitionerName: 'Dra. Vega',
  roomId: 'room-1',
  roomName: 'Consulta 1',
  startsAt: '2026-07-29T08:00:00Z',
  endsAt: '2026-07-29T08:30:00Z',
  timezone: 'Europe/Madrid',
  typeName: 'Consulta',
  appointmentTypeId: 'type-1',
  mode: 'in-person',
  status: 'pending',
};

const createInput: CreateAppointmentInput = {
  clinicId: 'clinic-1',
  patientId: appointment.patientId,
  practitionerId: appointment.practitionerId,
  roomId: appointment.roomId,
  startsAt: appointment.startsAt,
  endsAt: appointment.endsAt,
  timezone: appointment.timezone,
  appointmentTypeId: appointment.appointmentTypeId,
  mode: appointment.mode,
};

describe('AppointmentsService', () => {
  let service: AppointmentsService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    service = TestBed.inject(AppointmentsService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('unwraps the Laravel appointment resource on mutations', async () => {
    const result = firstValueFrom(service.create(createInput));
    const request = http.expectOne('/api/v1/appointments');
    expect(request.request.method).toBe('POST');
    expect(request.request.headers.has('Idempotency-Key')).toBe(true);
    request.flush({ data: appointment });

    await expect(result).resolves.toMatchObject({
      id: appointment.id,
      version: appointment.version,
    });
  });

  it('maps frontend lifecycle states to the protected idempotent transition endpoint', async () => {
    const result = firstValueFrom(service.transition(appointment.id, appointment.version, 'waiting'));
    const request = http.expectOne(`/api/v1/appointments/${appointment.id}/transition`);
    expect(request.request.method).toBe('POST');
    expect(request.request.headers.has('Idempotency-Key')).toBe(true);
    expect(request.request.body).toEqual({ version: 1, status: 'checked_in' });
    request.flush({ data: { ...appointment, version: 2, status: 'waiting' } });

    await expect(result).resolves.toMatchObject({ version: 2, status: 'waiting' });
  });

  it('generates and downloads appointment confirmations through protected document endpoints', async () => {
    const result = firstValueFrom(service.downloadAppointmentConfirmation(appointment.id));
    const generationRequest = http.expectOne(
      `/api/v1/appointments/${appointment.id}/documents/confirmation`,
    );
    expect(generationRequest.request.method).toBe('POST');
    expect(generationRequest.request.headers.has('Idempotency-Key')).toBe(true);
    generationRequest.flush({ data: { downloadPath: '/api/v1/documents/document-1/download' } });

    const downloadRequest = http.expectOne('/api/v1/documents/document-1/download');
    expect(downloadRequest.request.method).toBe('GET');
    expect(downloadRequest.request.responseType).toBe('blob');
    downloadRequest.flush(new Blob(['pdf'], { type: 'application/pdf' }), {
      headers: { 'Content-Disposition': `attachment; filename*=UTF-8''justificante-seguro.pdf` },
    });

    await expect(result).resolves.toMatchObject({ filename: 'justificante-seguro.pdf' });
  });
});
