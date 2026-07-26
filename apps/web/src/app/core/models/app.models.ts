export type AppointmentStatus =
  | 'pending'
  | 'confirmed'
  | 'waiting'
  | 'in-progress'
  | 'completed'
  | 'cancelled'
  | 'no-show';

export type AppointmentMode = 'in-person' | 'video' | 'phone';

export interface SessionUser {
  readonly id: string;
  readonly displayName: string;
  readonly emailHint: string;
  readonly initials: string;
  readonly professionalTitle: string;
}

export interface SessionTenant {
  readonly id: string;
  readonly name: string;
  readonly clinicId: string;
  readonly clinicName: string;
}

export interface SessionData {
  readonly authenticated: true;
  readonly user: SessionUser;
  readonly tenant: SessionTenant;
  readonly permissions: readonly string[];
  readonly idleExpiresAt: string;
  readonly absoluteExpiresAt: string;
  readonly authenticatedAt: string;
  readonly assuranceLevel: 'mfa' | 'passkey' | 'password';
}

export interface TenantContextOption {
  readonly membershipId: string;
  readonly tenant: { readonly id: string; readonly name: string };
  readonly clinics: readonly { readonly id: string; readonly name: string }[];
}

export interface TenantContextOptionsResponse {
  readonly items: readonly TenantContextOption[];
  readonly selected: { readonly membershipId: string | null; readonly clinicId: string | null };
}

export interface Appointment {
  readonly id: string;
  readonly version: number;
  readonly patientId: string;
  readonly patientDisplayName: string;
  readonly patientInitials: string;
  readonly patientAge?: number;
  readonly practitionerId: string;
  readonly practitionerName: string;
  readonly roomId?: string;
  readonly roomName?: string;
  readonly startsAt: string;
  readonly endsAt: string;
  readonly timezone: string;
  readonly typeName: string;
  readonly appointmentTypeId: string;
  readonly mode: AppointmentMode;
  readonly status: AppointmentStatus;
  readonly operationalLabel?: string;
  readonly reasonSummary?: string;
  readonly coverageLabel?: string;
  readonly lastVisitLabel?: string;
}

export interface AppointmentListResponse {
  readonly items: readonly Appointment[];
  readonly total: number;
  readonly timezone: string;
  readonly range: {
    readonly from: string;
    readonly to: string;
  };
}

export interface PaginatedApiResponse<T> {
  readonly data: readonly T[];
  readonly meta: {
    readonly current_page: number;
    readonly last_page: number;
    readonly per_page: number;
    readonly total: number;
  };
}

export interface CreateAppointmentInput {
  readonly clinicId: string;
  readonly patientId: string;
  readonly practitionerId: string;
  readonly roomId?: string;
  readonly startsAt: string;
  readonly endsAt: string;
  readonly timezone: string;
  readonly appointmentTypeId: string;
  readonly mode: AppointmentMode;
  readonly reason?: string;
}

export interface Patient {
  readonly id: string;
  readonly displayName: string;
  readonly initials: string;
  readonly ageRange: string;
  readonly phoneHint: string;
  readonly emailHint: string;
  readonly coverageLabel: string;
  readonly lastVisitLabel: string;
  readonly nextAppointmentLabel?: string;
  readonly consentStatus: 'current' | 'review' | 'missing';
  readonly assignedPractitioner: string;
  readonly alertLabels: readonly string[];
}

export interface PatientListResponse {
  readonly items: readonly Patient[];
  readonly total: number;
}

export interface ReferenceData {
  readonly clinics: readonly {
    readonly id: string;
    readonly code: string;
    readonly name: string;
    readonly timezone: string;
  }[];
  readonly rooms: readonly {
    readonly id: string;
    readonly clinicId: string;
    readonly name: string;
    readonly capacity: number;
  }[];
  readonly appointmentTypes: readonly {
    readonly id: string;
    readonly code: string;
    readonly name: string;
    readonly durationMinutes: number;
    readonly color?: string;
    readonly requiresRoom: boolean;
    readonly allowsVideo: boolean;
  }[];
  readonly practitioners: readonly {
    readonly id: string;
    readonly displayName: string;
    readonly roles: readonly string[];
  }[];
  readonly insurers: readonly { readonly id: string; readonly code: string; readonly name: string }[];
}

export interface WaitlistEntry {
  readonly id: string;
  readonly patientDisplayName: string;
  readonly patientInitials: string;
  readonly requestedType: string;
  readonly preferredWindow: string;
  readonly priority: 'normal' | 'priority' | 'urgent';
  readonly status: 'waiting' | 'offered' | 'accepted' | 'expired';
  readonly waitingSince: string;
}

export interface AuditEvent {
  readonly id: string;
  readonly occurredAt: string;
  readonly actorDisplayName: string;
  readonly actionLabel: string;
  readonly resourceLabel: string;
  readonly outcome: 'allowed' | 'blocked' | 'failed';
  readonly correlationId: string;
}

export interface ApiProblem {
  readonly type?: string;
  readonly title?: string;
  readonly status?: number;
  readonly detail?: string;
  readonly instance?: string;
  readonly code?: string;
  readonly correlationId?: string;
  readonly traceId?: string;
  readonly errors?: Readonly<Record<string, readonly string[]>>;
  readonly selection?: 'tenant' | 'clinic';
  readonly selectionUri?: string;
}

export interface PageErrorState {
  readonly title: string;
  readonly message: string;
  readonly correlationId?: string;
  readonly retryable: boolean;
}
