import {
  HttpErrorResponse,
  HttpEvent,
  HttpHandlerFn,
  HttpInterceptorFn,
  HttpRequest,
} from '@angular/common/http';
import { inject } from '@angular/core';
import { Observable, catchError, retry, throwError, timeout, timer } from 'rxjs';

import { toAppError } from '../errors/app-error';
import { ErrorNotificationService } from '../services/error-notification';
import { LONG_RUNNING_REQUEST, SILENT_HTTP_ERROR } from './http-context';

const safeMethods = new Set(['GET', 'HEAD', 'OPTIONS']);
const retriableStatuses = new Set([0, 408, 425, 502, 503, 504]);

export const securityHttpInterceptor: HttpInterceptorFn = (
  initialRequest,
  next,
): Observable<HttpEvent<unknown>> => {
  const notifications = inject(ErrorNotificationService);
  const requestId = createRequestId();

  let headers = initialRequest.headers
    .set('Accept', 'application/json')
    .set('X-Request-ID', requestId);

  const request = initialRequest.clone({ headers, withCredentials: true });
  const timeoutMs = request.context.get(LONG_RUNNING_REQUEST) ? 120_000 : 20_000;

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
    catchError((error: unknown) => {
      const appError = toAppError(error);
      if (!request.context.get(SILENT_HTTP_ERROR)) {
        notifications.notify(appError);
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
