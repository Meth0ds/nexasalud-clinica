import {
  HttpErrorResponse,
  HttpEvent,
  HttpHandlerFn,
  HttpInterceptorFn,
  HttpRequest,
  HttpResponse,
} from '@angular/common/http';
import { inject } from '@angular/core';
import { Observable, catchError, retry, tap, throwError, timeout, timer } from 'rxjs';

import { toAppError } from '../errors/app-error';
import { AppEventBus } from '../events/app-events';
import { ErrorNotificationService } from '../services/error-notification';
import { LONG_RUNNING_REQUEST, SILENT_HTTP_ERROR } from './http-context';

const safeMethods = new Set(['GET', 'HEAD', 'OPTIONS']);
const retriableStatuses = new Set([0, 408, 425, 502, 503, 504]);

export const securityHttpInterceptor: HttpInterceptorFn = (
  initialRequest,
  next,
): Observable<HttpEvent<unknown>> => {
  const notifications = inject(ErrorNotificationService);
  const events = inject(AppEventBus);
  const requestId = createRequestId();

  let headers = initialRequest.headers
    .set('Accept', 'application/json')
    .set('X-Request-ID', requestId);

  const request = initialRequest.clone({ headers, withCredentials: true });
  const timeoutMs = request.context.get(LONG_RUNNING_REQUEST) ? 120_000 : 20_000;
  const startedAt = Date.now();
  const path = safePath(request.urlWithParams);

  events.emit({
    type: 'http.request.started',
    requestId,
    method: request.method,
    path,
    longRunning: request.context.get(LONG_RUNNING_REQUEST),
  });

  return next(request).pipe(
    timeout({ first: timeoutMs }),
    retry({
      count: shouldRetryMethod(request) ? 1 : 0,
      delay: (error, retryCount) => {
        if (isRetriable(error)) {
          return timer(300 * retryCount);
        }
        return throwError(() => error);
      },
    }),
    tap((event) => {
      if (event instanceof HttpResponse) {
        events.emit({
          type: 'http.request.succeeded',
          requestId,
          method: request.method,
          path,
          status: event.status,
          durationMs: Date.now() - startedAt,
        });
      }
    }),
    catchError((error: unknown) => {
      const appError = toAppError(error);
      events.emit({
        type: 'http.request.failed',
        requestId,
        method: request.method,
        path,
        status: appError.status,
        code: appError.code,
        correlationId: appError.correlationId,
        retryable: appError.retryable,
        durationMs: Date.now() - startedAt,
      });
      if (!request.context.get(SILENT_HTTP_ERROR)) {
        notifications.notify(appError, 'http');
      }
      return throwError(() => appError);
    }),
  );
};

function shouldRetryMethod(request: HttpRequest<unknown>): boolean {
  return request.method === 'GET' || request.method === 'HEAD';
}

function isRetriable(error: unknown): boolean {
  return error instanceof HttpErrorResponse
    ? retriableStatuses.has(error.status)
    : error instanceof Error && error.name === 'TimeoutError';
}

function createRequestId(): string {
  try {
    return globalThis.crypto.randomUUID();
  } catch {
    return `web-${Date.now().toString(36)}`;
  }
}

export function safePath(url: string): string {
  const withoutQuery = url.split(/[?#]/, 1)[0] ?? url;
  const path = withoutQuery.replace(/^https?:\/\/[^/]+/i, '') || '/';
  return path
    .replace(
      /\/(patients|appointments|invoices|prescriptions|documents|messages|waitlist|waitlist-entries|memberships|users|communications|clinical-notes|notes)(?:\/[^/]+)/gi,
      '/$1/:id',
    )
    .replace(/\/[0-9a-f]{8}-[0-9a-f-]{27,}/gi, '/:id');
}
