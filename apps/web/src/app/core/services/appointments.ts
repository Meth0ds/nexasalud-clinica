import { HttpClient, HttpContext, HttpResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, filter, map, of, switchMap, take, throwError, timer } from 'rxjs';

import {
  Appointment,
  AppointmentListResponse,
  CreateAppointmentInput,
  PaginatedApiResponse,
} from '../models/app.models';
import { SILENT_HTTP_ERROR } from '../http/http-context';
import { ApiClientService } from './api-client';

interface ApiData<T> {
  readonly data: T;
}

interface GeneratedClinicalDocument {
  readonly id?: string;
  readonly status?: 'generating' | 'ready' | 'failed' | 'voided' | 'expired';
  readonly downloadPath?: string | null;
  readonly statusPath?: string;
  readonly pollAfterSeconds?: number;
}

export interface DownloadedClinicalDocument {
  readonly blob: Blob;
  readonly filename: string;
}

@Injectable({ providedIn: 'root' })
export class AppointmentsService {
  private readonly api = inject(ApiClientService);
  private readonly http = inject(HttpClient);

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
    return this.api
      .post<ApiData<Appointment>, CreateAppointmentInput>('/api/v1/appointments', input, {
        idempotencyKey: idempotencyKey(),
      })
      .pipe(map((response) => response.data));
  }

  update(
    id: string,
    version: number,
    changes: Partial<CreateAppointmentInput> & { readonly status?: Appointment['status'] },
  ): Observable<Appointment> {
    return this.api
      .patch<ApiData<Appointment>, typeof changes & { readonly version: number }>(
        `/api/v1/appointments/${encodeURIComponent(id)}`,
        {
          ...changes,
          version,
        },
      )
      .pipe(map((response) => response.data));
  }

  cancel(id: string, version: number, reason: string): Observable<Appointment> {
    return this.api
      .post<ApiData<Appointment>, { version: number; reason: string }>(
        `/api/v1/appointments/${encodeURIComponent(id)}/cancel`,
        { version, reason },
        { idempotencyKey: idempotencyKey() },
      )
      .pipe(map((response) => response.data));
  }

  transition(
    id: string,
    version: number,
    status: Exclude<Appointment['status'], 'pending'>,
    reason?: string,
  ): Observable<Appointment> {
    return this.api
      .post<ApiData<Appointment>, { version: number; status: string; reason?: string }>(
        `/api/v1/appointments/${encodeURIComponent(id)}/transition`,
        {
          version,
          status: serverStatus(status),
          ...(reason ? { reason } : {}),
        },
        { idempotencyKey: idempotencyKey() },
      )
      .pipe(map((response) => response.data));
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
    return this.api
      .post<ApiData<Appointment>, typeof input>(
        `/api/v1/appointments/${encodeURIComponent(id)}/reschedule`,
        input,
        { idempotencyKey: idempotencyKey() },
      )
      .pipe(map((response) => response.data));
  }

  downloadAppointmentConfirmation(id: string): Observable<DownloadedClinicalDocument> {
    return this.api
      .post<ApiData<GeneratedClinicalDocument>, Record<string, never>>(
        `/api/v1/appointments/${encodeURIComponent(id)}/documents/confirmation`,
        {},
        { idempotencyKey: idempotencyKey(), longRunning: true },
      )
      .pipe(switchMap((response) => this.waitForReady(response.data)));
  }

  private waitForReady(document: GeneratedClinicalDocument): Observable<DownloadedClinicalDocument> {
    if (document.status === undefined || document.status === 'ready') {
      return document.downloadPath
        ? this.downloadDocument(document.downloadPath)
        : throwError(() => new Error('El documento clínico no tiene una descarga disponible.'));
    }

    const statusPath = document.statusPath ?? (document.id ? `/api/v1/documents/${encodeURIComponent(document.id)}` : null);
    if (!statusPath) {
      return throwError(() => new Error('El documento clínico no tiene un estado consultable.'));
    }

    const interval = Math.max(1, document.pollAfterSeconds ?? 2) * 1000;

    return timer(0, interval).pipe(
      switchMap(() => this.api.get<ApiData<GeneratedClinicalDocument>>(statusPath, { silent: true })),
      map((response) => response.data),
      filter((current) => current.status === 'ready' || current.status === 'failed' || current.status === 'voided' || current.status === 'expired'),
      take(1),
      switchMap((current) => current.status === 'ready' && current.downloadPath
        ? this.downloadDocument(current.downloadPath)
        : throwError(() => new Error('No se pudo generar el documento clínico.'))),
    );
  }

  private downloadDocument(downloadPath: string): Observable<DownloadedClinicalDocument> {
    const context = new HttpContext().set(SILENT_HTTP_ERROR, true);
    return this.http
      .get(downloadPath, {
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

function idempotencyKey(): string {
  try {
    return globalThis.crypto.randomUUID();
  } catch {
    return `appointment-${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
  }
}

function serverStatus(status: Exclude<Appointment['status'], 'pending'>): string {
  return {
    confirmed: 'confirmed',
    waiting: 'checked_in',
    'in-progress': 'in_progress',
    completed: 'completed',
    cancelled: 'cancelled',
    'no-show': 'no_show',
  }[status];
}

function filenameFromDisposition(value: string | null): string {
  const encoded = value?.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
  if (encoded) {
    try {
      return decodeURIComponent(encoded);
    } catch {
      return 'justificante-cita.pdf';
    }
  }

  return value?.match(/filename="?([^";]+)"?/i)?.[1]?.trim() || 'justificante-cita.pdf';
}
