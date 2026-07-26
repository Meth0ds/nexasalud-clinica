import { Directive, ElementRef, afterNextRender, inject, input } from '@angular/core';

@Directive({ selector: '[appScrollReveal]' })
export class ScrollReveal {
  readonly revealDelay = input(0);
  private readonly element = inject<ElementRef<HTMLElement>>(ElementRef);

  constructor() {
    afterNextRender(() => {
      const node = this.element.nativeElement;
      node.style.setProperty('--reveal-delay', `${Math.max(0, this.revealDelay())}ms`);

      if (globalThis.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
        node.classList.add('is-revealed');
        return;
      }

      const observer = new IntersectionObserver(
        (entries) => {
          if (entries.some((entry) => entry.isIntersecting)) {
            node.classList.add('is-revealed');
            observer.disconnect();
          }
        },
        { rootMargin: '0px 0px -7% 0px', threshold: 0.08 },
      );
      observer.observe(node);
    });
  }
}
