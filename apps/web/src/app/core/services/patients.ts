import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { PaginatedApiResponse, Patient, PatientListResponse } from '../models/app.models';
import { ApiClientService } from './api-client';

@Injectable({ providedIn: 'root' })
export class PatientsService {
  private readonly api = inject(ApiClientService);

  search(query = '', page = 1): Observable<PatientListResponse> {
    return this.api
      .get<PaginatedApiResponse<Patient>>('/api/v1/patients', {
        params: { identifier: query || undefined, page, perPage: 25 },
      })
      .pipe(map((response) => ({ items: response.data, total: response.meta.total })));
  }

  get(id: string): Observable<Patient> {
    return this.api
      .get<{ readonly data: Patient }>(`/api/v1/patients/${encodeURIComponent(id)}`)
      .pipe(map((response) => response.data));
  }

  create(input: {
    readonly medicalRecordNumber: string;
    readonly firstName: string;
    readonly lastName: string;
    readonly birthDate: string;
    readonly email?: string;
    readonly phone?: string;
    readonly preferredLanguage: 'es';
  }): Observable<Patient> {
    return this.api
      .post<{ readonly data: Patient }, typeof input>('/api/v1/patients', input, {
        idempotencyKey: globalThis.crypto.randomUUID(),
      })
      .pipe(map((response) => response.data));
  }
}
