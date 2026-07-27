import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { ApiClientService } from '../../../core/services/api-client';

export type ClinicalNoteStatus = 'draft' | 'signed';

export interface ClinicalNoteRecord {
  readonly id: string;
  readonly patientId: string;
  readonly appointmentId: string | null;
  readonly supersedesId: string | null;
  readonly status: ClinicalNoteStatus;
  readonly content: string;
  readonly authorDisplayName: string | null;
  readonly createdAt: string | null;
  readonly signedAt: string | null;
}

interface ApiData<T> {
  readonly data: T;
}

interface ClinicalNotesCollection {
  readonly data: readonly ClinicalNoteRecord[];
  readonly meta: { readonly total: number };
}

@Injectable({ providedIn: 'root' })
export class ClinicalNotesApiService {
  private readonly api = inject(ApiClientService);

  list(
    patientId: string,
  ): Observable<{ readonly notes: readonly ClinicalNoteRecord[]; readonly total: number }> {
    return this.api
      .get<ClinicalNotesCollection>(
        `/api/v1/patients/${encodeURIComponent(patientId)}/clinical-notes`,
        { params: { perPage: 50 } },
      )
      .pipe(map((response) => ({ notes: response.data, total: response.meta.total })));
  }

  createDraft(
    patientId: string,
    content: string,
    appointmentId?: string,
  ): Observable<ClinicalNoteRecord> {
    return this.create(patientId, '/clinical-notes', content, appointmentId);
  }

  sign(patientId: string, content: string, appointmentId?: string): Observable<ClinicalNoteRecord> {
    return this.create(patientId, '/clinical-notes/sign', content, appointmentId);
  }

  private create(
    patientId: string,
    suffix: string,
    content: string,
    appointmentId?: string,
  ): Observable<ClinicalNoteRecord> {
    return this.api
      .post<
        ApiData<ClinicalNoteRecord>,
        { readonly content: string; readonly appointmentId?: string }
      >(
        `/api/v1/patients/${encodeURIComponent(patientId)}${suffix}`,
        { content, ...(appointmentId ? { appointmentId } : {}) },
        { idempotencyKey: idempotencyKey() },
      )
      .pipe(map((response) => response.data));
  }
}

function idempotencyKey(): string {
  try {
    return globalThis.crypto.randomUUID();
  } catch {
    return `clinical-note-${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
  }
}
