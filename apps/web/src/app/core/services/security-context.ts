import { Injectable, signal } from '@angular/core';

@Injectable({ providedIn: 'root' })
export class SecurityContextService {
  private readonly csrf = signal<string | null>(null);

  readonly csrfToken = this.csrf.asReadonly();

  setCsrfToken(token: string): void {
    this.csrf.set(token);
  }

  clear(): void {
    this.csrf.set(null);
  }
}
