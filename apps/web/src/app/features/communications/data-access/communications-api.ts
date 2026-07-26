import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { ApiClientService } from '../../../core/services/api-client';

export type CommunicationChannel = 'portal' | 'sms' | 'email';
export type CommunicationContentClass = 'administrative' | 'clinical_link';
export type CommunicationStatus = 'draft' | 'queued' | 'cancelled';
export type ExternalTemplateKey = 'appointment_reminder' | 'appointment_change' | 'billing_notification' | 'generic_notification';

export interface PatientCommunicationRecord {
  readonly id: string;
  readonly version: number;
  readonly patientId: string;
  readonly patientDisplayName: string;
  readonly patientInitials: string;
  readonly channel: CommunicationChannel;
  readonly contentClass: CommunicationContentClass;
  readonly status: CommunicationStatus;
  readonly subject: string | null;
  readonly preview: string;
  readonly templateKey: ExternalTemplateKey | null;
  readonly scheduledAt: string | null;
  readonly queuedAt: string | null;
  readonly cancelledAt: string | null;
  readonly authorDisplayName: string | null;
  readonly createdAt: string | null;
}

export interface CreatePatientCommunicationInput {
  readonly patientId: string;
  readonly channel: CommunicationChannel;
  readonly contentClass: CommunicationContentClass;
  readonly mode: 'draft' | 'queue';
  readonly subject?: string;
  readonly body?: string;
  readonly templateKey?: ExternalTemplateKey;
}

interface ApiData<T> {
  readonly data: T;
}

interface CommunicationCollection {
  readonly data: readonly PatientCommunicationRecord[];
  readonly meta: { readonly total: number };
}

@Injectable({ providedIn: 'root' })
export class CommunicationsApiService {
  private readonly api = inject(ApiClientService);

  list(): Observable<{ readonly communications: readonly PatientCommunicationRecord[]; readonly total: number }> {
    return this.api
      .get<CommunicationCollection>('/api/v1/communications', { params: { perPage: 100 } })
      .pipe(map((response) => ({ communications: response.data, total: response.meta.total })));
  }

  create(input: CreatePatientCommunicationInput): Observable<PatientCommunicationRecord> {
    return this.api
      .post<ApiData<PatientCommunicationRecord>, CreatePatientCommunicationInput>('/api/v1/communications', input, {
        idempotencyKey: idempotencyKey(),
      })
      .pipe(map((response) => response.data));
  }

  queue(record: PatientCommunicationRecord): Observable<PatientCommunicationRecord> {
    return this.api
      .post<ApiData<PatientCommunicationRecord>, { readonly version: number }>(
        `/api/v1/communications/${encodeURIComponent(record.id)}/queue`,
        { version: record.version },
        { idempotencyKey: idempotencyKey() },
      )
      .pipe(map((response) => response.data));
  }

  cancel(record: PatientCommunicationRecord): Observable<PatientCommunicationRecord> {
    return this.api
      .post<ApiData<PatientCommunicationRecord>, { readonly version: number }>(
        `/api/v1/communications/${encodeURIComponent(record.id)}/cancel`,
        { version: record.version },
        { idempotencyKey: idempotencyKey() },
      )
      .pipe(map((response) => response.data));
  }
}

function idempotencyKey(): string {
  try {
    return globalThis.crypto.randomUUID();
  } catch {
    return `communication-${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
  }
}
