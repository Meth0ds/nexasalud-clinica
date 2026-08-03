import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { MatCardModule } from '@angular/material/card';
import { MatChipsModule } from '@angular/material/chips';

import { AppIcon, AppIconName } from '../app-icon/app-icon';

export type SecurityBannerTone = 'verified' | 'warning' | 'info';

@Component({
  selector: 'app-security-banner',
  imports: [MatCardModule, MatChipsModule, AppIcon],
  templateUrl: './security-banner.html',
  styleUrl: './security-banner.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SecurityBanner {
  readonly title = input('Contexto protegido');
  readonly message = input('Las operaciones se validan en el servidor y quedan auditadas.');
  readonly label = input('Verificado');
  readonly tone = input<SecurityBannerTone>('verified');
  readonly icon = input<AppIconName>('shield');
}
