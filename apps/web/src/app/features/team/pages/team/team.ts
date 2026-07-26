import { ChangeDetectionStrategy, Component, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';

import { AppIcon } from '../../../../shared/components/app-icon/app-icon';
import { ScrollReveal } from '../../../../shared/directives/scroll-reveal';

@Component({ selector: 'app-team', imports: [MatButtonModule, AppIcon, ScrollReveal], templateUrl: './team.html', styleUrl: './team.scss', changeDetection: ChangeDetectionStrategy.OnPush })
export class Team {
  protected readonly tab = signal<'people' | 'shifts' | 'permissions'>('people');
}
