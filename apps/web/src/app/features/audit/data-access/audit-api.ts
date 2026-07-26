import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { ApiClientService } from '../../../core/services/api-client';

export interface AuditedEvent {
  readonly id: string;
  readonly occurredAt: string | null;
  readonly actorDisplayName: string;
  readonly actionLabel: string;
  readonly resourceType: string;
  readonly resourceIdHint: string | null;
  readonly outcome: 'allowed' | 'blocked';
  readonly traceId: string | null;
}

interface AuditEventsResponse {
  readonly data: readonly AuditedEvent[];
  readonly meta: {
    readonly current_page: number;
    readonly last_page: number;
    readonly per_page: number;
    readonly total: number;
  };
}

@Injectable({ providedIn: 'root' })
export class AuditApiService {
  private readonly api = inject(ApiClientService);

  list(): Observable<{ readonly events: readonly AuditedEvent[]; readonly total: number }> {
    return this.api
      .get<AuditEventsResponse>('/api/v1/audit-events', { params: { perPage: 100 } })
      .pipe(map((response) => ({ events: response.data, total: response.meta.total })));
  }
}
