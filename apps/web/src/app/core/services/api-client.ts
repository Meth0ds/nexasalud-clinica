import { HttpClient, HttpContext, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { LONG_RUNNING_REQUEST, SILENT_HTTP_ERROR } from '../http/http-context';

export interface RequestOptions {
  readonly params?: Readonly<Record<string, string | number | boolean | undefined>>;
  readonly silent?: boolean;
  readonly longRunning?: boolean;
  readonly idempotencyKey?: string;
}

@Injectable({ providedIn: 'root' })
export class ApiClientService {
  private readonly http = inject(HttpClient);

  get<T>(url: string, options: RequestOptions = {}): Observable<T> {
    return this.http.get<T>(url, {
      params: toHttpParams(options.params),
      context: toHttpContext(options),
    });
  }

  post<TResponse, TBody = unknown>(
    url: string,
    body: TBody,
    options: RequestOptions = {},
  ): Observable<TResponse> {
    return this.http.post<TResponse>(url, body, {
      context: toHttpContext(options),
      headers: options.idempotencyKey ? { 'Idempotency-Key': options.idempotencyKey } : undefined,
    });
  }

  patch<TResponse, TBody = unknown>(
    url: string,
    body: TBody,
    options: RequestOptions = {},
  ): Observable<TResponse> {
    return this.http.patch<TResponse>(url, body, { context: toHttpContext(options) });
  }

  put<TResponse, TBody = unknown>(
    url: string,
    body: TBody,
    options: RequestOptions = {},
  ): Observable<TResponse> {
    return this.http.put<TResponse>(url, body, { context: toHttpContext(options) });
  }

  delete<T>(url: string, options: RequestOptions = {}): Observable<T> {
    return this.http.delete<T>(url, { context: toHttpContext(options) });
  }
}

function toHttpParams(values: RequestOptions['params']): HttpParams {
  let params = new HttpParams();
  for (const [key, value] of Object.entries(values ?? {})) {
    if (value !== undefined) {
      params = params.set(key, String(value));
    }
  }
  return params;
}

function toHttpContext(options: RequestOptions): HttpContext {
  return new HttpContext()
    .set(SILENT_HTTP_ERROR, options.silent ?? false)
    .set(LONG_RUNNING_REQUEST, options.longRunning ?? false);
}
