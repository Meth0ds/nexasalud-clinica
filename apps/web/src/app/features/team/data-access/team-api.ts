import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { ApiClientService } from '../../../core/services/api-client';

export interface TeamRole {
  readonly id: string;
  readonly code: string;
  readonly name: string;
}

export interface TeamMember {
  readonly membershipId: string;
  readonly userId: string;
  readonly displayName: string | null;
  readonly initials: string;
  readonly roles: readonly TeamRole[];
  readonly active: boolean;
  readonly joinedAt: string | null;
  readonly lastLoginAt: string | null;
}

interface TeamResponse {
  readonly data: readonly TeamMember[];
  readonly meta: { readonly total: number };
}

@Injectable({ providedIn: 'root' })
export class TeamApiService {
  private readonly api = inject(ApiClientService);

  load(): Observable<readonly TeamMember[]> {
    return this.api.get<TeamResponse>('/api/v1/team').pipe(map((response) => response.data));
  }
}
