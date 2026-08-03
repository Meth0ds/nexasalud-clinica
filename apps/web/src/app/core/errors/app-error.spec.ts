import { HttpErrorResponse } from '@angular/common/http';

import { toAppError } from './app-error';

describe('toAppError', () => {
  it('preserves safe transport metadata while allowing a contextual UI message', () => {
    const error = toAppError(
      new HttpErrorResponse({
        status: 503,
      }),
      'La agenda no está disponible en este momento.',
    );

    expect(error.message).toBe('La agenda no está disponible en este momento.');
    expect(error.status).toBe(503);
    expect(error.retryable).toBe(true);
  });
});
