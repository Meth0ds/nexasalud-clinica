import { DOCUMENT } from '@angular/common';
import { HttpClient, HttpContext } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Observable, catchError, finalize, map, of, switchMap, tap } from 'rxjs';

import { AppError } from '../errors/app-error';
import { SILENT_HTTP_ERROR } from '../http/http-context';
import { SessionData, TenantContextOptionsResponse } from '../models/app.models';

export type SessionState =
  | { readonly status: 'idle' | 'loading' }
  | { readonly status: 'authenticated'; readonly data: SessionData }
  | { readonly status: 'context-required'; readonly options: TenantContextOptionsResponse }
  | { readonly status: 'anonymous' }
  | { readonly status: 'error'; readonly error: AppError };

@Injectable({ providedIn: 'root' })
export class SessionService {
  private readonly http = inject(HttpClient);
  private readonly document = inject(DOCUMENT);
  private readonly router = inject(Router);
  private readonly stateSignal = signal<SessionState>({ status: 'idle' });
  private activeRequest: Observable<SessionState> | null = null;

  readonly state = this.stateSignal.asReadonly();
  readonly user = computed(() => {
    const state = this.stateSignal();
    return state.status === 'authenticated' ? state.data.user : null;
  });
  readonly tenant = computed(() => {
    const state = this.stateSignal();
    return state.status === 'authenticated' ? state.data.tenant : null;
  });
  readonly authenticated = computed(() => this.stateSignal().status === 'authenticated');
  readonly assuranceLevel = computed(() => {
    const state = this.stateSignal();
    return state.status === 'authenticated' ? state.data.assuranceLevel : null;
  });
  readonly contextOptions = computed(() => {
    const state = this.stateSignal();
    return state.status === 'context-required' ? state.options : null;
  });

  ensureLoaded(force = false): Observable<SessionState> {
    const current = this.stateSignal();
    if (!force && current.status !== 'idle' && current.status !== 'loading') {
      return of(current);
    }
    if (this.activeRequest) {
      return this.activeRequest;
    }

    this.stateSignal.set({ status: 'loading' });
    const context = new HttpContext().set(SILENT_HTTP_ERROR, true);
    const request = this.http
      .get<SessionData>('/api/session', { context, withCredentials: true })
      .pipe(
        map((data): SessionState => ({ status: 'authenticated', data })),
        tap((state) => {
          this.stateSignal.set(state);
        }),
        catchError((error: AppError): Observable<SessionState> => {
          if (error.status === 409 && error.type === '/problems/tenant-selection-required') {
            return this.loadContextOptions(context);
          }
          const nextState: SessionState =
            error.status === 401 || error.status === 403
              ? { status: 'anonymous' }
              : { status: 'error', error };
          this.stateSignal.set(nextState);
          return of(nextState);
        }),
        finalize(() => {
          this.activeRequest = null;
        }),
      );
    this.activeRequest = request;
    return request;
  }

  selectContext(membershipId: string, clinicId: string): Observable<SessionState> {
    const context = new HttpContext().set(SILENT_HTTP_ERROR, true);
    return this.http
      .post<{ readonly selected: true }>('/api/session/context', { membershipId, clinicId }, { context })
      .pipe(switchMap(() => this.ensureLoaded(true)));
  }

  login(returnTo = '/'): void {
    const safeReturnTo = returnTo.startsWith('/') && !returnTo.startsWith('//') ? returnTo : '/';
    this.document.defaultView?.location.assign(
      `/auth/login?returnTo=${encodeURIComponent(safeReturnTo)}`,
    );
  }

  logout(): void {
    this.http
      .post<{ readonly signedOut: true; readonly upstreamRedirect: string | null }>(
        '/auth/logout',
        { upstream: true },
        { withCredentials: true },
      )
      .subscribe({
        next: (result) => {
          this.clearSession();
          if (result.upstreamRedirect) {
            this.document.defaultView?.location.assign(result.upstreamRedirect);
          } else {
            void this.router.navigate(['/login']);
          }
        },
        error: () => this.clearAndNavigate(),
      });
  }

  hasPermission(permission: string): boolean {
    const state = this.stateSignal();
    return (
      state.status === 'authenticated' &&
      state.data.permissions.some(
        (granted) =>
          granted === '*' ||
          granted === permission ||
          (granted.endsWith('.*') && permission.startsWith(granted.slice(0, -1))),
      )
    );
  }

  private clearAndNavigate(): void {
    this.clearSession();
    void this.router.navigate(['/login']);
  }

  private clearSession(): void {
    this.stateSignal.set({ status: 'anonymous' });
  }

  private loadContextOptions(context: HttpContext): Observable<SessionState> {
    return this.http
      .get<TenantContextOptionsResponse>('/api/session/context-options', { context })
      .pipe(
        map((options): SessionState => ({ status: 'context-required', options })),
        tap((state) => this.stateSignal.set(state)),
        catchError((error: AppError) => {
          const state: SessionState =
            error.status === 401 || error.status === 403
              ? { status: 'anonymous' }
              : { status: 'error', error };
          this.stateSignal.set(state);
          return of(state);
        }),
      );
  }
}
