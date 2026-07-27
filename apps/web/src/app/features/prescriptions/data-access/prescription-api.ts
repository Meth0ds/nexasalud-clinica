import { HttpClient, HttpContext, HttpResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { SILENT_HTTP_ERROR } from '../../../core/http/http-context';
import { ApiClientService } from '../../../core/services/api-client';

export type PrescriptionStatus = 'draft' | 'pending_review' | 'signed' | 'sent' | 'cancelled';

export interface PrescriptionMedication {
  readonly id?: string;
  readonly codeSystem: string;
  readonly code: string;
  readonly display: string;
  readonly doseAmount: number;
  readonly doseUnitCode: string;
  readonly routeCode: string;
  readonly frequencyCode: string;
  readonly durationValue: number | null;
  readonly durationUnit: string | null;
  readonly quantity: number;
  readonly quantityUnitCode: string;
  readonly instructions: string | null;
}

export interface PrescriptionRecord {
  readonly id: string;
  readonly version: number;
  readonly patientId: string;
  readonly patientDisplayName: string;
  readonly patientInitials: string;
  readonly prescriberId: string;
  readonly prescriberName: string | null;
  readonly status: PrescriptionStatus;
  readonly clinicalIndication: string;
  readonly validUntil: string | null;
  readonly signedAt: string | null;
  readonly sentAt: string | null;
  readonly createdAt: string | null;
  readonly safetyAlerts: readonly {
    readonly code: string;
    readonly label: string;
    readonly criticality: string;
  }[];
  readonly items: readonly PrescriptionMedication[];
}

export interface CreatePrescriptionInput {
  readonly clinicId: string;
  readonly patientId: string;
  readonly clinicalIndication: string;
  readonly validUntil?: string;
  readonly items: readonly PrescriptionMedication[];
}

export interface GeneratedDocument {
  readonly id: string;
  readonly documentType: string;
  readonly status: string;
  readonly revision: number;
  readonly templateVersion: string;
  readonly generatedAt: string | null;
  readonly downloadPath: string;
}

export interface DownloadedDocument {
  readonly blob: Blob;
  readonly filename: string;
}

interface ApiData<T> {
  readonly data: T;
}

interface PrescriptionCollection {
  readonly data: readonly PrescriptionRecord[];
  readonly meta: {
    readonly current_page: number;
    readonly last_page: number;
    readonly per_page: number;
    readonly total: number;
  };
}

@Injectable({ providedIn: 'root' })
export class PrescriptionApiService {
  private readonly api = inject(ApiClientService);
  private readonly http = inject(HttpClient);

  list(): Observable<{
    readonly prescriptions: readonly PrescriptionRecord[];
    readonly total: number;
  }> {
    return this.api
      .get<PrescriptionCollection>('/api/v1/prescriptions', { params: { perPage: 100 } })
      .pipe(map((response) => ({ prescriptions: response.data, total: response.meta.total })));
  }

  create(input: CreatePrescriptionInput): Observable<PrescriptionRecord> {
    return this.api
      .post<ApiData<PrescriptionRecord>, CreatePrescriptionInput>('/api/v1/prescriptions', input, {
        idempotencyKey: idempotencyKey(),
      })
      .pipe(map((response) => response.data));
  }

  transition(
    id: string,
    version: number,
    status: PrescriptionStatus,
    options: { readonly reason?: string; readonly externalProviderReference?: string } = {},
  ): Observable<PrescriptionRecord> {
    return this.api
      .post<
        ApiData<PrescriptionRecord>,
        {
          readonly version: number;
          readonly status: PrescriptionStatus;
          readonly reason?: string;
          readonly externalProviderReference?: string;
        }
      >(
        `/api/v1/prescriptions/${encodeURIComponent(id)}/transition`,
        { version, status, ...options },
        { idempotencyKey: idempotencyKey() },
      )
      .pipe(map((response) => response.data));
  }

  generateMedicationPlan(id: string): Observable<GeneratedDocument> {
    return this.api
      .post<ApiData<GeneratedDocument>, Record<string, never>>(
        `/api/v1/prescriptions/${encodeURIComponent(id)}/documents/plan`,
        {},
        { idempotencyKey: idempotencyKey(), longRunning: true },
      )
      .pipe(map((response) => response.data));
  }

  downloadDocument(downloadPath: string): Observable<DownloadedDocument> {
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
    return `prescription-${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
  }
}

function filenameFromDisposition(value: string | null): string {
  const encoded = value?.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
  if (encoded) {
    try {
      return decodeURIComponent(encoded);
    } catch {
      return 'plan-medicacion.pdf';
    }
  }

  return value?.match(/filename="?([^";]+)"?/i)?.[1]?.trim() || 'plan-medicacion.pdf';
}
