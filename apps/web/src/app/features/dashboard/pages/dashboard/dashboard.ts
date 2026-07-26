import { ChangeDetectionStrategy, Component, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { RouterLink } from '@angular/router';

import { AppIcon, AppIconName } from '../../../../shared/components/app-icon/app-icon';
import { ScrollReveal } from '../../../../shared/directives/scroll-reveal';

interface Metric {
  readonly label: string;
  readonly value: string;
  readonly note: string;
  readonly trend: string;
  readonly icon: AppIconName;
  readonly tone: 'green' | 'coral' | 'blue' | 'sand';
}

interface TodayAppointment {
  readonly id: string;
  readonly time: string;
  readonly duration: string;
  readonly initials: string;
  readonly patient: string;
  readonly type: string;
  readonly place: string;
  readonly status: 'En curso' | 'Confirmada' | 'Por confirmar' | 'Completada';
  readonly statusTone: string;
  readonly flags: readonly string[];
}

@Component({
  selector: 'app-dashboard',
  imports: [MatButtonModule, RouterLink, AppIcon, ScrollReveal],
  templateUrl: './dashboard.html',
  styleUrl: './dashboard.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class Dashboard {
  protected readonly selectedAppointment = signal<string | null>('apt-2');

  protected readonly metrics: readonly Metric[] = [
    { label: 'Citas de hoy', value: '24', note: '19 confirmadas', trend: '+8%', icon: 'calendar', tone: 'green' },
    { label: 'En espera', value: '08', note: '2 de prioridad', trend: '−3', icon: 'waitlist', tone: 'coral' },
    { label: 'Ocupación', value: '86%', note: 'Salas y profesionales', trend: '+4%', icon: 'chart', tone: 'blue' },
    { label: 'Tiempo medio', value: '18 min', note: 'Espera en recepción', trend: '−6 min', icon: 'clock', tone: 'sand' },
  ];

  protected readonly appointments: readonly TodayAppointment[] = [
    { id: 'apt-1', time: '08:30', duration: '30 min', initials: 'EV', patient: 'Elena V.', type: 'Revisión de tratamiento', place: 'Sala Encina', status: 'Completada', statusTone: 'success', flags: [] },
    { id: 'apt-2', time: '09:15', duration: '45 min', initials: 'LM', patient: 'Lucas M.', type: 'Consulta de seguimiento', place: 'Sala Olivo', status: 'En curso', statusTone: 'info', flags: ['Alergia registrada'] },
    { id: 'apt-3', time: '10:15', duration: '30 min', initials: 'AR', patient: 'Ana R.', type: 'Primera valoración', place: 'Videoconsulta', status: 'Confirmada', statusTone: 'success', flags: ['Documentos pendientes'] },
    { id: 'apt-4', time: '11:00', duration: '20 min', initials: 'DG', patient: 'David G.', type: 'Resultados y seguimiento', place: 'Sala Encina', status: 'Por confirmar', statusTone: 'warning', flags: [] },
    { id: 'apt-5', time: '11:30', duration: '30 min', initials: 'CF', patient: 'Clara F.', type: 'Consulta programada', place: 'Sala Olivo', status: 'Confirmada', statusTone: 'success', flags: [] },
  ];

  protected selectAppointment(id: string): void {
    this.selectedAppointment.set(this.selectedAppointment() === id ? null : id);
  }
}
