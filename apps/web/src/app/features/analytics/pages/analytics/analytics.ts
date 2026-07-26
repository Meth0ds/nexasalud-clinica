import { ChangeDetectionStrategy, Component, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';

import { AppIcon } from '../../../../shared/components/app-icon/app-icon';
import { ScrollReveal } from '../../../../shared/directives/scroll-reveal';

@Component({ selector: 'app-analytics', imports: [MatButtonModule, AppIcon, ScrollReveal], templateUrl: './analytics.html', styleUrl: './analytics.scss', changeDetection: ChangeDetectionStrategy.OnPush })
export class Analytics { protected readonly period = signal<'30d' | '90d' | 'year'>('30d'); }
