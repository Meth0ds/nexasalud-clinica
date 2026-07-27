import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { ApiClientService } from '../../../core/services/api-client';

export type AnalyticsPeriod = '30d' | '90d' | 'year';

export interface AnalyticsSnapshot {
  readonly range: { readonly from: string; readonly to: string; readonly timezone: string };
  readonly metrics: {
    readonly appointments: number;
    readonly completionRate: number;
    readonly cancellationRate: number;
    readonly noShowRate: number;
  };
  readonly byStatus: Readonly<Record<string, number>>;
  readonly privacy: { readonly aggregated: true; readonly minimumGroupSize: number };
}

@Injectable({ providedIn: 'root' })
export class AnalyticsApiService {
  private readonly api = inject(ApiClientService);

  load(period: AnalyticsPeriod): Observable<AnalyticsSnapshot> {
    const now = new Date();
    const days = period === '30d' ? 30 : period === '90d' ? 90 : 365;
    const from = new Date(now);
    from.setUTCDate(now.getUTCDate() - (days - 1));

    return this.api
      .get<{ readonly data: AnalyticsSnapshot }>('/api/v1/analytics', {
        params: { from: from.toISOString(), to: now.toISOString() },
      })
      .pipe(map((response) => response.data));
  }
}
