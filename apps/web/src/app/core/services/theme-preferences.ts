import { DOCUMENT } from '@angular/common';
import { Injectable, computed, inject, signal } from '@angular/core';

export type ThemeMode = 'system' | 'light' | 'dark';
export type DensityMode = 'comfortable' | 'compact';

@Injectable({ providedIn: 'root' })
export class ThemePreferences {
  private readonly document = inject(DOCUMENT);
  private readonly themeSignal = signal<ThemeMode>('system');
  private readonly densitySignal = signal<DensityMode>('comfortable');
  private readonly reduceMotionSignal = signal(false);

  readonly theme = this.themeSignal.asReadonly();
  readonly density = this.densitySignal.asReadonly();
  readonly reduceMotion = this.reduceMotionSignal.asReadonly();
  readonly effectiveTheme = computed<'light' | 'dark'>(() => {
    const selected = this.themeSignal();
    if (selected !== 'system') {
      return selected;
    }
    return this.document.defaultView?.matchMedia('(prefers-color-scheme: dark)').matches
      ? 'dark'
      : 'light';
  });

  constructor() {
    const storage = this.document.defaultView?.localStorage;
    const storedTheme = storage?.getItem('nexa.theme');
    const storedDensity = storage?.getItem('nexa.density');
    const storedMotion = storage?.getItem('nexa.reduceMotion');
    if (storedTheme === 'system' || storedTheme === 'light' || storedTheme === 'dark') {
      this.themeSignal.set(storedTheme);
    }
    if (storedDensity === 'comfortable' || storedDensity === 'compact') {
      this.densitySignal.set(storedDensity);
    }
    this.reduceMotionSignal.set(storedMotion === 'true');
    this.apply();

    this.document.defaultView
      ?.matchMedia('(prefers-color-scheme: dark)')
      .addEventListener('change', () => {
        if (this.themeSignal() === 'system') {
          this.apply();
        }
      });
  }

  setTheme(theme: ThemeMode): void {
    this.themeSignal.set(theme);
    this.document.defaultView?.localStorage.setItem('nexa.theme', theme);
    this.apply();
  }

  toggleTheme(): void {
    this.setTheme(this.effectiveTheme() === 'dark' ? 'light' : 'dark');
  }

  setDensity(density: DensityMode): void {
    this.densitySignal.set(density);
    this.document.defaultView?.localStorage.setItem('nexa.density', density);
    this.apply();
  }

  setReduceMotion(reduce: boolean): void {
    this.reduceMotionSignal.set(reduce);
    this.document.defaultView?.localStorage.setItem('nexa.reduceMotion', String(reduce));
    this.apply();
  }

  private apply(): void {
    const root = this.document.documentElement;
    root.dataset['theme'] = this.effectiveTheme();
    root.dataset['density'] = this.densitySignal();
    root.dataset['reduceMotion'] = String(this.reduceMotionSignal());
  }
}
