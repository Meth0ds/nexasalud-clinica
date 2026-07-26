import { ChangeDetectionStrategy, Component, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';

import { AppIcon } from '../../../../shared/components/app-icon/app-icon';
import { ScrollReveal } from '../../../../shared/directives/scroll-reveal';

@Component({ selector: 'app-audit', imports: [MatButtonModule, AppIcon, ScrollReveal], templateUrl: './audit.html', styleUrl: './audit.scss', changeDetection: ChangeDetectionStrategy.OnPush })
export class Audit { protected readonly privacyMode = signal(true); }
