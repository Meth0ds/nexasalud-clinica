import { Injectable, signal } from '@angular/core';
import { Observable, Subject, filter, map } from 'rxjs';

export type AppEvent =
  | {
      readonly type: 'http.request.started';
      readonly requestId: string;
      readonly method: string;
      readonly path: string;
      readonly longRunning: boolean;
    }
  | {
      readonly type: 'http.request.succeeded';
      readonly requestId: string;
      readonly method: string;
      readonly path: string;
      readonly status: number;
      readonly durationMs: number;
    }
  | {
      readonly type: 'http.request.failed';
      readonly requestId: string;
      readonly method: string;
      readonly path: string;
      readonly status: number;
      readonly code: string;
      readonly correlationId?: string;
      readonly retryable: boolean;
      readonly durationMs: number;
    }
  | {
      readonly type: 'auth.login.started';
      readonly returnTo: string;
    }
  | {
      readonly type: 'auth.session.loaded';
      readonly assuranceLevel: 'mfa' | 'passkey' | 'password';
    }
  | {
      readonly type: 'auth.session.expired';
      readonly status: number;
    }
  | {
      readonly type: 'auth.session.load.failed';
      readonly status: number;
      readonly code: string;
      readonly correlationId?: string;
    }
  | {
      readonly type: 'auth.context.options.failed';
      readonly status: number;
      readonly code: string;
      readonly correlationId?: string;
    }
  | {
      readonly type: 'auth.context.selected';
      readonly selected: true;
    }
  | {
      readonly type: 'auth.context.selection.failed';
      readonly status: number;
      readonly code: string;
      readonly correlationId?: string;
    }
  | {
      readonly type: 'auth.logout.completed';
      readonly upstreamRedirect: boolean;
    }
  | {
      readonly type: 'auth.logout.failed';
      readonly status: number;
      readonly code: string;
      readonly correlationId?: string;
    }
  | {
      readonly type: 'app.error.presented';
      readonly source: 'http' | 'runtime' | 'manual';
      readonly status: number;
      readonly code: string;
      readonly correlationId?: string;
      readonly retryable: boolean;
    }
  | {
      readonly type: 'ui.notice';
      readonly level: 'success' | 'info' | 'warning';
      /** Stable semantic code; the user-facing copy never enters telemetry. */
      readonly code: string;
    };

export interface AppEventEnvelope {
  readonly event: AppEvent;
  readonly occurredAt: number;
}

/**
 * Small in-process event bus for cross-cutting UI, auth, and HTTP telemetry.
 * Events are metadata-only: no request bodies, tokens, clinical data, or PII.
 */
@Injectable({ providedIn: 'root' })
export class AppEventBus {
  private readonly subject = new Subject<AppEventEnvelope>();
  private readonly latestSignal = signal<AppEventEnvelope | null>(null);

  readonly events$ = this.subject.asObservable();
  readonly latest = this.latestSignal.asReadonly();

  emit(event: AppEvent): void {
    const envelope: AppEventEnvelope = { event, occurredAt: Date.now() };
    this.latestSignal.set(envelope);
    this.subject.next(envelope);
  }

  ofType<Type extends AppEvent['type']>(type: Type): Observable<Extract<AppEvent, { type: Type }>> {
    return this.events$.pipe(
      filter((envelope) => envelope.event.type === type),
      map((envelope) => envelope.event as Extract<AppEvent, { type: Type }>),
    );
  }
}
