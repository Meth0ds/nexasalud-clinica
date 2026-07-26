import { ComponentFixture, TestBed } from '@angular/core/testing';

import { Administration } from './administration';

describe('Administration', () => {
  let fixture: ComponentFixture<Administration>;
  let root: HTMLElement;

  beforeEach(async () => {
    await TestBed.configureTestingModule({ imports: [Administration] }).compileComponents();
    fixture = TestBed.createComponent(Administration);
    root = fixture.nativeElement as HTMLElement;
    await fixture.whenStable();
  });

  const findButton = (label: string): HTMLButtonElement => {
    const button = [...root.querySelectorAll('button')].find((item) => item.textContent?.includes(label));
    if (!(button instanceof HTMLButtonElement)) throw new Error(`No se encontró el botón: ${label}`);
    return button;
  };

  const click = async (button: HTMLButtonElement): Promise<void> => {
    button.click();
    fixture.detectChanges();
    await fixture.whenStable();
  };

  it('should create with synthetic data and users selected', () => {
    expect(fixture.componentInstance).toBeTruthy();
    expect(root.textContent).toContain('Entorno sintético');
    expect(root.querySelector('nav button[aria-current="page"]')?.textContent).toContain('Usuarios');
  });

  it('keeps patient references masked until context is explicitly requested', async () => {
    await click(findButton('Auditoría'));
    expect(root.querySelector('.audit-main code')?.textContent).toBe('P-••84');

    await click(findButton('Mostrar contexto'));
    expect(root.querySelector('.audit-main code')?.textContent).toBe('DEMO-P-0284');
    expect(findButton('Ocultar contexto').getAttribute('aria-pressed')).toBe('true');
  });

  it('filters concrete audit events by actor', async () => {
    await click(findButton('Auditoría'));
    const actor = root.querySelector<HTMLInputElement>('.audit-filters input');
    expect(actor).toBeTruthy();
    actor!.value = 'soporte.demo';
    actor!.dispatchEvent(new Event('input', { bubbles: true }));
    fixture.detectChanges();
    await fixture.whenStable();

    const events = root.querySelectorAll('.audit-stream article');
    expect(events).toHaveLength(1);
    expect(events[0].textContent).toContain('Intentó abrir una ficha clínica');
    expect(events[0].textContent).toContain('Denegado');
  });

  it('keeps permission edits in the local draft state', async () => {
    await click(findButton('Roles y permisos'));
    const toggle = root.querySelector<HTMLButtonElement>('.permission-toggle');
    expect(toggle?.getAttribute('aria-pressed')).toBe('true');

    await click(toggle!);
    expect(toggle?.getAttribute('aria-pressed')).toBe('false');
    expect(root.querySelector('[role="status"]')?.textContent).toContain('requiere aprobación');
  });
});
