import { ChangeDetectionStrategy, Component } from '@angular/core';

@Component({
  selector: 'app-security-banner',
  imports: [],
  templateUrl: './security-banner.html',
  styleUrl: './security-banner.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SecurityBanner {}
