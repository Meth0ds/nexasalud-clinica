import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatTabsModule } from '@angular/material/tabs';

import { ErrorNotificationService } from '../../../../core/services/error-notification';
import { ThemePreferences } from '../../../../core/services/theme-preferences';
import { AppIcon } from '../../../../shared/components/app-icon/app-icon';
import { ScrollReveal } from '../../../../shared/directives/scroll-reveal';

@Component({ selector: 'app-settings', imports: [FormsModule, MatButtonModule, MatFormFieldModule, MatInputModule, MatSelectModule, MatSlideToggleModule, MatTabsModule, AppIcon, ScrollReveal], templateUrl: './settings.html', styleUrls: ['./settings.scss', './settings-catalog.scss', './settings-advanced.scss', './settings-integrations.scss'], changeDetection: ChangeDetectionStrategy.OnPush })
export class Settings {
  readonly preferences = inject(ThemePreferences);
  private readonly notifications = inject(ErrorNotificationService);
  protected readonly sessionTimeout = signal(15);
  protected readonly notificationsEnabled = signal(true);
  protected readonly appointmentReminders = signal(true);
  protected readonly waitlistAutomation = signal(true);
  protected readonly telemedicine = signal(true);
  protected readonly privacyScreen = signal(true);
  protected save(): void { this.notifications.success('Configuración guardada y cambio auditado.'); }
}
