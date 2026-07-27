import { HttpErrorResponse } from '@angular/common/http';

import { ApiProblem } from '../models/app.models';

const genericMessage = 'No hemos podido completar la operación. Tus datos permanecen protegidos.';

const messagesByStatus: Readonly<Record<number, string>> = {
  0: 'No se ha podido contactar con el servicio. Comprueba la conexión e inténtalo de nuevo.',
  400: 'Algunos datos no son válidos. Revisa los campos indicados.',
  401: 'Tu sesión no es válida o ha caducado. Vuelve a identificarte.',
  403: 'No tienes autorización para realizar esta operación.',
  404: 'El elemento solicitado ya no está disponible.',
  409: 'La información ha cambiado o el horario ya está ocupado. Actualiza la vista.',
  412: 'La operación requiere una verificación de seguridad adicional.',
  419: 'La sesión de seguridad ha caducado. Actualiza la página e inténtalo de nuevo.',
  422: 'No se puede procesar la información con los valores actuales.',
  429: 'Se han realizado demasiados intentos. Espera un momento antes de continuar.',
  500: genericMessage,
  502: 'Un servicio necesario no está disponible temporalmente.',
  503: 'El servicio está temporalmente en mantenimiento. Inténtalo en unos minutos.',
  504: 'La operación ha tardado demasiado y se ha detenido de forma segura.',
};

export class AppError extends Error {
  readonly status: number;
  readonly code: string;
  readonly correlationId?: string;
  readonly retryable: boolean;
  readonly type?: string;
  readonly selectionUri?: string;
  readonly fieldErrors?: Readonly<Record<string, readonly string[]>>;

  constructor(options: {
    message: string;
    status?: number;
    code?: string;
    correlationId?: string;
    retryable?: boolean;
    type?: string;
    selectionUri?: string;
    fieldErrors?: Readonly<Record<string, readonly string[]>>;
  }) {
    super(options.message);
    this.name = 'AppError';
    this.status = options.status ?? 500;
    this.code = options.code ?? 'UNEXPECTED_ERROR';
    this.correlationId = options.correlationId;
    this.retryable = options.retryable ?? (this.status === 0 || this.status >= 500);
    this.type = options.type;
    this.selectionUri = options.selectionUri;
    this.fieldErrors = options.fieldErrors;
  }
}

export function toAppError(error: unknown): AppError {
  if (error instanceof AppError) {
    return error;
  }

  if (error instanceof HttpErrorResponse) {
    const problem = isApiProblem(error.error) ? error.error : undefined;
    const traceHeader = error.headers?.get('x-trace-id') ?? undefined;
    const status = Number.isFinite(error.status) ? error.status : 0;
    return new AppError({
      message: messagesByStatus[status] ?? genericMessage,
      status,
      code: problem?.code ?? `HTTP_${status || 'NETWORK'}`,
      correlationId: problem?.traceId ?? problem?.correlationId ?? traceHeader,
      retryable: status === 0 || status === 408 || status === 425 || status >= 500,
      type: problem?.type,
      selectionUri: problem?.selectionUri,
      fieldErrors: problem?.errors,
    });
  }

  return new AppError({ message: genericMessage });
}

function isApiProblem(value: unknown): value is ApiProblem {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
