import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { AppointmentStatus } from '../../../core/models/app.models';
import { ApiClientService } from '../../../core/services/api-client';

export interface DashboardAppointment {
  readonly id: string;
  readonly startsAt: string | null;
  readonly endsAt: string | null;
  readonly patientDisplayName: string;
  readonly patientInitials: string;
  readonly roomName: string | null;
  readonly typeName: string | null;
  readonly status: AppointmentStatus;
}

export interface DashboardData {
  readonly date: string;
  readonly timezone: string;
  readonly clinic: { readonly id: string; readonly name: string };
  readonly metrics: {
    readonly appointments: number;
    readonly active: number;
    readonly completed: number;
    readonly cancelledOrNoShow: number;
    readonly waitingList: number;
    readonly occupancyPercent: number;
  };
  readonly dayAppointments: readonly DashboardAppointment[];
}

@Injectable({ providedIn: 'root' })
export class DashboardApiService {
  private readonly api = inject(ApiClientService);

  load(): Observable<DashboardData> {
    return this.api.get<{ readonly data: DashboardData }>('/api/v1/dashboard').pipe(map((response) => response.data));
  }
}
