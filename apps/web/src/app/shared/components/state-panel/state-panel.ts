import { ChangeDetectionStrategy, Component } from '@angular/core';

@Component({
  selector: 'app-state-panel',
  imports: [],
  templateUrl: './state-panel.html',
  styleUrl: './state-panel.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class StatePanel {}
