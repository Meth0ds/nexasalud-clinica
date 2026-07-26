import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';

import { ErrorNotificationService } from '../../../../core/services/error-notification';
import { AppIcon } from '../../../../shared/components/app-icon/app-icon';
import { ScrollReveal } from '../../../../shared/directives/scroll-reveal';

interface QueueEntry {
  readonly id: string;
  readonly initials: string;
  readonly patient: string;
  readonly type: string;
  readonly preference: string;
  readonly waiting: string;
  readonly priority: 'Urgente' | 'Prioridad' | 'Normal';
  readonly tone: 'danger' | 'warning' | 'info';
  readonly status: 'En espera' | 'Oferta enviada' | 'Aceptada';
}

@Component({
  selector: 'app-waitlist',
  imports: [MatButtonModule, AppIcon, ScrollReveal],
  templateUrl: './waitlist.html',
  styleUrl: './waitlist.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class Waitlist {
  protected readonly entries = signal<readonly QueueEntry[]>([
    { id: 'w1', initials: 'IR', patient: 'Irene R.', type: 'Consulta de seguimiento', preference: 'Mañanas · cualquier profesional', waiting: '12 días', priority: 'Urgente', tone: 'danger', status: 'En espera' },
    { id: 'w2', initials: 'PG', patient: 'Pablo G.', type: 'Primera valoración', preference: 'Lun o mié · tarde', waiting: '9 días', priority: 'Prioridad', tone: 'warning', status: 'En espera' },
    { id: 'w3', initials: 'SA', patient: 'Sara A.', type: 'Revisión de tratamiento', preference: 'Cualquier día antes de 12:00', waiting: '6 días', priority: 'Normal', tone: 'info', status: 'Oferta enviada' },
    { id: 'w4', initials: 'JM', patient: 'Jorge M.', type: 'Consulta de seguimiento', preference: 'Videoconsulta · tardes', waiting: '4 días', priority: 'Normal', tone: 'info', status: 'En espera' },
    { id: 'w5', initials: 'NT', patient: 'Noa T.', type: 'Resultados', preference: 'Cualquier hueco', waiting: '2 días', priority: 'Prioridad', tone: 'warning', status: 'En espera' },
  ]);
  protected readonly selectedId = signal('w1');
  private readonly notifications = inject(ErrorNotificationService);

  protected offer(entry: QueueEntry): void {
    this.entries.update((items) =>
      items.map((item) => (item.id === entry.id ? { ...item, status: 'Oferta enviada' } : item)),
    );
    this.notifications.success('Hueco reservado temporalmente y oferta enviada.');
  }

  protected selected(): QueueEntry | undefined {
    return this.entries().find((entry) => entry.id === this.selectedId());
  }
}
