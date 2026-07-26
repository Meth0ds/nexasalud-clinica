import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

const paths = {
  home: 'M3 10.8 12 3l9 7.8V21h-6v-6H9v6H3V10.8Z',
  calendar: 'M6 2v3m12-3v3M4 8h16M5 4h14a1 1 0 0 1 1 1v15H4V5a1 1 0 0 1 1-1Zm3 8h3v3H8v-3Z',
  patients: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m7-10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm13 10v-2a4 4 0 0 0-3-3.87m-2-10a4 4 0 0 1 0 7.75',
  waitlist: 'M9 11h6m-6 4h4M7 3h10a2 2 0 0 1 2 2v16l-7-3-7 3V5a2 2 0 0 1 2-2Z',
  team: 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2m8-10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm8 10v-2a4 4 0 0 0-3-3.87m-1-9.13a4 4 0 0 1 0 7.75',
  chart: 'M4 19V9m6 10V5m6 14v-7m5 7H2',
  shield: 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Zm-3.5-10 2.2 2.2L15.8 9',
  settings: 'M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm7.4-3.5a7 7 0 0 0-.12-1.28l2.08-1.62-2-3.46-2.58 1.04a7 7 0 0 0-2.2-1.28L14.2 2h-4l-.38 3.4a7 7 0 0 0-2.2 1.28L5.04 5.64l-2 3.46 2.08 1.62a7 7 0 0 0 0 2.56L3.04 14.9l2 3.46 2.58-1.04a7 7 0 0 0 2.2 1.28l.38 3.4h4l.38-3.4a7 7 0 0 0 2.2-1.28l2.58 1.04 2-3.46-2.08-1.62A7 7 0 0 0 19.4 12Z',
  search: 'm21 21-4.35-4.35m2.35-5.65a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z',
  plus: 'M12 5v14M5 12h14',
  chevron: 'm9 18 6-6-6-6',
  arrow: 'M5 12h14m-5-5 5 5-5 5',
  bell: 'M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9Zm-8 13h4',
  lock: 'M6 10h12v11H6V10Zm3 0V7a3 3 0 0 1 6 0v3m-3 4v3',
  menu: 'M4 6h16M4 12h16M4 18h16',
  close: 'm6 6 12 12M18 6 6 18',
  clock: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Zm0-15v5l3.5 2',
  video: 'M15 10 21 7v10l-6-3v3H3V7h12v3Z',
  phone: 'M5 3h4l2 5-2.5 1.5a15 15 0 0 0 6 6L16 13l5 2v4c0 1.1-.9 2-2 2A16 16 0 0 1 3 5c0-1.1.9-2 2-2Z',
  location: 'M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Zm-8 3a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
  more: 'M5 10a2 2 0 1 0 0 4 2 2 0 0 0 0-4Zm7 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4Zm7 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z',
  check: 'm5 12 4 4L19 6',
  filter: 'M4 5h16l-6 7v6l-4 2v-8L4 5Z',
  download: 'M12 3v12m-5-5 5 5 5-5M5 21h14',
  spark: 'm12 2 1.6 5.4L19 9l-5.4 1.6L12 16l-1.6-5.4L5 9l5.4-1.6L12 2Zm7 13 .8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8L19 15Z',
  sun: 'M12 4V2m0 20v-2m8-8h2M2 12h2m13.66-5.66 1.42-1.42M4.92 19.08l1.42-1.42m0-11.32L4.92 4.92m14.16 14.16-1.42-1.42M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z',
  moon: 'M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z',
  eye: 'M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Zm10 3a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
  logout: 'M10 17l5-5-5-5m5 5H3m10-9h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6',
  help: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Zm-3-12a3 3 0 1 1 4.4 2.65C12.6 13.1 12 13.7 12 15m0 3h.01',
} as const;

export type AppIconName = keyof typeof paths;

@Component({
  selector: 'app-icon',
  templateUrl: './app-icon.html',
  styleUrl: './app-icon.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AppIcon {
  readonly name = input<AppIconName>('home');
  readonly size = input(20);
  readonly label = input<string | null>(null);
  protected readonly path = computed(() => paths[this.name()]);
}
