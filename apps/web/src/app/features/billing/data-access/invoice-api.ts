import { HttpClient, HttpContext, HttpResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map, switchMap } from 'rxjs';

import { SILENT_HTTP_ERROR } from '../../../core/http/http-context';
import { ApiClientService } from '../../../core/services/api-client';

export type InvoiceStatus = 'draft' | 'issued' | 'partially_paid' | 'paid' | 'void' | 'overdue';
export type PaymentMethod = 'cash' | 'bank_transfer' | 'card_provider' | 'insurer';

export interface InvoiceItem {
  readonly id?: string;
  readonly serviceCode: string | null;
  readonly description: string;
  readonly quantity: string;
  readonly unitPriceMinor: number;
  readonly taxMinor: number;
  readonly totalMinor: number;
}

export interface InvoiceRecord {
  readonly id: string;
  readonly version: number;
  readonly number: string;
  readonly patientId: string;
  readonly patientDisplayName: string;
  readonly status: InvoiceStatus;
  readonly currency: string;
  readonly subtotalMinor: number;
  readonly taxMinor: number;
  readonly totalMinor: number;
  readonly balanceMinor: number;
  readonly issuedOn: string | null;
  readonly dueOn: string | null;
  readonly items: readonly InvoiceItem[];
}

export interface CreateInvoiceInput {
  readonly clinicId: string;
  readonly patientId: string;
  readonly currency: 'EUR';
  readonly dueOn?: string;
  readonly notes?: string;
  readonly items: readonly {
    readonly serviceCode?: string;
    readonly description: string;
    readonly quantity: string;
    readonly unitPriceMinor: number;
    readonly taxRateBasisPoints: number;
  }[];
}

export interface DownloadedInvoiceDocument {
  readonly blob: Blob;
  readonly filename: string;
}

interface ApiData<T> {
  readonly data: T;
}

interface InvoiceCollection {
  readonly data: readonly InvoiceRecord[];
  readonly meta: { readonly total: number };
}

interface GeneratedDocument {
  readonly downloadPath: string;
}

@Injectable({ providedIn: 'root' })
export class InvoiceApiService {
  private readonly api = inject(ApiClientService);
  private readonly http = inject(HttpClient);

  list(): Observable<{ readonly invoices: readonly InvoiceRecord[]; readonly total: number }> {
    return this.api
      .get<InvoiceCollection>('/api/v1/invoices', { params: { perPage: 100 } })
      .pipe(map((response) => ({ invoices: response.data, total: response.meta.total })));
  }

  create(input: CreateInvoiceInput): Observable<InvoiceRecord> {
    return this.api
      .post<ApiData<InvoiceRecord>, CreateInvoiceInput>('/api/v1/invoices', input, { idempotencyKey: idempotencyKey() })
      .pipe(map((response) => response.data));
  }

  issue(invoice: InvoiceRecord): Observable<InvoiceRecord> {
    return this.api
      .post<ApiData<InvoiceRecord>, { readonly version: number }>(
        `/api/v1/invoices/${encodeURIComponent(invoice.id)}/issue`,
        { version: invoice.version },
        { idempotencyKey: idempotencyKey() },
      )
      .pipe(map((response) => response.data));
  }

  recordPayment(
    invoice: InvoiceRecord,
    input: { readonly amountMinor: number; readonly methodCode: PaymentMethod; readonly externalReference?: string },
  ): Observable<InvoiceRecord> {
    return this.api
      .post<ApiData<InvoiceRecord>, { readonly version: number; readonly amountMinor: number; readonly methodCode: PaymentMethod; readonly externalReference?: string }>(
        `/api/v1/invoices/${encodeURIComponent(invoice.id)}/payments`,
        { version: invoice.version, ...input },
        { idempotencyKey: idempotencyKey() },
      )
      .pipe(map((response) => response.data));
  }

  downloadInvoice(invoice: InvoiceRecord): Observable<DownloadedInvoiceDocument> {
    return this.api
      .post<ApiData<GeneratedDocument>, Record<string, never>>(
        `/api/v1/invoices/${encodeURIComponent(invoice.id)}/documents/invoice`,
        {},
        { idempotencyKey: idempotencyKey(), longRunning: true },
      )
      .pipe(switchMap((response) => this.downloadDocument(response.data.downloadPath)));
  }

  private downloadDocument(downloadPath: string): Observable<DownloadedInvoiceDocument> {
    const context = new HttpContext().set(SILENT_HTTP_ERROR, true);
    return this.http
      .get(downloadPath, { context, observe: 'response', responseType: 'blob', withCredentials: true })
      .pipe(map((response: HttpResponse<Blob>) => ({
        blob: response.body ?? new Blob([], { type: 'application/pdf' }),
        filename: filenameFromDisposition(response.headers.get('content-disposition')),
      })));
  }
}

function idempotencyKey(): string {
  try {
    return globalThis.crypto.randomUUID();
  } catch {
    return `invoice-${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
  }
}

function filenameFromDisposition(value: string | null): string {
  const encoded = value?.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
  if (encoded) {
    try {
      return decodeURIComponent(encoded);
    } catch {
      return 'factura.pdf';
    }
  }

  return value?.match(/filename="?([^";]+)"?/i)?.[1]?.trim() || 'factura.pdf';
}
