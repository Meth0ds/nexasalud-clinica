import { DOCUMENT } from '@angular/common';
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatSelectModule } from '@angular/material/select';
import { finalize, forkJoin } from 'rxjs';

import { Patient } from '../../../../core/models/app.models';
import { ErrorNotificationService } from '../../../../core/services/error-notification';
import { PatientsService } from '../../../../core/services/patients';
import { SessionService } from '../../../../core/services/session';
import { AppIcon } from '../../../../shared/components/app-icon/app-icon';
import { ScrollReveal } from '../../../../shared/directives/scroll-reveal';
import {
  InvoiceApiService,
  InvoiceRecord,
  InvoiceStatus,
  PaymentMethod,
} from '../../data-access/invoice-api';

@Component({
  selector: 'app-billing',
  imports: [
    ReactiveFormsModule,
    MatButtonModule,
    MatFormFieldModule,
    MatInputModule,
    MatProgressSpinnerModule,
    MatSelectModule,
    AppIcon,
    ScrollReveal,
  ],
  templateUrl: './billing.html',
  styleUrls: ['./billing.scss', './billing-panel.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class Billing {
  private readonly formBuilder = inject(FormBuilder);
  private readonly invoiceApi = inject(InvoiceApiService);
  private readonly patientsApi = inject(PatientsService);
  private readonly session = inject(SessionService);
  private readonly notifications = inject(ErrorNotificationService);
  private readonly document = inject(DOCUMENT);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly loading = signal(true);
  protected readonly saving = signal(false);
  protected readonly processingId = signal<string | null>(null);
  protected readonly downloadingId = signal<string | null>(null);
  protected readonly createOpen = signal(false);
  protected readonly paymentInvoice = signal<InvoiceRecord | null>(null);
  protected readonly invoices = signal<readonly InvoiceRecord[]>([]);
  protected readonly patients = signal<readonly Patient[]>([]);
  protected readonly query = signal('');
  protected readonly errorMessage = signal<string | null>(null);
  protected readonly filteredInvoices = computed(() => {
    const query = this.query().trim().toLocaleLowerCase('es');
    if (!query) {
      return this.invoices();
    }

    return this.invoices().filter((invoice) =>
      [
        invoice.number,
        invoice.patientDisplayName,
        ...invoice.items.map((item) => item.description),
      ].some((value) => value.toLocaleLowerCase('es').includes(query)),
    );
  });
  protected readonly metrics = computed(() => {
    const invoices = this.invoices();
    const issued = invoices.filter(
      (invoice) => invoice.status !== 'draft' && invoice.status !== 'void',
    );
    const collected = issued.reduce(
      (sum, invoice) => sum + (invoice.totalMinor - invoice.balanceMinor),
      0,
    );
    const outstanding = issued.reduce((sum, invoice) => sum + invoice.balanceMinor, 0);
    return {
      total: issued.reduce((sum, invoice) => sum + invoice.totalMinor, 0),
      collected,
      outstanding,
      documents: issued.length,
    };
  });

  protected readonly invoiceForm = this.formBuilder.nonNullable.group({
    patientId: ['', Validators.required],
    description: ['', [Validators.required, Validators.minLength(3), Validators.maxLength(255)]],
    quantity: ['1', [Validators.required, Validators.pattern(/^\d{1,5}(?:\.\d{1,3})?$/)]],
    unitPriceEuro: ['', [Validators.required, Validators.min(0.01), Validators.max(1_000_000)]],
    taxRatePercent: ['0', [Validators.required, Validators.min(0), Validators.max(100)]],
    dueOn: [''],
    notes: ['', Validators.maxLength(1000)],
  });

  protected readonly paymentForm = this.formBuilder.nonNullable.group({
    amountEuro: ['', [Validators.required, Validators.min(0.01)]],
    methodCode: this.formBuilder.nonNullable.control<PaymentMethod>(
      'card_provider',
      Validators.required,
    ),
    externalReference: ['', Validators.maxLength(160)],
  });

  constructor() {
    this.load();
  }

  protected openCreate(): void {
    this.invoiceForm.reset({
      patientId: '',
      description: '',
      quantity: '1',
      unitPriceEuro: '',
      taxRatePercent: '0',
      dueOn: '',
      notes: '',
    });
    this.createOpen.set(true);
  }

  protected closeCreate(): void {
    if (!this.saving()) {
      this.createOpen.set(false);
    }
  }

  protected createInvoice(): void {
    const clinicId = this.session.tenant()?.clinicId;
    if (!clinicId || this.saving() || this.invoiceForm.invalid) {
      this.invoiceForm.markAllAsTouched();
      if (!clinicId) {
        this.notifications.error('Selecciona un centro activo antes de crear una factura.');
      }
      return;
    }

    const value = this.invoiceForm.getRawValue();
    const unitPriceMinor = Math.round(Number(value.unitPriceEuro) * 100);
    const taxRateBasisPoints = Math.round(Number(value.taxRatePercent) * 100);
    this.saving.set(true);
    this.invoiceApi
      .create({
        clinicId,
        patientId: value.patientId,
        currency: 'EUR',
        ...(value.dueOn ? { dueOn: value.dueOn } : {}),
        ...(value.notes.trim() ? { notes: value.notes.trim() } : {}),
        items: [
          {
            description: value.description.trim(),
            quantity: value.quantity,
            unitPriceMinor,
            taxRateBasisPoints,
          },
        ],
      })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.saving.set(false)),
      )
      .subscribe({
        next: (invoice) => {
          this.upsert(invoice);
          this.createOpen.set(false);
          this.notifications.success('Borrador de factura creado y auditado.');
        },
      });
  }

  protected issue(invoice: InvoiceRecord): void {
    if (invoice.status !== 'draft' || this.processingId()) {
      return;
    }

    this.processingId.set(invoice.id);
    this.invoiceApi
      .issue(invoice)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.processingId.set(null)),
      )
      .subscribe({
        next: (updated) => {
          this.upsert(updated);
          this.notifications.success('Factura emitida. Ya puede generarse el documento PDF.');
        },
      });
  }

  protected openPayment(invoice: InvoiceRecord): void {
    if (!['issued', 'partially_paid'].includes(invoice.status)) {
      return;
    }

    this.paymentForm.reset({
      amountEuro: (invoice.balanceMinor / 100).toFixed(2),
      methodCode: 'card_provider',
      externalReference: '',
    });
    this.paymentInvoice.set(invoice);
  }

  protected closePayment(): void {
    if (!this.processingId()) {
      this.paymentInvoice.set(null);
    }
  }

  protected recordPayment(): void {
    const invoice = this.paymentInvoice();
    if (!invoice || this.paymentForm.invalid || this.processingId()) {
      this.paymentForm.markAllAsTouched();
      return;
    }

    const value = this.paymentForm.getRawValue();
    const amountMinor = Math.round(Number(value.amountEuro) * 100);
    if (amountMinor > invoice.balanceMinor) {
      this.notifications.error('El importe no puede superar el saldo pendiente de la factura.');
      return;
    }

    this.processingId.set(invoice.id);
    this.invoiceApi
      .recordPayment(invoice, {
        amountMinor,
        methodCode: value.methodCode,
        ...(value.externalReference.trim()
          ? { externalReference: value.externalReference.trim() }
          : {}),
      })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.processingId.set(null)),
      )
      .subscribe({
        next: (updated) => {
          this.upsert(updated);
          this.paymentInvoice.set(null);
          this.notifications.success('Cobro registrado y trazado correctamente.');
        },
      });
  }

  protected download(invoice: InvoiceRecord): void {
    if (
      this.downloadingId() === invoice.id ||
      invoice.status === 'draft' ||
      invoice.status === 'void'
    ) {
      return;
    }

    this.downloadingId.set(invoice.id);
    this.invoiceApi
      .downloadInvoice(invoice)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.downloadingId.set(null)),
      )
      .subscribe({
        next: ({ blob, filename }) => this.downloadBlob(blob, filename),
      });
  }

  protected statusLabel(status: InvoiceStatus): string {
    return {
      draft: 'Borrador',
      issued: 'Emitida',
      partially_paid: 'Pago parcial',
      paid: 'Pagada',
      void: 'Anulada',
      overdue: 'Vencida',
    }[status];
  }

  protected statusTone(status: InvoiceStatus): 'info' | 'warning' | 'success' | 'danger' {
    return status === 'draft' || status === 'issued'
      ? 'info'
      : status === 'paid'
        ? 'success'
        : status === 'overdue' || status === 'void'
          ? 'danger'
          : 'warning';
  }

  protected money(value: number, currency = 'EUR'): string {
    return new Intl.NumberFormat('es-ES', { style: 'currency', currency }).format(value / 100);
  }

  protected date(value: string | null): string {
    if (!value) {
      return 'Sin fecha';
    }
    const parsed = new Date(`${value}T12:00:00`);
    return Number.isNaN(parsed.getTime())
      ? 'Sin fecha'
      : new Intl.DateTimeFormat('es-ES', { dateStyle: 'medium' }).format(parsed);
  }

  private load(): void {
    forkJoin({ invoices: this.invoiceApi.list(), patients: this.patientsApi.search() })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.loading.set(false)),
      )
      .subscribe({
        next: ({ invoices, patients }) => {
          this.invoices.set(invoices.invoices);
          this.patients.set(patients.items);
        },
        error: () =>
          this.errorMessage.set(
            'No se han podido cargar las facturas autorizadas. Comprueba tu sesión, contexto y permisos de facturación.',
          ),
      });
  }

  private upsert(invoice: InvoiceRecord): void {
    this.invoices.update((items) => {
      const exists = items.some((item) => item.id === invoice.id);
      return exists
        ? items.map((item) => (item.id === invoice.id ? invoice : item))
        : [invoice, ...items];
    });
  }

  private downloadBlob(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const anchor = this.document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    anchor.rel = 'noopener';
    anchor.style.display = 'none';
    this.document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    globalThis.setTimeout(() => URL.revokeObjectURL(url), 0);
  }
}
