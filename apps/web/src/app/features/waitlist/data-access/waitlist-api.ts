import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { ApiClientService } from '../../../core/services/api-client';

export type WaitlistStatus = 'waiting' | 'offered' | 'booked' | 'expired' | 'cancelled';

export interface WaitlistEntryRecord {
  readonly id: string;
  readonly version: number;
  readonly patientId: string;
  readonly patientDisplayName: string;
  readonly patientInitials: string;
  readonly requestedType: string | null;
  readonly preferredWindow: readonly [string | null, string | null];
  readonly preferredDays: readonly number[];
  readonly priority: number;
  readonly status: WaitlistStatus;
  readonly waitingSince: string | null;
  readonly offeredAt: string | null;
  readonly offerExpiresAt: string | null;
}

export interface CreateWaitlistEntryInput {
  readonly clinicId: string;
  readonly patientId: string;
  readonly appointmentTypeId: string;
  readonly desiredFrom: string;
  readonly desiredTo: string;
  readonly priority: number;
  readonly notes?: string;
}

interface ApiData<T> {
  readonly data: T;
}

interface WaitlistCollection {
  readonly data: readonly WaitlistEntryRecord[];
  readonly meta: { readonly total: number };
}

@Injectable({ providedIn: 'root' })
export class WaitlistApiService {
  private readonly api = inject(ApiClientService);

  list(): Observable<{ readonly entries: readonly WaitlistEntryRecord[]; readonly total: number }> {
    return this.api
      .get<WaitlistCollection>('/api/v1/waitlist-entries', { params: { perPage: 100 } })
      .pipe(map((response) => ({ entries: response.data, total: response.meta.total })));
  }

  create(input: CreateWaitlistEntryInput): Observable<WaitlistEntryRecord> {
    return this.api
      .post<ApiData<WaitlistEntryRecord>, CreateWaitlistEntryInput>(
        '/api/v1/waitlist-entries',
        input,
        {
          idempotencyKey: idempotencyKey(),
        },
      )
      .pipe(map((response) => response.data));
  }

  offer(entry: WaitlistEntryRecord, expiresInMinutes = 20): Observable<WaitlistEntryRecord> {
    return this.api
      .post<
        ApiData<WaitlistEntryRecord>,
        { readonly version: number; readonly expiresInMinutes: number }
      >(
        `/api/v1/waitlist-entries/${encodeURIComponent(entry.id)}/offer`,
        { version: entry.version, expiresInMinutes },
        { idempotencyKey: idempotencyKey() },
      )
      .pipe(map((response) => response.data));
  }
}

function idempotencyKey(): string {
  try {
    return globalThis.crypto.randomUUID();
  } catch {
    return `waitlist-${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
  }
}
