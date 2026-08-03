import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';

import { AppIcon, AppIconName } from '../app-icon/app-icon';

export type StatePanelKind = 'loading' | 'error' | 'empty' | 'success';

@Component({
  selector: 'app-state-panel',
  imports: [MatButtonModule, MatCardModule, MatProgressSpinnerModule, AppIcon],
  templateUrl: './state-panel.html',
  styleUrl: './state-panel.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class StatePanel {
  readonly kind = input<StatePanelKind>('loading');
  readonly title = input('');
  readonly message = input('');
  readonly actionLabel = input<string | null>(null);
  readonly icon = input<AppIconName>('shield');
  readonly busy = input(false);
  readonly reference = input<string | null>(null);
  readonly action = output<void>();
}
