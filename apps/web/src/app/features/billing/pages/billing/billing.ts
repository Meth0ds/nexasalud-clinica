import { DOCUMENT } from '@angular/common';
import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';

import { ErrorNotificationService } from '../../../../core/services/error-notification';
import { AppIcon } from '../../../../shared/components/app-icon/app-icon';
import { ScrollReveal } from '../../../../shared/directives/scroll-reveal';

interface InvoiceRow {
  readonly id: string;
  readonly patient: string;
  readonly concept: string;
  readonly issued: string;
  readonly amount: number;
  readonly payer: string;
  readonly status: 'Borrador' | 'Emitida' | 'Pagada' | 'Pendiente aseguradora' | 'Vencida';
  readonly tone: 'info' | 'success' | 'warning' | 'danger';
}

@Component({ selector: 'app-billing', imports: [FormsModule, MatButtonModule, AppIcon, ScrollReveal], templateUrl: './billing.html', styleUrl: './billing.scss', changeDetection: ChangeDetectionStrategy.OnPush })
export class Billing {
  private readonly document = inject(DOCUMENT);
  private readonly notifications = inject(ErrorNotificationService);
  protected readonly createOpen = signal(false);
  protected readonly invoicePatient = signal('');
  protected readonly invoiceConcept = signal('Consulta clínica');
  protected readonly invoiceAmount = signal(80);
  protected readonly invoices = signal<readonly InvoiceRow[]>([
    { id: 'F-2026-0184', patient: 'Elena V.', concept: 'Revisión de tratamiento', issued: '18 jul 2026', amount: 65, payer: 'Privado', status: 'Pagada', tone: 'success' },
    { id: 'F-2026-0183', patient: 'Lucas M.', concept: 'Consulta de seguimiento', issued: '19 jul 2026', amount: 80, payer: 'Salud Integral', status: 'Pendiente aseguradora', tone: 'warning' },
    { id: 'F-2026-0182', patient: 'Ana R.', concept: 'Primera valoración', issued: '17 jul 2026', amount: 95, payer: 'Vida Sana', status: 'Emitida', tone: 'info' },
    { id: 'F-2026-0176', patient: 'David G.', concept: 'Consulta clínica', issued: '2 jul 2026', amount: 80, payer: 'Privado', status: 'Vencida', tone: 'danger' },
  ]);

  protected createInvoice(): void {
    if (!this.invoicePatient().trim() || this.invoiceAmount() <= 0) { return; }
    this.invoices.update((rows) => [{ id: `F-2026-${String(185 + rows.length).padStart(4, '0')}`, patient: this.invoicePatient().trim(), concept: this.invoiceConcept().trim(), issued: 'Hoy', amount: this.invoiceAmount(), payer: 'Privado', status: 'Borrador', tone: 'info' }, ...rows]);
    this.createOpen.set(false);
    this.invoicePatient.set('');
    this.notifications.success('Borrador de factura creado.');
  }

  protected markPaid(invoice: InvoiceRow): void {
    this.invoices.update((rows) => rows.map((row) => row.id === invoice.id ? { ...row, status: 'Pagada', tone: 'success' } : row));
    this.notifications.success('Pago registrado y conciliado.');
  }

  protected download(invoice: InvoiceRow): void {
    this.document.defaultView?.open(`/api/v1/invoices/${encodeURIComponent(invoice.id)}/document.pdf`, '_blank', 'noopener,noreferrer');
  }
}
