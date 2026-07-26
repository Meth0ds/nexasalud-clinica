import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import {
  Appointment,
  AppointmentListResponse,
  CreateAppointmentInput,
  PaginatedApiResponse,
} from '../models/app.models';
import { ApiClientService } from './api-client';

@Injectable({ providedIn: 'root' })
export class AppointmentsService {
  private readonly api = inject(ApiClientService);

  list(from: string, to: string): Observable<AppointmentListResponse> {
    return this.api
      .get<PaginatedApiResponse<Appointment>>('/api/v1/appointments', {
        params: { from, to },
      })
      .pipe(
        map((response) => ({
          items: response.data,
          total: response.meta.total,
          timezone: response.data[0]?.timezone ?? 'Europe/Madrid',
          range: { from, to },
        })),
      );
  }

  create(input: CreateAppointmentInput): Observable<Appointment> {
    return this.api.post<Appointment, CreateAppointmentInput>('/api/v1/appointments', input, {
      idempotencyKey: globalThis.crypto.randomUUID(),
    });
  }

  update(
    id: string,
    version: number,
    changes: Partial<CreateAppointmentInput> & { readonly status?: Appointment['status'] },
  ): Observable<Appointment> {
    return this.api.patch<Appointment, typeof changes & { readonly version: number }>(`/api/v1/appointments/${encodeURIComponent(id)}`, {
      ...changes,
      version,
    });
  }

  cancel(id: string, version: number, reason: string): Observable<Appointment> {
    return this.api.post<Appointment, { version: number; reason: string }>(
      `/api/v1/appointments/${encodeURIComponent(id)}/cancel`,
      { version, reason },
      { idempotencyKey: globalThis.crypto.randomUUID() },
    );
  }

  reschedule(
    id: string,
    input: {
      readonly version: number;
      readonly startsAt: string;
      readonly endsAt: string;
      readonly timezone: string;
      readonly roomId?: string;
      readonly practitionerId?: string;
      readonly reason: string;
    },
  ): Observable<Appointment> {
    return this.api.post<Appointment, typeof input>(
      `/api/v1/appointments/${encodeURIComponent(id)}/reschedule`,
      input,
      { idempotencyKey: globalThis.crypto.randomUUID() },
    );
  }
}
