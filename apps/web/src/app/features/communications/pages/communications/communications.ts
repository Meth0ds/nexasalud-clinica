import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';

import { ErrorNotificationService } from '../../../../core/services/error-notification';
import { AppIcon } from '../../../../shared/components/app-icon/app-icon';
import { ScrollReveal } from '../../../../shared/directives/scroll-reveal';

@Component({ selector: 'app-communications', imports: [FormsModule, MatButtonModule, AppIcon, ScrollReveal], templateUrl: './communications.html', styleUrl: './communications.scss', changeDetection: ChangeDetectionStrategy.OnPush })
export class Communications {
  protected readonly composeOpen = signal(false);
  protected readonly recipient = signal('');
  protected readonly message = signal('');
  protected readonly channel = signal<'SMS' | 'Email' | 'Portal'>('Portal');
  private readonly notifications = inject(ErrorNotificationService);
  protected send(): void { if (!this.recipient().trim() || !this.message().trim()) { return; } this.composeOpen.set(false); this.notifications.success('Mensaje enviado por el canal consentido y registrado.'); this.recipient.set(''); this.message.set(''); }
}
