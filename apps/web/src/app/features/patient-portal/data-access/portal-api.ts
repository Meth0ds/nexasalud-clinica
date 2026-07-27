import { HttpClient, HttpContext, HttpResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { SILENT_HTTP_ERROR } from '../../../core/http/http-context';
import { ApiClientService } from '../../../core/services/api-client';

export type PortalAppointmentStatus =
  'scheduled' | 'confirmed' | 'checked_in' | 'in_progress' | 'completed' | 'cancelled' | 'no_show';
export type PortalAppointmentMode = 'in-person' | 'in_person' | 'video' | 'phone';

export interface PortalPatient {
  readonly id: string;
  readonly displayName: string;
  readonly initials: string;
  readonly relationship: string;
}

export interface PortalAppointment {
  readonly id: string;
  readonly version: number;
  readonly startsAt: string;
  readonly endsAt: string;
  readonly status: PortalAppointmentStatus;
  readonly mode: PortalAppointmentMode;
  readonly typeName: string | null;
  readonly practitionerName: string | null;
  readonly clinicName: string | null;
  readonly roomName: string | null;
  readonly canReschedule: boolean;
  readonly canCancel: boolean;
}

export interface PortalPrescription {
  readonly id: string;
  readonly status: string;
  readonly validUntil: string | null;
  readonly prescriberName: string | null;
  readonly itemCount: number;
}

export interface PortalDocument {
  readonly id: string;
  readonly type: string;
  readonly generatedAt: string | null;
  readonly revision: number;
}

export interface PortalInvoice {
  readonly id: string;
  readonly number: string | null;
  readonly status: string;
  readonly currency: string;
  readonly totalMinor: number;
  readonly balanceMinor: number;
}

export interface PortalMessage {
  readonly id: string;
  readonly subject: string;
  readonly body: string;
  readonly contentClass: 'administrative' | 'clinical_link';
  readonly authorDisplayName: string | null;
  readonly publishedAt: string | null;
}

export interface PortalOverview {
  readonly patient: PortalPatient;
  readonly appointments: readonly PortalAppointment[];
  readonly prescriptions: readonly PortalPrescription[];
  readonly documents: readonly PortalDocument[];
  readonly invoices: readonly PortalInvoice[];
}

export interface PortalClinic {
  readonly id: string;
  readonly name: string;
  readonly timezone: string;
}

export interface PortalAppointmentType {
  readonly id: string;
  readonly name: string;
  readonly durationMinutes: number;
  readonly requiresRoom: boolean;
  readonly allowsVideo: boolean;
}

export interface PortalBookingCatalog {
  readonly clinics: readonly PortalClinic[];
  readonly appointmentTypes: readonly PortalAppointmentType[];
}

export interface PortalAvailabilityQuery {
  readonly clinicId: string;
  readonly appointmentTypeId: string;
  readonly practitionerId?: string;
  readonly from?: string;
  readonly days?: number;
  readonly mode?: 'in-person' | 'video' | 'phone';
}

export interface PortalAvailabilitySlot {
  readonly clinicId: string;
  readonly appointmentTypeId: string;
  readonly practitionerId: string;
  readonly practitionerName: string | null;
  readonly roomId: string | null;
  readonly roomName: string | null;
  readonly startsAt: string;
  readonly endsAt: string;
  readonly mode: 'in-person' | 'video' | 'phone';
}

export interface PortalAvailability {
  readonly slots: readonly PortalAvailabilitySlot[];
  readonly timezone: string;
  readonly generatedAt: string;
  readonly holdDurationSeconds: number;
}

export interface PortalSlotHold {
  readonly holdId: string;
  readonly startsAt: string;
  readonly endsAt: string;
  readonly expiresAt: string;
  readonly version: number;
}

export interface PortalDownloadedDocument {
  readonly blob: Blob;
  readonly filename: string;
}

interface ApiData<T> {
  readonly data: T;
}

interface AvailabilityResponse {
  readonly data: readonly PortalAvailabilitySlot[];
  readonly meta: {
    readonly timezone: string;
    readonly generatedAt: string;
    readonly holdDurationSeconds: number;
  };
}

interface ReferenceDataResponse {
  readonly data: PortalBookingCatalog;
}

@Injectable({ providedIn: 'root' })
export class PortalApiService {
  private readonly api = inject(ApiClientService);
  private readonly http = inject(HttpClient);

  getOverview(): Observable<PortalOverview> {
    return this.api
      .get<ApiData<PortalOverview>>('/api/v1/portal/overview', { silent: true })
      .pipe(map((response) => response.data));
  }

  getMessages(): Observable<readonly PortalMessage[]> {
    return this.api
      .get<ApiData<readonly PortalMessage[]>>('/api/v1/portal/messages', { silent: true })
      .pipe(map((response) => response.data));
  }

  getBookingCatalog(): Observable<PortalBookingCatalog> {
    return this.api
      .get<ReferenceDataResponse>('/api/v1/reference-data', { silent: true })
      .pipe(map((response) => response.data));
  }

  getAvailability(query: PortalAvailabilityQuery): Observable<PortalAvailability> {
    return this.api
      .get<AvailabilityResponse>('/api/v1/portal/availability', {
        params: { ...query },
        silent: true,
      })
      .pipe(
        map((response) => ({
          slots: response.data,
          timezone: response.meta.timezone,
          generatedAt: response.meta.generatedAt,
          holdDurationSeconds: response.meta.holdDurationSeconds,
        })),
      );
  }

  createSlotHold(slot: PortalAvailabilitySlot): Observable<PortalSlotHold> {
    return this.api
      .post<
        ApiData<PortalSlotHold>,
        {
          readonly clinicId: string;
          readonly appointmentTypeId: string;
          readonly practitionerId: string;
          readonly roomId?: string;
          readonly startsAt: string;
          readonly mode: 'in-person' | 'video' | 'phone';
        }
      >(
        '/api/v1/portal/slot-holds',
        {
          clinicId: slot.clinicId,
          appointmentTypeId: slot.appointmentTypeId,
          practitionerId: slot.practitionerId,
          ...(slot.roomId ? { roomId: slot.roomId } : {}),
          startsAt: slot.startsAt,
          mode: slot.mode,
        },
        { silent: true, idempotencyKey: createIdempotencyKey() },
      )
      .pipe(map((response) => response.data));
  }

  createBooking(holdId: string, reason: string): Observable<unknown> {
    return this.api.post(
      '/api/v1/portal/bookings',
      { holdId, reason },
      { silent: true, idempotencyKey: createIdempotencyKey() },
    );
  }

  cancelAppointment(appointmentId: string, version: number, reason: string): Observable<unknown> {
    return this.api.post(
      `/api/v1/portal/appointments/${encodeURIComponent(appointmentId)}/cancel`,
      { version, reason },
      { silent: true, idempotencyKey: createIdempotencyKey() },
    );
  }

  rescheduleAppointment(
    appointmentId: string,
    version: number,
    holdId: string,
    reason: string,
  ): Observable<unknown> {
    return this.api.post(
      `/api/v1/portal/appointments/${encodeURIComponent(appointmentId)}/reschedule`,
      { version, holdId, reason },
      { silent: true, idempotencyKey: createIdempotencyKey() },
    );
  }

  downloadDocument(documentId: string): Observable<PortalDownloadedDocument> {
    const context = new HttpContext().set(SILENT_HTTP_ERROR, true);
    return this.http
      .get(`/api/v1/documents/${encodeURIComponent(documentId)}/download`, {
        context,
        observe: 'response',
        responseType: 'blob',
        withCredentials: true,
      })
      .pipe(
        map((response: HttpResponse<Blob>) => ({
          blob: response.body ?? new Blob([], { type: 'application/pdf' }),
          filename: filenameFromDisposition(response.headers.get('content-disposition')),
        })),
      );
  }
}

function createIdempotencyKey(): string {
  try {
    return globalThis.crypto.randomUUID();
  } catch {
    return `portal-${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
  }
}

function filenameFromDisposition(value: string | null): string {
  const encoded = value?.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
  if (encoded) {
    try {
      return decodeURIComponent(encoded);
    } catch {
      return 'documento-clinico.pdf';
    }
  }

  const plain = value?.match(/filename="?([^";]+)"?/i)?.[1];
  return plain?.trim() || 'documento-clinico.pdf';
}
