import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { ApiClientService } from '../../../core/services/api-client';

export interface AdministrationOverview {
  readonly users: {
    readonly total: number;
    readonly active: number;
    readonly suspended: number;
  };
  readonly roles: number;
  readonly auditEventsLast24Hours: number;
  readonly mfaPolicy: {
    readonly required: boolean;
    readonly methods: readonly string[];
    readonly minimumAcr: number;
  };
}

export interface AdministrationRoleSummary {
  readonly id: string;
  readonly code: string;
  readonly name: string;
}

export interface AdministrationUser {
  readonly membershipId: string;
  readonly userId: string;
  readonly displayName: string;
  readonly emailHint: string | null;
  readonly active: boolean;
  readonly roles: readonly AdministrationRoleSummary[];
  readonly lastLoginAt: string | null;
  readonly sessionsRevokedAt: string | null;
  readonly mfaPolicy: 'required';
}

export interface AdministrationRole extends AdministrationRoleSummary {
  readonly permissions: readonly string[];
  readonly system: boolean;
  readonly memberCount: number;
}

export interface MembershipChange {
  readonly active?: boolean;
  readonly roleIds?: readonly string[];
  readonly reason: string;
}

interface ApiData<T> {
  readonly data: T;
}

interface AdministrationUserResponse {
  readonly data: readonly AdministrationUser[];
  readonly meta: {
    readonly currentPage: number;
    readonly lastPage: number;
    readonly perPage: number;
    readonly total: number;
  };
}

@Injectable({ providedIn: 'root' })
export class AdministrationApiService {
  private readonly api = inject(ApiClientService);

  overview(): Observable<AdministrationOverview> {
    return this.api
      .get<ApiData<AdministrationOverview>>('/api/v1/administration/overview')
      .pipe(map((response) => response.data));
  }

  users(): Observable<readonly AdministrationUser[]> {
    return this.api
      .get<AdministrationUserResponse>('/api/v1/administration/users', {
        params: { perPage: 100 },
      })
      .pipe(map((response) => response.data));
  }

  roles(): Observable<readonly AdministrationRole[]> {
    return this.api
      .get<ApiData<readonly AdministrationRole[]>>('/api/v1/administration/roles')
      .pipe(map((response) => response.data));
  }

  updateMembership(userId: string, changes: MembershipChange): Observable<AdministrationUser> {
    return this.api
      .patch<ApiData<AdministrationUser>, MembershipChange>(
        `/api/v1/administration/users/${encodeURIComponent(userId)}`,
        changes,
      )
      .pipe(map((response) => response.data));
  }

  revokeSessions(userId: string, reason: string): Observable<{ readonly revoked: true; readonly effectiveAt: string }> {
    return this.api
      .post<ApiData<{ readonly revoked: true; readonly effectiveAt: string }>, { readonly reason: string }>(
        `/api/v1/administration/users/${encodeURIComponent(userId)}/revoke-sessions`,
        { reason },
      )
      .pipe(map((response) => response.data));
  }
}
