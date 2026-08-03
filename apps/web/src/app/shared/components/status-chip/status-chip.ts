import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { MatChipsModule } from '@angular/material/chips';

import { AppIcon, AppIconName } from '../app-icon/app-icon';

export type StatusChipTone = 'default' | 'success' | 'warning' | 'danger' | 'info';

@Component({
  selector: 'app-status-chip',
  imports: [MatChipsModule, AppIcon],
  templateUrl: './status-chip.html',
  styleUrl: './status-chip.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class StatusChip {
  readonly tone = input<StatusChipTone>('default');
  readonly icon = input<AppIconName | null>(null);
}
