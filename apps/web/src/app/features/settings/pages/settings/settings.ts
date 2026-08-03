import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatCardModule } from '@angular/material/card';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatSelectModule } from '@angular/material/select';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';

import { AppError, toAppError } from '../../../../core/errors/app-error';
import { ErrorNotificationService } from '../../../../core/services/error-notification';
import {
  DensityMode,
  ThemeMode,
  ThemePreferences,
} from '../../../../core/services/theme-preferences';
import { AppIcon } from '../../../../shared/components/app-icon/app-icon';
import { StatePanel } from '../../../../shared/components/state-panel/state-panel';
import { ScrollReveal } from '../../../../shared/directives/scroll-reveal';
import { SettingsApiService, WorkspacePreferences } from '../../data-access/settings-api';

@Component({
  selector: 'app-settings',
  imports: [
    MatButtonModule,
    MatButtonToggleModule,
    MatCardModule,
    MatProgressSpinnerModule,
    MatSelectModule,
    MatSlideToggleModule,
    AppIcon,
    StatePanel,
    ScrollReveal,
  ],
  templateUrl: './settings.html',
  styleUrl: './settings.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class Settings implements OnInit {
  readonly preferences = inject(ThemePreferences);
  private readonly api = inject(SettingsApiService);
  private readonly notifications = inject(ErrorNotificationService);
  protected readonly loading = signal(true);
  protected readonly saving = signal(false);
  protected readonly error = signal<AppError | null>(null);
  protected readonly saveError = signal<AppError | null>(null);
  protected readonly agendaView = signal<'day' | 'week' | 'month'>('day');
  protected readonly autoMask = signal(true);
  protected readonly desktopNotifications = signal(false);
  ngOnInit(): void {
    this.load();
  }
  protected setTheme(mode: ThemeMode): void {
    this.preferences.setTheme(mode);
  }
  protected setDensity(density: DensityMode): void {
    this.preferences.setDensity(density);
  }
  protected save(): void {
    this.saving.set(true);
    this.saveError.set(null);
    this.api.save(this.payload()).subscribe({
      next: () => {
        this.saving.set(false);
        this.notifications.success('Preferencias guardadas y auditadas.');
      },
      error: (error: unknown) => {
        this.saving.set(false);
        this.saveError.set(toAppError(error, 'No se han podido guardar tus preferencias.'));
      },
    });
  }
  protected retry(): void {
    this.load();
  }
  private load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.load().subscribe({
      next: ({ preferences }) => {
        this.preferences.setTheme(preferences.theme.mode);
        this.preferences.setDensity(preferences.theme.density);
        this.preferences.setReduceMotion(preferences.theme.reduceMotion);
        this.agendaView.set(preferences.agenda.defaultView);
        this.autoMask.set(preferences.privacy.autoMask);
        this.desktopNotifications.set(preferences.notifications.desktop);
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.error.set(toAppError(error, 'No se han podido cargar tus preferencias.'));
        this.loading.set(false);
      },
    });
  }
  private payload(): WorkspacePreferences {
    return {
      theme: {
        mode: this.preferences.theme(),
        density: this.preferences.density(),
        reduceMotion: this.preferences.reduceMotion(),
      },
      agenda: { defaultView: this.agendaView() },
      privacy: { autoMask: this.autoMask() },
      notifications: { desktop: this.desktopNotifications() },
    };
  }
}
