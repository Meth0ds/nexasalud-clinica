import {
  ChangeDetectionStrategy,
  Component,
  OnInit,
  computed,
  inject,
  signal,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';

import {
  AnalyticsApiService,
  AnalyticsPeriod,
  AnalyticsSnapshot,
} from '../../data-access/analytics-api';
import { AppIcon } from '../../../../shared/components/app-icon/app-icon';
import { ScrollReveal } from '../../../../shared/directives/scroll-reveal';

@Component({
  selector: 'app-analytics',
  imports: [
    MatButtonModule,
    MatButtonToggleModule,
    MatProgressSpinnerModule,
    AppIcon,
    ScrollReveal,
  ],
  templateUrl: './analytics.html',
  styleUrl: './analytics.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class Analytics implements OnInit {
  private readonly api = inject(AnalyticsApiService);
  protected readonly period = signal<AnalyticsPeriod>('30d');
  protected readonly snapshot = signal<AnalyticsSnapshot | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);
  protected readonly statusRows = computed(() =>
    Object.entries(this.snapshot()?.byStatus ?? {}).map(([status, total]) => ({
      status: this.statusLabel(status),
      total,
    })),
  );

  ngOnInit(): void {
    this.load();
  }

  protected setPeriod(period: AnalyticsPeriod): void {
    this.period.set(period);
    this.load();
  }
  protected retry(): void {
    this.load();
  }

  private load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.load(this.period()).subscribe({
      next: (snapshot) => {
        this.snapshot.set(snapshot);
        this.loading.set(false);
      },
      error: () => {
        this.error.set('No se ha podido calcular la analítica. Inténtalo de nuevo.');
        this.loading.set(false);
      },
    });
  }

  private statusLabel(status: string): string {
    return (
      {
        scheduled: 'Programadas',
        confirmed: 'Confirmadas',
        checked_in: 'Llegadas',
        in_progress: 'En consulta',
        completed: 'Completadas',
        cancelled: 'Canceladas',
        no_show: 'No presentadas',
      }[status] ?? status
    );
  }
}
