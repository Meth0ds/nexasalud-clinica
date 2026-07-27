import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { ApiClientService } from '../../../core/services/api-client';

export interface WorkspacePreferences {
  readonly theme: {
    readonly mode: 'system' | 'light' | 'dark';
    readonly density: 'comfortable' | 'compact';
    readonly reduceMotion: boolean;
  };
  readonly agenda: { readonly defaultView: 'day' | 'week' | 'month' };
  readonly privacy: { readonly autoMask: boolean };
  readonly notifications: { readonly desktop: boolean };
}
export interface SettingsSnapshot {
  readonly version: number;
  readonly preferences: WorkspacePreferences;
}

@Injectable({ providedIn: 'root' })
export class SettingsApiService {
  private readonly api = inject(ApiClientService);
  load(): Observable<SettingsSnapshot> {
    return this.api
      .get<{ readonly data: SettingsSnapshot }>('/api/v1/settings')
      .pipe(map((response) => response.data));
  }
  save(preferences: WorkspacePreferences): Observable<SettingsSnapshot> {
    return this.api
      .put<{ readonly data: SettingsSnapshot }, WorkspacePreferences>(
        '/api/v1/settings',
        preferences,
      )
      .pipe(map((response) => response.data));
  }
}
