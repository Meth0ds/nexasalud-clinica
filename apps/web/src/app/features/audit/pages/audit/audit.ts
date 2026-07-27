import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { finalize } from 'rxjs';

import { AuditApiService, AuditedEvent } from '../../data-access/audit-api';
import { AppIcon } from '../../../../shared/components/app-icon/app-icon';
import { ScrollReveal } from '../../../../shared/directives/scroll-reveal';

@Component({
  selector: 'app-audit',
  imports: [MatButtonModule, MatProgressSpinnerModule, AppIcon, ScrollReveal],
  templateUrl: './audit.html',
  styleUrl: './audit.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class Audit {
  private readonly auditApi = inject(AuditApiService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly loading = signal(true);
  protected readonly refreshing = signal(false);
  protected readonly errorMessage = signal<string | null>(null);
  protected readonly query = signal('');
  protected readonly events = signal<readonly AuditedEvent[]>([]);
  protected readonly total = signal(0);
  protected readonly filteredEvents = computed(() => {
    const query = this.query().trim().toLocaleLowerCase('es');
    if (!query) {
      return this.events();
    }

    return this.events().filter((event) =>
      [
        event.actorDisplayName,
        event.actionLabel,
        event.resourceType,
        event.resourceIdHint ?? '',
        event.traceId ?? '',
      ].some((value) => value.toLocaleLowerCase('es').includes(query)),
    );
  });
  protected readonly blockedCount = computed(
    () => this.events().filter((event) => event.outcome === 'blocked').length,
  );

  constructor() {
    this.load();
  }

  protected refresh(): void {
    if (!this.refreshing()) {
      this.load(true);
    }
  }

  protected eventInitials(event: AuditedEvent): string {
    return (
      event.actorDisplayName
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.slice(0, 1).toLocaleUpperCase('es'))
        .join('') || 'SI'
    );
  }

  protected formatDate(value: string | null): string {
    if (!value) {
      return 'Fecha no disponible';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
      return 'Fecha no disponible';
    }

    return new Intl.DateTimeFormat('es-ES', { dateStyle: 'medium', timeStyle: 'medium' }).format(
      date,
    );
  }

  protected outcomeLabel(outcome: AuditedEvent['outcome']): string {
    return outcome === 'blocked' ? 'Bloqueado' : 'Permitido';
  }

  private load(isRefresh = false): void {
    if (isRefresh) {
      this.refreshing.set(true);
    } else {
      this.loading.set(true);
    }
    this.errorMessage.set(null);

    this.auditApi
      .list()
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => {
          this.loading.set(false);
          this.refreshing.set(false);
        }),
      )
      .subscribe({
        next: (response) => {
          this.events.set(response.events);
          this.total.set(response.total);
        },
        error: () =>
          this.errorMessage.set(
            'No se pudo recuperar la auditoría. Revisa la sesión y el permiso de auditoría.',
          ),
      });
  }
}
